<?php

namespace Ltxiong\CustomLog;

/**
 * 远程写 rsyslog 日志类，单次发送最大可发送4K数据 使用示例 TESTLOG|APILOG|SERVICELOG
 *   TESTLOG 研发代码过程输出的测试日志和调试日志，主要用于代码当中通过过程日志进行问题排查
 *   APILOG api接口日志
 *   SERVICELOG service服务日志
 * @example
 * 
 * use Ltxiong\CustomLog\Rsyslog;
 * 
 * $log_id_arr = array(1000001 => 'TESTLOG_HYPERF_TT', 1000002 => 'APILOG_FIND_YY', 1000003 => 'SERVICELOG_FIND_YY',);
 * $message = json_encode($data);
 * $rsyslog_instance = new Rsyslog('133.10.0.11', 19858, $log_id_arr);
 * $send_rs = $rsyslog_instance->Send($message, 1000001);
 * 
 */
class Rsyslog
{
    
    /**
     * @desc 日志服务器地址
     * Syslog destination server
     * @var string
     * @access private
     */
    private $_host;

    /**
     * @desc 日志服务器 端口
     * Standard syslog port is 514
     * @var int
     * @access private
     */
    private $_port;

    /**
     * @desc 链接超时时间
     * @var int
     * @access private
     */
    private $_timeout = 1;

    /**
     * 数据存储是以“字节”(Byte)为单位，数据传输大多是以“位”(bit，又名“比特”)为单位，一个位就代表一个0或1
     * 消息默认长度(字节数) -- 每8个位(bit，简写为b)组成一个字节(Byte，简写为B)，是最小一级的信息单位。
     * 1024字节也就是1kb
     * 1KiB（Kibibyte）=1024byte (二进制) 1KB（Kilobyte）=1000byte  (十进制)
     * 
     */
    private $_msg_default_len = 1024;

    /**
     * 消息最大长度(字节数 1024 * 4)
     */
    private $_msg_max_len = 4096;

	/**
	 * 
	 * 日志的标示ID列表
	 * @var array
	 * @access protected
	 * 
	 */
	private $_log_id_arr;

    /**
     * 套接字传输器/传输协议
     */
    private $_socket_transports = array(
        'tcp', 
        'udp', 
        'ssl', 
        'sslv2', 
        'sslv3', 
        'tls'
    );

    /**
     * 构造函数 可输入策略数组，定义内容
     *
     * @param string $host 日志服务器IP
     * @param integer $port 日志服务器接收数据的端口
     * @param array $log_id_arr  自定义日志前缀数组
     */
    public function __construct($host = '', $port = 19880, $log_id_arr = array())
    {
        $this->_host = $host;
        $this->_port = $port;
		$this->_log_id_arr = $log_id_arr;
    }


    /**
     * 发送消息之前，进行消息前置处理，包括消息体长度处理，如消息内容超过最大长度，将对消息内容进行截断只有前N长度的内容有效
     *
     * @param string $message 日志消息内容
     * @param string $msg_prefix 日志消息前缀 
     * @param integer $msg_len 消息长度 
     * @return void
     */
    private function ProcessMessage($message = '', $msg_prefix = '', $msg_len = 1024)
    {
        if(empty($message) || !is_string($message))
        {
            return '';
        }
        $msg_len = intval($msg_len);
        $msg_len = $msg_len > $this->_msg_max_len ? $this->_msg_max_len : ($msg_len < 0 ? $this->_msg_default_len : $msg_len);
		//  毫秒时间戳 
        //$log_send_time = intval(microtime(true) * 1000);
        $time_arr = explode(".", number_format(microtime(true), 3, '.', ''));
        $log_send_time = date("YmdHms", intval($time_arr[0])) . '|' . $time_arr[1];
        // 消息最前面带空格，要么消息最前面带上<>开头和结尾的特殊字符，
        // 例如： <PHP RSYSLOG>Apr BLOG_LTX3_KKK 1xxxxxxxxxx 22020/04/20/11:32:43，实际消息内容为 1xxxxxxxxxx 22020/04/20/11:32:43
        $message = substr($message, 0, $msg_len);
        $message = "<PHP RSYSLOG> log $msg_prefix $log_send_time|origin_msg|$message";
        return $message;
    }

    /**
     * 发送消息之前，进行消息前缀处理
     *
     * @param integer / integer numberical $log_id 日志ID(根据一定的规范命名)
     * @return string 返回日志ID对应的消息前缀
     */
    private function GetMessagePrefix($log_id)
    {
		if (!isset($this->_log_id_arr[$log_id]))
		{
			return '';
		}
        return $this->_log_id_arr[$log_id];
    }

    /**
     * 通过TCP/UDP协议 发送消息到远程rsyslog 服务器，并能够进行日志归类和自动分目录存储
     *
     * @param string $message 日志消息内容
     * @param integer / integer numberical $log_id 日志ID(根据一定的规范命名)
     * @param integer $msg_len  消息长度，默认1024字节，最大4096字节
     * @param string $socket_transport 传输协议，默认为tcp协议
     * @return void
     */
    public function Send($message, $log_id, $msg_len = 1024, $socket_transport = 'tcp')
    {
		if(empty($message) || !is_string($message))
		{
			return false;
        }
        $socket_transport = strtolower($socket_transport);
        if(!in_array($socket_transport, $this->_socket_transports))
        {
            return false;
        }
        // 消息内容前缀
        $msg_prefix = $this->GetMessagePrefix($log_id);
        if(empty($msg_prefix))
        {
            return false;
        }
        // 对消息内容进行处理
        $message = $this->ProcessMessage($message, $msg_prefix, $msg_len);

        $errno = "";
        $errstr = "";
        // 打开套接字描述符 
        $fp = @fsockopen("$socket_transport://" . $this->_host, $this->_port, $errno, $errstr, $this->_timeout);
        if ($fp) {
            // 发送消息
            fwrite($fp, $message);
            // 关闭连接
            fclose($fp);
            return true;
        }
        return false;
    }

}


/**
 * 
 * $log_id_arr = array(1000001 => 'TESTLOG_HYPERF_TT', 1000002 => 'APILOG_FIND_YY', 1000003 => 'SERVICELOG_FIND_YY',);
 * $message = json_encode($data);
 * $rsyslog_instance = new Rsyslog('133.10.0.11', 19858, $log_id_arr);
 * $send_rs = $rsyslog_instance->Send($message, 1000001);
 * 
 */