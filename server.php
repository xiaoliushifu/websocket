<?php
error_reporting(E_ALL);
set_time_limit(0);
date_default_timezone_set('Asia/shanghai');

class WebSocket {
    const LOG_PATH = "./";
    const LISTEN_SOCKET_NUM = 9;

	 //存放套接字，每个套接字表示
    private $sockets = [];
    private $master;

    public function __construct($host, $port) {
        try {
			//创建一个通讯节点（客户端和服务端都可以，这里明显是服务端）
			//第一个参数有（ipv4,ipv6,本地协议（进程通讯）三种，AF_INET是指IPV4网络协议
			//第二个参数一般都是SOCK_STREAM （顺序，可靠，全双工，基于连接的字节流，tcp就是用这种套接字类型）
			//第三个参数是指第一个参数（IPV4)下的具体协议，目前只用过SOL_TCP。
			//综上所述，对于一个web开发人员，创建通讯节点（无论客户端还是服务端）就是固定的这三个参数了
            $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            // 在SOL_SOCKET层的重用选项设置为1。设置IP和端口重用,在重启服务器后能重新使用此端口;
            socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
            // 将IP和端口绑定在服务器socket上;
            $this->debug(["绑定在套接字: {$host}:{$port} 并开始监听"]);
            socket_bind($this->master, $host, $port);
            // listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
            socket_listen($this->master, self::LISTEN_SOCKET_NUM);
        } catch (\Exception $e) {
            $err_code = socket_last_error();
            $err_msg = socket_strerror($err_code);

            $this->error([
                'error_init_server',
                $err_code,
                $err_msg
            ]);
        }

        $this->sockets[0] = ['resource' => $this->master];
        //$pid = posix_getpid();//获得当前进程ID
        $pid = getmypid();//获得当前进程ID,由于posix扩展未安装，改用它
        $this->debug(["服务端: {$this->master} 启动,pid: {$pid}"]);

		//然后用无限循环开始监听
        while (true) {
            $this->debug(["开始一次循环"]);
            try {
                $this->doServer();
            } catch (\Exception $e) {
                $this->error([
                    'error_do_server',
                    $e->getCode(),
                    $e->getMessage()
                ]);
            }
            $this->debug(["结束一次循环"]);
        }
    }

    private function doServer() {
        $write = $except = NULL;
		//获得sockets下一级数组里的resuource字段，也就是所有的套接字资源对象
        $sockets = array_column($this->sockets, 'resource');
		//监视三个套接字数组里的套接字资源：可读的，可写的，异常的。只第一个只读的有值。一旦有变动，$sockets就会筛选粗状态出现变化的那些套接字，其它不保存。
		//第四个参数超时时间非常重要。null意味着将会阻塞到这里，直到有可操作的socket返回。才会往下进行
		//第一次阻塞到socket_select，是服务器刚刚启动的时候，它要等待客户端的连接。
        $this->debug(['$sockets开始阻塞在select 套接字数量：'.count($sockets)]);
        $read_num = socket_select($sockets, $write, $except, NULL);
        $this->debug(["监听到有{$read_num}个套接字发生变动"]);
        // select作为监视函数,赶紧去查看php手册
        if (false === $read_num) {
            $this->error([
                'error_select',
                $err_code = socket_last_error(),
                socket_strerror($err_code)
            ]);
            return;
        }
		//上面监听到了三个套接字组的一个有变动（由于$write,$except为空，自然是$sockets里呗）。
		//否则还要遍历$write,$except数组
        foreach ($sockets as $socket) {
            // 如果是服务器socket,则处理连接逻辑，（只有每个客户端来连接服务端时才会走这段程序）
            if ($socket == $this->master) {
				//master进程读取一个客户端进程。有可能读不到。阻塞到这里
				$this->debug(["服务端套接字有状态变动，估计有客户端来连接了"]);
                $client = socket_accept($this->master);
				$this->debug(["读取到一个客户端",$client]);
                // 创建,绑定,监听后socket_accept函数将会读取外部连接,一旦有连接过来,将会返回一个新的socket资源用以交互,如果是一个多个连接的队列,只会处理第一个,如果没有连接过来的话,进程将会被阻塞,直到连接上。
				//如果用set_socket_blocking或socket_set_noblock()设置了阻塞,会返回false;返回资源后,将会持续等待连接。
				//这里明显并不是上述过程，而是通过socket_select开启监听，然后通过socket_accept直接读取外部连接
                if (false === $client) {
                    $this->error([
                        'err_accept',
                        $err_code = socket_last_error(),
                        socket_strerror($err_code)
                    ]);
                    continue;
                } else {
					$this->debug(["执行connect方法，把客户端套接字放到数组里"]);
                    self::connect($client);
                    continue;
                }
            } else {
                // 如果可读的是其他已连接socket,则读取2048字节的数据,保存到$buffer缓存里
				//最终广播给所有在线的客户端，服务端只是转接，不显示。
                $this->debug(["客户端套接字有状态变动，socket_recv()读取该套接字的信息"]);
                $bytes = @socket_recv($socket, $buffer, 2048, 0);
                if ($bytes < 9) {
                    $recv_msg = $this->disconnect($socket);
                } else {
					//一般客户端第一次连接时都是先发送握手http，这里就是准备服务端的握手响应
                    if (!$this->sockets[(int)$socket]['handshake']) {
                        $this->debug(["该客户端尚未握手，服务端准备处理握手"]);
                        self::handShake($socket, $buffer);
                        continue;
                    } else {
						//已经握手完毕，客户端第一次给服务端发送的信息就是登录信息。这里parse就是服务端开始解析
						//客户端过来的消息
                        $recv_msg = self::parse($buffer);
                        $this->debug(["调用parse方法，解析帧中的应用信息为：".implode(',',$recv_msg)]);
                    }
                }
				//在$recv_msg数组头部加一个元素
                array_unshift($recv_msg, 'receive_msg');
				//服务端整理解析客户端的信息，并组装成websocket的帧
                $msg = self::dealMsg($socket, $recv_msg);
                $this->debug(["组装好服务端的响应信息帧{$msg},\n广播给所有的客户端"]);
				
				//把帧数据广播给所有其他客户端
				//服务端除了在与某客户端握手后发送一次有关握手完成的消息之外，其他服务端的消息都是广播
                $this->broadcast($msg);
            }
        }
    }

    /**
     * 将socket添加到已连接列表,但握手状态留空;
	 * 这样下次while(true)循环里的doServer()方法就会监听到
	 这个$socket客户端的变动了。
	 * 由于$this->sockets里放置了master和其他客户端套接字。
	 *而一旦交互都是客户端和服务器交互，所以基本上socket_select会监听到服务器的一次变动和客户端的一次变动
     *
     * @param $socket
     */
    public function connect($socket) {
		//获得这个套接字$socket的远端信息，比如ip，其端口。
		//如果是UNIX内部协议通讯，则一般是获得.sock文件的路径。
        socket_getpeername($socket, $ip, $port);
        $socket_info = [
            'resource' => $socket,
            'uname' => '未知',
            'handshake' => false,//未握手
            'ip' => $ip,//它的ip
            'port' => $port,//它的端口
        ];
		//对一个资源进行(int)会是咋回事呢？非常棒，这样会获取资源在内存中的编号，就是用var_dump()打印时输出的编号。
        $this->sockets[(int)$socket] = $socket_info;
        $this->debug(array_merge(['最新客户端socket信息'], $socket_info));
    }

    /**
     * 客户端关闭连接
     *既然一个$socket代表一个c/s连接，那么干掉这个$socket就表示
     *服务端和客户端断开连接了
     * @param $socket
     *
     * @return array
     */
    private function disconnect($socket) {
        $recv_msg = [
            'type' => 'logout',
            'content' => $this->sockets[(int)$socket]['uname'],
        ];
		//最终通过unset函数，从内存中去掉
        unset($this->sockets[(int)$socket]);

        return $recv_msg;
    }

    /**
     * 该方法完成服务端的握手过程
     * 1 服务端发送握手的消息，本质上还是一个http响应。客户端会在屏幕上显示：xxx连接成功。
     * 2 服务端的握手既然是http响应，所以使用字符即可，不必是二进制数据，容易看得懂
     * 3 服务端除了握手响应外，还发送了个handshake类型的消息给客户端，属于业务消息非websocket范围内的消息概念。
	 上述两个过程后，该方法的使命就完成了。
	 *下面简单说下客户端的逻辑
     * 客户端收到handshake类型的消息后，会向服务端回一个login的消息，
     * 服务端接收后不再单独响应给该客户端，而是广播所有客户端（大意就是xxx登录了），然后继续监听。
     * 客户端收到广播信息后，就会在大屏幕上打印广播消息，然后客户端们就等待，每个客户端的用户开始编写信息发送给服务端；
     * 服务端在接收客户端的消息之前，服务端又会一直阻塞到select。
     * @param $socket
     * @param $buffer
     *
     * @return bool
     */
    public function handShake($socket, $buffer) {
        // 获取到客户端的升级密匙
        $line_with_key = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:') + 18);//Sec-WebSocket-Key:之后的全部信息
        $key = trim(substr($line_with_key, 0, strpos($line_with_key, "\r\n")));//再从换行符截断，就获得了升级密钥

        // 用固定算法生成应答密钥
        $upgrade_key = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));// 升级key的算法
		//其他几个响应头部都是固定的
        $upgrade_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgrade_message .= "Upgrade: websocket\r\n";
        $upgrade_message .= "Sec-WebSocket-Version: 13\r\n";
        $upgrade_message .= "Connection: Upgrade\r\n";
		//拼接上应答密钥就完事了。
        $upgrade_message .= "Sec-WebSocket-Accept:" . $upgrade_key . "\r\n\r\n";

        //上面过程准备好了握手的http响应。下面就是它发送给客户端$socket，自此websocket部分的握手就完成了。
        socket_write($socket, $upgrade_message, strlen($upgrade_message));
        $this->sockets[(int)$socket]['handshake'] = true;
		
		//打入debug日志
        socket_getpeername($socket, $ip, $port);
        $this->debug([
            '服务端握手响应',
            $socket,
            $ip,
            $port
        ]);
		
        // 这里服务端主动向客户端发送一个消息。服务端是用build方法完成对数据帧的组装
        $msg = [
            'type' => 'handshake',
            'content' => 'done',
        ];
        $msg = $this->build(json_encode($msg));
        socket_write($socket, $msg, strlen($msg));
        return true;
    }

    /**
     * 解析数据
	 * 服务端解析客户端发送的数据帧
     * 客户端向服务端发送的数据帧是经过掩码处理的。
     * @param $buffer
	 *注意，这里的$buffer是字节数据，它是从套接字直接获取来的。虽然看起来是乱码。
	 所以$buffer{1}其实是获得了一个字符，8个bit位，是第二个字节。用dechex(ord("字符"))可以获得它的第一个字节的内码
	 *这里就是“字"的utf-8内码的第一个字节。
     *掩码位是固定的4个字节
	 * 注意，掩码只对应用数据做掩码算法处理，也就是mask key之后的都是掩码过的，这之前的数据长度值，FIN,RSV,MASK都不必掩码
     * @return bool|string
     */
    private function parse($buffer) {
        $decoded = '';
		//这里和服务端向客户端发送信息时相反，s->c是根据应用数据来确定数据长度值。
		//的目的是要先获得数据长度值。根据帧的格式前8位bit无需看；第9位固定mask位是1。
		//我们首先关注10-16位，也就是取得第二个字节的前7位（右数）。
		//ord($buffer[1])是获得第二个字节的ascii值(内码）。
		// 任何8bit和01111111按位与操作，将得到8bit的低七位。
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {//126，根据帧的格式，该7位之后的两个字节表示长度数值，2+2是4。
            $masks = substr($buffer, 4, 4);//第0位是，从第33位（第五个字节）开始取位数，共取32位（4个字节），也就是四个字符，因为mask key是固定的32位（4个字节）
            $data = substr($buffer, 8);//则第8个字节之后的都是掩码的数据。
        } else if ($len === 127) {//127,则说明后续的8个字节的值表示数据长度，2+8=10，
            $masks = substr($buffer, 10, 4);//从第10个字节开始取4字节为mask key
            $data = substr($buffer, 14);
        } else {//不是126，也不是127，那么就是0-125。当前7位就表示数据长度，其后就是mask key
            $masks = substr($buffer, 2, 4);//当前7位，还有固定的mask占1位，加上之前的8位。所以从第三个字节开始取4字节是mask key
            $data = substr($buffer, 6);//剩下的就是应用数据
        }
		//找到了mask key；也找到了掩码过的应用数据，下面就是反掩码操作。
		//掩码和反掩码算法都是官网给出的，且都是如下的操作。
		//我们知道，连续对同一个值做偶数次抑或，都将返回原始的值。这就解释了为啥掩码和反掩码是同一个算法的原因了。
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
		//按照约定，解析出来是个json串，所以反解析为一个php数组。
        return json_decode($decoded, true);
    }

    /**
     * 将普通信息组装成websocket数据帧。
	 * 这是用于服务端向客户端发送数据帧，故无需掩码。
	 * 分三个部分，这三个部分都是16进制：
		第一部分固定是81
		第二部分确定应用数据的长度值，其中掩码值凑巧会一直为0
		第三部分就是应用数据
	    最后把三部分合并，用pack('H*',$data)把16进制数打包为2进制返回。
		这就是最终的服务端给客户端的帧。
		默认无论多长的数据都是只用一个帧，这就是为啥第一部分永远用8
     * 这是关键。
     * @param $msg  json字符串
     *
     * @return string
     */
    private function build($msg) {
        $frame = [];
		//8在后续pack函数作用下会打包为1000；而1会打包为0001(opcode)。
		//所以8确定是一个最后帧，1是确定文本帧。
        $frame[0] = '81';
		//由于websocket的帧使用7,7+16,7+64三种方式确定负载数据的长度，负载数据也称之为应用数据
		//故先根据数据的实际字节长度，来反过来确定负载数据长度值的表示方式
        $len = strlen($msg);
		//0-125，是7位的表示方式，125是1111101
        if ($len < 126) {
			//dechex会把数值参数$len当作十进制进而转换成16进制表示
			//如果小于16的话，最多占用7位里的四位，左边的三位需要补0；
			//16到125的长度，就直接转为16进制就行。会全部占用7位。
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
		//如果是7+16,7位的数值是126（1111110，也就是16进制的7e），后2个字节是数据长度，需要4位16进制数就行
		//这里为啥是65025呢？难道是写错了？
        } else if ($len < 65025) {
			//转换为一个十六进制，由于两个字节最多转换为4个16进制数。所以$s <=4
			//当$s不够4个16进制数时，前面需要补0，最终是4位16进制数就行。str_repeat('0', 4 - strlen($s))就是补0操作。
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
		
		//剩下就是7+64的情况，7位的数值是(1111111,也就是16进制的7f),后8个字节是数据长度，需要16位16进制数就行
		//str_repeat('0', 16 - strlen($s))就是补0操作，这里的补0是补16进制的0
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }
		//上面的$frame[1]确定了应用数据的长度值表示，下面该真正的应用数据了。
		//注意，这里没有提及掩码位，这是服务端向客户端发送帧，故不必掩码。mask总是0。
		//根据计算，7位的三种情况0x,7e,7f三种情况。0,7,7在被pack函数打包时凑巧最高位是0。
        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
			//ord返回参数的ascii值，这里ord($msg{$i})是取得每个应用数据里每个字符的每个字节的内码（计算机存储的实际内部编码，与字符集相关）
			//内码只是存储，内码和字符集映射关系影响显示。
			//ord的结算结果一定是一个ascii值（十进制）整型
			//然后再用dechex转换为16进制表示。
            $data .= dechex(ord($msg{$i}));
        }
		//$frame[2]确定了应用数据
        $frame[2] = $data;

		//拼接为一个大字符串。技巧是：使用""空字符串拼接。
        $data = implode('', $frame);
		//上述的转换都是把十进制转换为16进制，这里则是把所有的16进制最终转换为2进制。
		//这就是websocket的帧了。
		//如果直接echo,那么浏览器一般输出乱码，这里是把帧向套接字写入
        return pack("H*", $data);
    }

    /**
     *组装服务端和客户端交互的业务数据格式
     *这里的数据，就是业务消息，并非websocket原生的信息概念。
     *其实就是一些关联数组
     *根据客户端的消息类型，服务端响应对应的消息类型
     *这里注意，服务端响应客户端的消息，是广播发送给客户端的，并非一对一的
     * @param $socket
     * @param $recv_msg
     *          [
     *          'type'=>user/login
     *          'content'=>content
     *          ]
     *
     * @return string
     */
    private function dealMsg($socket, $recv_msg) {
        $msg_type = $recv_msg['type'];
        $msg_content = $recv_msg['content'];
        $response = [];

        switch ($msg_type) {
            case 'login':
                $this->sockets[(int)$socket]['uname'] = $msg_content;
                // 取得最新的名字记录
                $user_list = array_column($this->sockets, 'uname');
                $response['type'] = 'login';
                $response['content'] = $msg_content;
                $response['user_list'] = $user_list;//每个客户端登录时，都把当前在线用户列表告诉客户端
                break;
            case 'logout':
                $user_list = array_column($this->sockets, 'uname');
                $response['type'] = 'logout';
                $response['content'] = $msg_content;
                $response['user_list'] = $user_list;
                break;
            case 'user':
                $uname = $this->sockets[(int)$socket]['uname'];
                $response['type'] = 'user';
                $response['from'] = $uname;
                $response['content'] = $msg_content;
                break;
        }
        //组装成符合websocket格式的数据帧
        return $this->build(json_encode($response));
    }

    /**
     * 广播消息
     * 要广播，就要遍历所有在服务端内存里保存的客户端套接字资源
	 * 依次写入信息即可.每个客户端套接字代表了服务端和客户端的一个连接，服务端可以同时和多个客户端同时保持连接
     * @param $data
     */
    private function broadcast($data) {
        foreach ($this->sockets as $socket) {
            if ($socket['resource'] == $this->master) {
                continue;
            }
            socket_write($socket['resource'], $data, strlen($data));
        }
    }

    /**
     * 记录debug信息
     *
     * @param array $info
     */
    private function debug(array $info) {
        $time = date('Y-m-d H:i:s');
		//添加个时间信息
        array_unshift($info, $time);
		//每个元素都json_encode处理下
        //$info = array_map('json_encode', $info);
		//组织成一定的格式，追加到日志文件里
        file_put_contents(self::LOG_PATH . 'websocket_debug.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }

    /**
     * 记录错误信息，与debug类似，只是级别不同而已
	 *该级别保存在websocket_error.log文件里
     *
     * @param array $info
     */
    private function error(array $info) {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);

        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_error.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }
}
//启动服务器
$ws = new WebSocket("127.0.0.1", "8000");