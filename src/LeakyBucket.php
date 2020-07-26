<?php
namespace Ltxiong\FlowLimiter;

/**
 * 
 * 漏桶算法实现的接口限流器(控制粒度到秒级别，也就是控制每秒流量)
 * 数据被填充到桶中,并以固定速率注入网络中,而不管数据流的突发性，如桶是空的,不做任何事情
 * 主机在每一个时间片向网络注入一个数据包,因此产生一致的数据流
 * 
 * 使 用 示 例：
 * $redis = new Redis();
 * $redis->connect('ltx_centos78_01', 6379);
 * $lkb = new LeakyBucket($redis, "test", 10, 30);
 * for($i = 0; $i < 100; $i++)
 * {
 *     // 从100到300毫秒随机产生一个数 
 *     usleep(random_int(10, 30) * 1000);
 *     $rs = $lkb->permissionGranted();
 *     echo "$i " . 'is_allowed ' . intval($rs['is_allowed']) . '--' . time() . "</br>";
 * }
 * 
 */

class LeakyBucket
{

    /**
     * redis_conn 实例
     *
     * @var string
     */
    private $_rds_conn = null;

    /**
     * 刷新时间缓存key
     *
     * @var string
     */
    private $_refresh_time_key = "flowlimit:lkbucket:refresh:lasttime";

    /**
     * 上次刷新时，漏桶里面已有的数据总量的缓存key
     *
     * @var string
     */
    private $_lkb_water_has_count_key = "flowlimit:lkbucket:water:has:count";

    /**
     * leak rate in calls/s--每秒漏桶流出的速率，单位：N个/s，默认值：20
     *
     * @var integer
     */
    private $rate = 20;

    /**
     * bucket size in calls--漏桶容量(漏桶默认最大可容纳N个)，默认值：10
     *
     * @var integer
     */
    private $burst = 10;

    /**
     * 漏桶数据缓存超时时间
     *
     * @var integer
     */
    private $_leaky_bucket_timeout = 6;

    /**
     * 构造函数
     *
     * @param redis:connect $redis_conn redis连接实例
     * @param string $bucket_cache_suffix    漏桶算法缓存key后缀，为避免不同业务使用key冲突，强制必须设置值
     * @param integer $rate    每秒漏桶流出的速率，单位：N个/s，非必需参数，如不传将使用默认值
     * @param integer $burst    漏桶容量(漏桶默认最大可容纳N个)，非必需参数，如不传将使用默认值
     */
    public function __construct($redis_conn, $bucket_cache_suffix, $rate = 0, $burst = 0)
    {
        $this->_rds_conn = $redis_conn;
        $rate = intval($rate);
        $burst = intval($burst);
        $bucket_cache_suffix = (string)($bucket_cache_suffix);
        $this->_refresh_time_key .= ":$bucket_cache_suffix";
        $this->_lkb_water_has_count_key .= ":$bucket_cache_suffix";        
        if($rate > 0)
        {
            $this->rate = $rate;
        }
        if($burst > 0)
        {
            $this->burst = $burst;
        }
    }

    /**
     * 刷新漏桶流量和漏桶最后一次访问时间，返回漏桶当前已有的数量
     * 
     * @return array 处理结果，返回的 参数列表如下所示：
     *   $refresh_data['error_msg']  type:string 失败时，错误消息
     *   $refresh_data['lkb_water_has_count']  type:int 当前桶当中已有的数量
     * 
     */
    private function refreshWater()
    {
        $refresh_data = array(
            'lkb_water_has_count' => 0,
            'error_msg' => ''
        );
        try
        {
            $key_arr = array($this->_refresh_time_key, $this->_lkb_water_has_count_key);
            $rds_rs = $this->_rds_conn->getMultiple($key_arr);
            // time for last water refresh--上次刷新数据的时间戳
            $water_refresh_time = intval($rds_rs[0]);
            // water count at refreshTime--上次刷新时，漏桶里面已有的数据总量
            $lkb_water_has_count = intval($rds_rs[1]);
            // 获取当前时间戳(秒)
            $now = time();
            // 当前时间和上次刷新时间中间 间隔N秒 可处理的数量
            $now_can_process = ($now - $water_refresh_time) * $this->rate;
            //$lkb_water_has_count = max(0, $lkb_water_has_count - $now_can_process);
            
            // 技巧点：lkb_water_has_count - now_can_process < 0 说明上次操作没到最大容量，由于流速恒定，过了不可再补，所以最多也就是重置为0
            // 且当前时间戳和上次时间戳有跨度，漏桶里数据需要重置为0，因为水流出去了就无法补，所以不存在负数问题
            // 水随着时间流逝,不断流走,最多就流干到0，当首次跨秒时默认为当前桶为空桶，也就是目前容量为0
            if($now != $water_refresh_time)
            {
                $lkb_water_has_count = 0;
                // 设置本次时间为上次刷新时间
                $this->_rds_conn->set($this->_refresh_time_key, $now, $this->_leaky_bucket_timeout);
            }
            // 设置当前可处理的数量
            $this->_rds_conn->set($this->_lkb_water_has_count_key, $lkb_water_has_count, $this->_leaky_bucket_timeout);
            $refresh_data['lkb_water_has_count'] = $lkb_water_has_count;
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查失败问题
            $refresh_data['error_msg'] = $e->getMessage();
        }
        return $refresh_data;
    }

    /**
     * 当前漏桶是否还可继续添加数据
     * 
     * @return array 处理结果，返回的 参数列表如下所示：
     *   $permission_data['is_allowed']  type:bool 是否可继续处理，true:是，false:否
     *   $permission_data['error_msg']  type:string 失败时，错误消息
     * 
     */
    public function permissionGranted()
    {
        $permission_data = array(
            'is_allowed' => false,
            'error_msg' => ''
        );
        try
        {
            $refresh_data = $this->refreshWater();
            $lkb_water_has_count = $refresh_data['lkb_water_has_count'];
            if($lkb_water_has_count < $this->burst)
            {
                // 漏桶还没满,可以继续加1
                $this->_rds_conn->incr($this->_lkb_water_has_count_key);
                $permission_data['is_allowed'] = true;
            }
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查失败问题
            $permission_data['error_msg'] = $e->getMessage();
        }
        return $permission_data;
    }

}