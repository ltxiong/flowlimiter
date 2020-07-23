# customlog
customlog

关于rsyslog服务搭建详细百度，相应的rsyslog.conf配置见目录中相应的配置文件示例，目录中配置文件要直接用起来，需要做如下几步
1、创建根目录 mkdir /rsyslog/
2、将/etc/rsyslog.conf默认配置文件进行备份，同时将当前目录下的rsyslog.conf配置文件拷贝到 /etc/rsyslog.conf 进行覆盖
3、使用命令启动rsyslogd 服务
4、使用php进行测试验证
