# 学习websocket
通过研究源码方式学习<BR>
源码功能是一个 PHP 的 websocket 的聊天室

# php实现的服务端
源码不是我写的，我是在人家基础上修改的，原文链接是： [源博客](http://www.cnblogs.com/zhenbianshu/p/6111257.html)<BR>
## 基本流程
### 服务端启动
websocket服务端是通过command line模式运行，不用多说，php只要涉及常驻内存型的网络应用聊天室定时器都是这种模式运行  
```
	php  server.php
```
服务端就以套接字的形式监听在一个IP:Port上，等待连接。具体代码实现，请看server.php里的注释
```
	//服务器启动后，会阻塞到如下，因为此时尚无客户端来连接
	$read_num = socket_select($sockets, $write, $except, NULL);
```
### 客户端连接
浏览器直接访问client.html即可自动连接，客户端的代码比较简单，因为js已经封装了ws的客户端代码
```
	//与服务端开始建立连接
    var ws = new WebSocket("ws://127.0.0.1:8000");
```



# js实现的客户端
