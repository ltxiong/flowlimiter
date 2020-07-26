<?php
namespace Ltxiong\FlowLimiter;

/**
 * 
 * 基于Redis实现的计数器算法 业务限流器
 * 实现原理：将时间片切分成足够细的粒度，通过时间窗口流动的方式来动态实现最近的N时间段内可容纳的流量
 * 
 * 使 用 示 例：
 * $redis = new Redis();
 * $redis->connect('ltx_centos78_01', 6379);
 * $n = 1000;
 * $throttle = new MyThrottle($redis);
 * for($i = 0; $i < 20; $i++)
 * {
 *     // 从100到300毫秒随机产生一个数 
 *     usleep(random_int(50, 400) * $n);
 *     $rs = $throttle->actionAllowed('read_topic:10089', 2, 4);
 *     echo 'is_allowed ' . intval($rs['is_allowed']) . ' current_num ' . $rs['current_num'] . ' time-->' . time() . "</br>";
 * }
 * 
 */
class MyThrottle
{

    /**
     * redis_conn 实例
     *
     * @var string
     */
    private $_rds_conn = null;

    /**
     * 过滤器缓存key参数前缀
     *
     * @var string
     */
    private $_throttling_key_prefix = "my:throttle:2020:";

    /**
     * 用于处理时间间隔针对秒为单位的倍数
     *
     * @var integer
     */
    private $_ts_times = 1000;

    /**
     * 过期时间加权值--也就是延迟xx秒后才过期
     *
     * @var integer
     */
    private $_expire_weight_num = 1;

    /**
     * 2020-01-01 00:00:00 时间的毫秒数，为了在zAdd存储数据时，score值尽量小而设置的调节值
     *
     * @param int score调节值
     */
    private static $_score_adjust = 1577808000000;

    /**
     * 2020-01-01 00:00:00 时间的毫秒数，为了在zAdd存储数据时，value值尽量小而设置的调节值
     *
     * @param int value调节值
     */
    private static $_value_adjust = 1577808000000;

    /**
     * 构造函数，
     *
     * @param string $redis_conn redis实例
     */
    public function __construct($redis_conn)
    {
        $this->_rds_conn = $redis_conn;
    }

    /**
     * 检测相应的操作类型在有效时间断内是否允许操作
     * 返回的结果，只有 is_allowedo 为 true时，才可操作，current_num error_msg 返回值可在业务当中根据实际情况去使用
     *
     * @param string $unique_action  唯一的操作类型字符串，可以采用业务+操作唯一ID，例如：topic_read:1287989
     * @param integer $period_effective  有效周期时间段，整数，单位：秒，例如：30，代表 30秒内
     * @param integer $period_effective_max_num  有效周期时间段内允许操作的最大次数，单位：次，例如：30，代表period_effective秒内最多可允许操作30次
     * 
     * @return array 加锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $rs_data['is_allowed']  type:bool  当前是否允许操作   加锁成功与否，true:成功，false:失败
     *   $rs_data['current_num']  type:int  当前有效周期内已操作的总次数(本周期内已操作的总次数)
     *   $rs_data['error_msg']  type:string  具体的错误消息 
     */
    public function actionAllowed(string $unique_action, int $period_effective, int $period_effective_max_num)
    {
        $rs_data = array(
            'is_allowed' => false,
            'current_num' => 0,
            'error_msg' => ''
        );
        if(empty($this->_rds_conn))
        {
            $rs_data['error_msg'] = '10030:invalid redis conn';
            return $rs_data;
        }
        // 业务类型缓存key 
        $action_cache_key = $this->_throttling_key_prefix . $unique_action;
        // 毫秒时间戳*1000
        $now_ts = intval(microtime(true) * $this->_ts_times);
        $value_will_set = $now_ts;
        $now_ts = $now_ts - self::$_score_adjust;
        // 当前时间往前查看的N区间
        $forward_score_period = $now_ts - $period_effective * $this->_ts_times;
        try
        {
            // 获取窗口内的行为数量，如果窗口内数量已达到或者超过最大值，返回False，先不允许进行后续操作
            $current_period_effective_num = $this->_rds_conn->zCount($action_cache_key, $forward_score_period, $now_ts);
            if($current_period_effective_num >= $period_effective_max_num)
            {
                // 移除时间窗口之前的行为记录，剩下的都是时间窗口内的 
                $this->_rds_conn->zRemRangeByScore($action_cache_key, 0, $forward_score_period);
                $rs_data['current_num'] = $current_period_effective_num;
                return $rs_data;
            }
            // 记录行为, mapping 当中，value 和 score 都使用毫秒时间戳 
            // 以下2种写法都可以 
            // $multi_rs = $this->_rds_conn->multi(Redis::PIPELINE)
            //     ->zAdd($action_cache_key, $now_ts, $now_ts)
            //     ->zCount($action_cache_key, $forward_score_period, $now_ts)
            //     ->ttl($action_cache_key)
            //     ->exec();
            $pipe = $this->_rds_conn->multi(Redis::PIPELINE);
            // key score value
            $current_score = $now_ts;
            $current_value = $value_will_set - self::$_value_adjust;
            $pipe->zAdd($action_cache_key, $current_score, $current_value);
            $pipe->zCount($action_cache_key, $forward_score_period, $now_ts);
            $pipe->ttl($action_cache_key);
            // 批量执行并按照添加的顺序返回操作结果
            $multi_rs = $pipe->exec();

            $current_num = intval($multi_rs[1]);
            $ttl_num = intval($multi_rs[2]);
            // 设置 zset 过期时间，避免冷用户持续占用内存，过期时间应该等于时间窗口的长度，再多宽限 1s 
            // 对于过期时间距离 时间窗口很大的，没必要再延长过期时间 
            if($ttl_num <= $period_effective)
            {
                // ttl:The time to live in seconds. If the key has no ttl, -1 will be returned, and -2 if the key doesn't exist.
                if($ttl_num < 0)
                {
                    $ttl_num = 0;
                }
                $this->_rds_conn->expire($action_cache_key, ($ttl_num + $period_effective + $this->_expire_weight_num));
            }
            // 比较数量是否超标 
            $rs_data['is_allowed'] = $current_num <= $period_effective_max_num;
            $rs_data['current_num'] = $current_num;
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查加锁失败问题
            $rs_data['error_msg'] = $e->getMessage();
        }
        return $rs_data;
    }

}
