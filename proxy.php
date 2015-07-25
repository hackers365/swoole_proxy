<?php

class proxy_server {
	public $server;
	
	//
	public $backend_info = array(
		'ip' => '127.0.0.1',
		'port' => '11211',
		'pool_size' => '5',
	);
	//客户端列表
	public $clients;
	//后端连接池
	public $backends;
	
	//client 2 backend的对应关系
	public $c2b;
	//backend 2 client的对应关系
	public $b2c;
	public $setting = array(
		'worker_num' => 1,
		'daemonize' => false,
		//'task_worker_num' => 1,
	);

	public function run() {
		$serv = new swoole_server('0.0.0.0', 9501);
		$serv->on('connect', array($this, 'onConnect'));
		$serv->on('receive', array($this, 'onReceive'));
		$serv->on('close', array($this, 'onClose'));
		$serv->on('workerstart', array($this, 'onWorkerStart'));
		
		$serv->set($this->setting);
		$serv->start();
	}
	
	public function pid($str) {
		echo $str . ":" . getmypid() . "\n";
	}
	
	public function onWorkerStart($serv, $worker_id) {
		if ($worker_id < $this->setting['worker_num']) {
			$this->pid(__function__);
			$this->initBackend($this->backend_info['size']);
		}
		//开启10个backend
		//$this->initBackend(10);
		//$this->backends[] = $this->createBackend();
		
	}
	
	public function initBackend($count) {
		for($i = 1; $i <= $count; $i++) {
			$this->backends[] = $this->createBackend();
		}
	}
	
	public function createBackend() {
		$backend = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
		$backend->on('connect', function($cli) {
			echo "backend connect.\n";
		});
		
		$backend->on('receive', function($cli, $data) {
			echo "backend receive.\n";

			$fd = (int)$cli->sock;
			
			if (!empty($this->b2c[$fd]['fd'])) {
				$this->server->send($this->b2c[$fd]['fd'], $data);
			}
		});
		
		$backend->on('close', function($cli) {
			if (!empty($cli)) {
				$this->backendReconnect($cli);
				//$this->clearClient((int)$cli->sock, $from_client=false);
			}
		});
		
		$backend->on('error', function($cli) {
			echo "error\n";
		});
		
		$backend->connect($this->backend_info['ip'], $this->backend_info['port']);
		return $backend;
	}
	
	public function findBackend() {
		foreach($this->backends as $_backend) {
			if (empty($_backend->in_use)) {
				return $_backend;
			}
		}
		return false;
	}
	
	public function onConnect($serv, $fd) {
		$this->pid(__function__);

		$this->server = $serv;
		
		$this->clients[$fd] = array();
		
		$backend = $this->findBackend();
		//连接池中没有直接关闭
		if (!$backend) {
			//$serv->close($fd);
			$this->clients[$fd]['waiting'] = time();
			//加入等待队列
			$this->wait_list[$fd] = array(
				'fd' => $fd,
			);
			return;
		}
		$backend->in_use = true;
		
		$backend->c_fd = $fd;

		//客户端的fd和后端连接fd的对应关系
		$this->c2b[$fd] = array(
			'sock' => $backend,
		);
		
		$this->b2c[$backend->sock] = array(
			'fd' => $fd,
		);
	}
		
	//重新连接
	public function backendReconnect($cli) {
		$cli->connect('127.0.0.1', 80);
	}
	
	//断线时清除客户端
	public function clearClient($fd) {
		if (!empty($this->c2b[$fd])) {
			$backend = $this->c2b[$fd]['sock'];
			
			$this->c2b[$fd]['sock']->in_use = false;
			$b_fd = $this->c2b[$fd]['sock']->sock;
			unset($this->c2b[$fd]);
			unset($this->b2c[$b_fd]);

			//如果有等待的客户端则处理.
			if ($this->wait_list) {
				$wait_client = array_shift($this->wait_list);
				
				$wait_fd = $wait_client['fd'];
				
				//后台使用true
				$backend->in_use = true;
				
				$this->c2b[$wait_client['fd']] = array(
					'sock' => $backend,
				);
				$this->b2c[$backend->sock] = array(
					'fd' => $wait_fd,
				);

				//如果有待发送
				if (!empty($this->clients[$wait_fd]['data'])) {
					//$ret = 7;
					$ret = $backend->send($this->clients[$wait_fd]['data']);
					if (false !== $ret) {
						$this->clients[$wait_fd]['data'] = '';
					}
				}
				//从等待队列中删除
				$this->clients[$wait_fd]['waiting'] = 0;
				unset($this->wait_list[$wait_fd]);
			}
		}
	}
	
	//服务器收到客户端发来的消息
	public function onReceive($serv, $fd, $from_id, $data) {
		echo "client receive.\n";
		if (!empty($this->clients[$fd]['waiting'])) {
			if (empty($this->clients[$fd]['data'])) {
				$this->clients[$fd]['data'] = '';
			}
			$this->clients[$fd]['data'] .= $data;
			return;
		}
		
		if (!empty($this->c2b[$fd])) {
			$ret = $this->c2b[$fd]['sock']->send($data);
		}
	}
	
	public function onClose($serv, $fd, $from_id) {
		echo "client close.\n";
		$this->clearClient($fd, $from_client=true);
	}
}

$proxy = new proxy_server;

$proxy->run();
