<?php
define('YCN_VERSION', '1.0.0');
define('YCN_START_TIME', microtime(true));
define('YCN_START_MEM', memory_get_usage());
define('JSON_OPTIONS', \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
define('DS', DIRECTORY_SEPARATOR);

define('ROOT_PATH', __DIR__);
define('APP_PATH', realpath(ROOT_PATH . DS . 'app'));
define('COMMON_PATH', realpath(ROOT_PATH . DS . 'common'));
define('CONFIG_PATH', realpath(ROOT_PATH . DS . 'config'));
define('LIB_PATH', realpath(ROOT_PATH . DS . 'lib'));
define('SYSTEM_PATH', realpath(ROOT_PATH . DS . 'system'));
define('RUNTIME_PATH', realpath(ROOT_PATH . DS . 'runtime'));
define('VENDOR_PATH', realpath(ROOT_PATH . DS . 'vendor'));

define('EXT', '.php');

define('JOBS_QUEUE', 'JOBS:QUEUE');
define('CLI_MODE', true);
define('DB_TTL', 60);
/*配置文件路径*/
define('REGISTER_CONFIG_PATH', [
    CONFIG_PATH . DS . 'common.ini',
]);

date_default_timezone_set("Asia/Shanghai");

/* soap 配置 */
//define('WSDL_CACHE_DIR', realpath(RUNTIME_PATH . '/wsdl'));
//ini_set('soap.wsdl_cache_enabled', 1);
//ini_set('soap.wsdl_cache_dir', \WSDL_CACHE_DIR);
//ini_set('soap.wsdl_cache_ttl', 86400);
//ini_set('soap.wsdl_cache_limit', 0);

require VENDOR_PATH . DS .'autoload.php';
require COMMON_PATH . DS .'function.php';
require SYSTEM_PATH . DS . 'loader.php';

use \system\{register,server,config,logger\log,loader,error};

use \Swoole\{Runtime,Process,Coroutine};

//注册自动加载
loader::register();
//注册异常处理
error\error::register();

config::register(REGISTER_CONFIG_PATH);
register::put('config', config::get());

$server = new server($host, $port);
//开启协程
Runtime::enableCoroutine(true);

$server->set([
    'reactor_num ' => 4,//线程数
    'worker_num' => 8, // Worker进程数
    'reload_async' => true,
    'tcp_fastopen' => true,
    'task_worker_num' => 20,
    'task_max_request' => 2000,
    'task_enable_coroutine'=>true,
    'dispatch_mode' => 1,

    'log_file' => RUNTIME_PATH . '/swoole//http_' . $port . '_' . date('Ymd') . '.log',
    'pid_file' => RUNTIME_PATH . '/swoole/http_' . $port . '.pid',
    'max_coroutine' => 10000,
    'max_connection' => 10000,
    'buffer_output_size' => 16 * 1024 * 1024,
]);

$server->port = $port;
$server->config = config::get();
$dbConfig = $server->config['mysql'];
$redisConfig = $server->config['redis_cluster_home'];


$server->_task = new \system\task\task(\system\task\taskType::splQueue());
$server->redisPool = new \system\cache\redis\redisPool(200, $redisConfig);
$server->dbPool = new \system\db\dbPool(150, $dbConfig);

$logPprocess = new Process(function(Process $logPprocess) use($server) {
    $data = $logPprocess->read();
    if(!empty($data)) {
        $filename = RUNTIME_PATH . '/swoole/' . date('Y-m-d') . '.log';
        Coroutine::writeFile($filename, log::getFormat($data), FILE_APPEND);
    }
}, false, 1, true);
$logPprocess->name("logger[{$server->port}]");
$logPprocess->setBlocking(false);
$server->addProcess($logPprocess);

$server->on('Start', function ($server) {
    echo "swoole runing ...\r\n";
    swoole_set_process_name("http[$server->port]");
});

$server->on('Shutdown', function ($server) {
    echo "swoole stop ...\r\n";
});

$server->on("Open", function (\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request) {
    print_r($request);
});

$server->on("Close", function (\Swoole\WebSocket\Server $server, int $fd, int $reactorId) {
    echo "Close fd:{$fd} reactorId:{$reactorId}\r\n";
});


$server->on('WorkerStart', function (\Swoole\WebSocket\Server $server, $worker_id) {
    if($worker_id > $server->setting['worker_num']) {
        //task worker
        swoole_set_process_name("TaskWork[$server->port][$worker_id]");
    }else {
        //event worker
        swoole_set_process_name("EventWork[$server->port][$worker_id]");
        if($worker_id == 1) {
            //定时任务 -- 队列任务投递
            \Swoole\Timer::tick($server->config['swoole']['task_queue'] ?? 1000, function() use($server) {
                $task_data = $server->_task->get();
                if(!empty($task_data)) {
                    $server->task($task_data);
                }
            });
        }elseif($worker_id == 2) {
            //定时将任务PUT到定时任务队列
            \Swoole\Timer::tick($server->config['swoole']['timer_task_time'] ?? 1000, function() use($server) {
                $server->_task->get(123);
                $timer_task_data = $server->_task->get();
                var_dump($timer_task_data);
                if(!empty($timer_task_data)) {
                    if(is_string($timer_task_data)) {
                        $server->_task->put($timer_task_data);
                    }else {
                        foreach($timer_task_data as $k => $v) {
                            $server->_task->put($v);
                        }
                    }
                }
            });
        }
    }
});

$server->on("Request", function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) use($server, $logPprocess) {
    $infoPath = $request->server['request_uri'] ?? $request->server['path_info'];
    list(list($module, $controller, $action), $pathparam) = \system\route\route::getRoutepath($infoPath);
    $route = "{$module}/$controller/$action";
    $logger = new \system\logger\log($route);
    register::put('logger', $logger);
    $logger->addLog($route);

    $header = array_change_key_case(array_merge($request->server, $request->header), CASE_UPPER);
    $body = $request->rawContent() ?: null;
    $file = $request->files ?: null;
    $get = $request->get ?: null;
    $post = $request->post ?: null;

    $route = [];
    $route['route'] = $route;
    $route['module'] = $module;
    $route['controller'] = $controller;
    $route['action'] = $action;
    $request = new \system\request\request($header, $body, $get, $post, $file, $pathparam, $route);

    $db = $server->dbPool->get();
    $redis = $server->redisPool->get();
    register::put('db', $db);
    register::put('redis', $redis);
    register::put('request', $request);
    register::put('task', new \system\task\task($redis));
    //返回文本
    $result = \system\loader::dispatch($request);

    register::del('db', $db);
    register::del('redis', $redis);
    $server->dbPool->put($db);
    $server->redisPool->put($redis);
    $logger->bLog();
    //header("Content-Type: text/html;charset=utf-8");
    $response->header('Content-Type', 'text/html;charset=utf-8');
    $response->end($result);
});

$server->on("Message", function (\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame) {
    $server->push($frame->fd, "this is server " . date('Y-m-d H:i:s'));
});

$server->on("Connect", function (\Swoole\WebSocket\Server $server, int $fd, int $reactorId) {
});

$server->on("Receive", function (\Swoole\WebSocket\Server $server, int $fd, int $reactor_id, string $data) {
});

//执行异步任务
$server->on("Task", function (\Swoole\WebSocket\Server $server, \Swoole\Server\Task $task) {
    $data = $task->data ?? null;
//    echo "task_{$data}\r\n";
//    if(!empty($data)) {
//        $data = json_decode($data, true);
//        if(!empty($data['url'])) {
//            $result = \system\request\httpRequest::requestCURL($data['url'], 'get');
//        }
//    }
    $task->finish("{$task->id}_" . date('Y-m-d H:i:s'));
});

$server->on("Finish", function (\Swoole\WebSocket\Server $server, int $task_id, string $data) {
//    print_r($task_id);print_r($data);
    echo "\r\n";
});

$server->start();