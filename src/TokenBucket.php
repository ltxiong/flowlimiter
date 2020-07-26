<?php
namespace Ltxiong\FlowLimiter;

/**
 * 
 * 令牌桶算法实现的接口限流器
 * 实现原理：定时往令牌桶当中添加固定数量的数据，业务从令牌桶获取数据，取到数据就进行业务处理，获取不到数据业务就不处理
 * 可以通过redis incrBy decr 来实现，也可以通过redis队列来实现
 * 1、如通过redis incrBy decr来实现，每次添加时通过 incrBy往令牌桶添加数据，获取数据时，每次通过decr减少数据，直到decr减少到0为止
 * 2、通过redis队列来实现，每次往队列添加固定个数的数据，获取数据时，每次通过pop减少队列数据，直到pop不出数据为止
 * 
 * 使 用 示 例：
 * $redis = new Redis();
 * $redis->connect('ltx_centos78_01', 6379);
 * // 实例化参数
 * $tkb = new TokenBucket($redis, 'tkb:test', 30);
 * // 重置令牌桶
 * $tkb->resetTokenBucket();
 * // 添加令牌到令牌桶
 * $tkb->addTokenToBucket();
 * for($i = 0; $i < 100; $i++)
 * {
 *     // 从10到30毫秒随机产生一个数 
 *     usleep(random_int(10, 30) * 1000);
 *     $rs = $tkb->getToken();
 *     echo "$i " . 'has_token ' . intval($rs['has_token']) . '--' . time() . "</br>";
 * }
 * 
 */
class TokenBucket
{

    /**
     * redis_conn 实例
     *
     * @var string
     */
    private $_rds_conn = null;

    /**
     * 令牌桶里面已有的数据总量的缓存key
     *
     * @var string
     */
    private $_tkb_token_key = "flowlimit:tkbucket:token";

    /**
     * bucket max size in calls--令牌桶容量(令牌桶默认最大可容纳N个)，默认值：100
     *
     * @var integer
     */
    private $burst = 100;

    /**
     * 令牌桶数据缓存超时时间
     * 
     * @var integer
     */
    private $_tkb_cache_timeout = 60;
    
    /**
     * 构造函数
     *
     * @param redis:connect $redis_conn redis连接实例
     * @param string $bucket_cache_suffix    令牌桶算法缓存key后缀，为避免不同业务使用key冲突，强制必须设置值
     * @param integer $burst    令牌桶容量(令牌桶默认最大可容纳N个)，非必需参数，如不传将使用默认值
     */
    public function __construct($redis_conn, $bucket_cache_suffix, $burst = 0)
    {
        $this->_rds_conn = $redis_conn;
        $burst = intval($burst);
        $bucket_cache_suffix = (string)($bucket_cache_suffix);
        $this->_tkb_token_key .= ":$bucket_cache_suffix";        
        if($burst > 0)
        {
            $this->burst = $burst;
        }
    }

    /**
     * 重置令牌桶，清空令牌桶，清空后，令牌桶数据为0
     * 
     * @return array 处理结果，返回的 参数列表如下所示：
     *   $reset_rs['ok']  type:bool 重置令牌桶成功与否，true:是，false:否
     *   $reset_rs['error_msg']  type:string 失败时，错误消息
     * 
     */
    public function resetTokenBucket()
    {
        $reset_rs = array(
            'ok' => false,
            'error_msg' => ''
        );
        try
        {
            $reset_rs['ok'] = $this->_rds_conn->set($this->_tkb_token_key, 0, $this->_tkb_cache_timeout);
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查失败问题
            $reset_rs['error_msg'] = $e->getMessage();
        }
        return $reset_rs;
    }

    /**
     * 添加令牌(通过定时任务添加，例如：每1秒添加1次数据)
     * 
     * @param type:integer $number 需要添加的令牌数量
     * 
     * @return array 处理结果，返回的 参数列表如下所示：
     *   $add_rs['ok']  type:bool 添加成功与否，true:是，false:否
     *   $add_rs['error_msg']  type:string 失败时，错误消息
     * 
     */
    public function addTokenToBucket($number = 0)
    {
        $add_rs = array(
            'ok' => false,
            'error_msg' => ''
        );
        if($number == 0)
        {
            $number = $this->burst;
        }
        try
        {
            // 有可能key过期，无法获取到数据
            $current_number = $this->_rds_conn->get($this->_tkb_token_key);
            $current_number = intval($current_number);
            if($current_number < 0)
            {
                // 当current_number为负数时，需要进行数据矫正
                $this->resetTokenBucket();
                $current_number = 0;
            }
            $number = $this->burst >= ($current_number + $number) ? $number : ($this->burst - $current_number);
            if ($number > 0)
            {
                $pipe = $this->_rds_conn->multi(Redis::PIPELINE);
                $this->_rds_conn->incrBy($this->_tkb_token_key, $number);
                $this->_rds_conn->expire($this->_tkb_token_key, $this->_tkb_cache_timeout);
                $pipe->exec();
                $add_rs['ok'] = true;
            }
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查失败问题
            $add_rs['error_msg'] = $e->getMessage();
        }
        return $add_rs;
    }

    /**
     * 从令牌桶当中获取数据，是否还有令牌数据(只负责取数据，能够取到数据说明桶里有令牌，否则无令牌) 
     * 
     * @return array 处理结果，返回的 参数列表如下所示：
     *   $token_rs['has_token']  type:bool 是否有令牌，true:业务可继续处理，false:业务不可继续处理
     *   $token_rs['error_msg']  type:string 失败时，错误消息
     * 
     */
    public function getToken()
    {
        $token_rs = array(
            'has_token' => false,
            'error_msg' => ''
        );
        try
        {
            $pipe = $this->_rds_conn->multi(Redis::PIPELINE);
            // 如key不存在，调用decr会默认将key的值设置为0，然后再进行decr，所以当key不存在时，直接返回的结果为-1
            $this->_rds_conn->decr($this->_tkb_token_key);
            $this->_rds_conn->expire($this->_tkb_token_key, $this->_tkb_cache_timeout);
            $pipe_rs = $pipe->exec();
            $token_value = $pipe_rs[0];
            // 当token_value<0，说明桶当中已无可用令牌，也就是说，此时将不可再继续操作
            $token_rs['has_token'] = $token_value >= 0;
            if($token_value < 0)
            {
                // 当token_value为负数时，需要进行数据矫正
                $this->resetTokenBucket();
            }
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查失败问题
            $token_rs['error_msg'] = $e->getMessage();
        }
        return $token_rs;
    }

}
