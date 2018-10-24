<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/2/19
 * Time: 下午7:25
 */

namespace deepziyu\yii\swoole\server;

use Swoole;
use deepziyu\yii\swoole\web\ErrorHandler;

/**
 * swoole server
 * events: 'Start', 'ManagerStart', 'ManagerStop', 'PipeMessage', 'Task', 'Packet', 'Finish',
 *         'Receive', 'Connect', 'Close', 'Timer', 'WorkerStart', 'WorkerStop', 'Shutdown', 'WorkerError'
 * @package deepziyu\yii\swoole\server
 */
abstract class Server
{

    protected $id;
    /**
     * @var \Swoole\Server
     */
    protected $swoole;

    protected $serverType = 'http';

    /**
     * @var string
     */
    public $host = '0.0.0.0';

    public $port = 9501;

    /**
     * @var \deepziyu\yii\swoole\bootstrap\BootstrapInterface
     */
    public $bootstrap;
    /**
     * @var string root directory
     */
    public $root;
    /**
     * @var int swoole's process mode
     */
    public $swooleMode;
    /**
     * @var int swoole socket
     */
    public $sockType;
    /**
     * @var array server setting
     * @see https://wiki.swoole.com/wiki/page/274.html
     */
    public $setting = [];

    /**
     * @var Swoole\Table | \Closure
     */
    public $cacheTable;

    public function __construct(array $config = [])
    {
        $this->parseConfig($config);
        $this->init();
    }

    /**
     *
     */
    public function init()
    {
        switch ($this->serverType){
            case 'http':
                $this->swoole = new Swoole\Http\Server($this->host,$this->port);
                $events = ['Request'];
                break;
            case 'socket':
                $this->swoole = new Swoole\WebSocket\Server($this->host,$this->port);
                $events = ['Open','Message','Close','HandShake'];
                break;
            default:
                $this->swoole = new Swoole\Server($this->host,$this->port,$this->swooleMode,$this->sockType);
                $events    = ['ManagerStart', 'ManagerStop', 'PipeMessage','Packet',
                    'Receive', 'Connect', 'Close', 'Timer', 'WorkerStop', 'WorkerError'];
                break;
        }
        if(!isset($this->setting['chroot'])){
            //默认当前站点路径
            $this->setting['chroot'] = $this->root;
        }
        $this->swoole->set($this->setting);

        $this->swoole->on('Start',[$this,'onStart']);
        $this->swoole->on('Shutdown',[$this,'onShutdown']);
        $this->swoole->on('WorkerStart',[$this,'onWorkerStart']);
        $this->swoole->on('WorkerStop',[$this,'onWorkerStop']);
        if(isset($this->setting['task_worker_num'])){
            $this->swoole->on('Task',[$this,'onTask']);
            $this->swoole->on('Finish',[$this,'onFinish']);
        }
        foreach ($events as $event){
            if(method_exists($this,'on'.$event)){
                $this->swoole->on($event,[$this,'on'.$event]);
            }
        }
        if($this->cacheTable && $this->cacheTable instanceof \Closure){
            $this->cacheTable = call_user_func($this->cacheTable);
        }
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($value)
    {
        $this->id = $value;
    }

    /**
     * @return Swoole\Server
     */
    public function getSwoole()
    {
        return $this->swoole;
    }
    /**
     * 启动服务
     */
    public function start()
    {
        $this->swoole->start();
    }

    /**
     * @see https://wiki.swoole.com/wiki/page/p-event/onStart.html
     */
    public function onStart()
    {
        $this->setProcessTitle($this->id,'master');
    }

    /**
     *
     * @param Swoole\Server $server
     * @param $worker_id
     */
    public function onWorkerStart(Swoole\Server $server,$worker_id)
    {
        if($worker_id >= $server->setting['worker_num']) {
            $this->setProcessTitle($this->id,'task process');
        } else {
            $this->setProcessTitle($this->id,'worker process');
        }
        try{
            $this->bootstrap->onWorkerStart($server,$worker_id);
        }catch (\Exception $e){
            print_r("start yii error:".ErrorHandler::convertExceptionToString($e).PHP_EOL);
            $this->swoole->shutdown();
            die;
        }
    }

    public function onWorkerStop(Swoole\Server $server,$worker_id)
    {
        $this->bootstrap->onWorkerStop($server,$worker_id);
    }

    /**
     * @param Swoole\Server $server
     * @see https://wiki.swoole.com/wiki/page/45.html
     */
    public function onShutdown(Swoole\Server $server)
    {
//        echo 'swoole server shutdown';
    }

    /**
     * set process title
     * @param $siteName
     * @param $channelName
     */
    public function setProcessTitle($siteName,$channelName)
    {
        //低版本Linux内核和Mac OSX不支持进程重命名
        //@see https://wiki.swoole.com/wiki/page/125.html
        @swoole_set_process_name("php $siteName :$channelName swoole process");
    }

    private function parseConfig(array $config)
    {
        foreach ($config as $key=>$value){
            if(property_exists($this,$key)){
                if($key === 'bootstrap'){
                    if(is_array($value) && isset($value['class'])){
                        $class = $value['class']; unset($value['class']);
                        $instance = new $class($this);
                        foreach ($value as $k => $item) {
                            $instance->$k = $item;
                        }
                        $this->bootstrap = $instance;
                    }else{
                        throw new \InvalidArgumentException("Invalid config 'bootstrap'");
                    }
                }elseif($value){
                    $this->$key = $value;
                }
                continue;
            }
            $className = get_called_class();
            throw new \Exception("config '$key' not found in $className->$key");
        }

    }

    /**
     * 根据配置文件创建swoole 服务
     * @param array $nodeConfig 单节点的swoole配置
     * @return static
     * @throws \Exception
     */
    static function autoCreate($nodeConfig)
    {
        if(isset($nodeConfig['class'])){
            $class = $nodeConfig['class'];
            unset($nodeConfig['class']);
            $instance = new $class($nodeConfig);
            if($instance instanceof Server){
               return $instance;
            }
            throw new \Exception('class must implement deepziyu\yii\swoole\server');
        }else{
            throw new \Exception("config 'class' not found");
        }

    }

    /**
     * 服务运行入口
     * @param array $config swoole配置文件
     * @param callable $func 启动回调
     */
    static function run($config)
    {
        global $argv;
        if(!isset($argv[0],$argv[1])){
            print_r("invalid run params,see help,run like:php swoole.php start|stop|reload|reload-task".PHP_EOL);
            return;
        }

        if(!isset($config['id'])){
            print_r("invalid node id in config file".PHP_EOL);
            return;
        }
        if(!isset($config['setting']['pid_file'])){
            $config['setting']['pid_file'] = sys_get_temp_dir() . "/swoole-{$config['id']}.pid";
        }
        if(!isset($config['class'])){
            $config['class'] = 'deepziyu\yii\swoole\server\HttpServer';
        }

        $command = $argv[1];
        $pidFile = $config['setting']['pid_file'];
        $masterPid     = file_exists($pidFile) ? file_get_contents($pidFile) : null;

        if ($command == 'start'){
            if ($masterPid > 0 and posix_kill($masterPid,0)) {
                print_r('Server is already running. Please stop it first.'.PHP_EOL);
                exit;
            }
            static::autoCreate($config)->start();
        }elseif($command == 'stop'){
            if(!empty($masterPid)){
                posix_kill($masterPid,SIGTERM);
                if(PHP_OS=="Darwin"){
                    //mac下.发送信号量无法触发shutdown.
                    unlink($pidFile);
                }
            }else{
                print_r('master pid is null, maybe you delete the pid file we created. you can manually kill the master process with signal SIGTERM.'.PHP_EOL);
            }
            exit;
        }elseif($command == 'reload'){
            if (!empty($masterPid)) {
                posix_kill($masterPid, SIGUSR1); // reload all worker
            } else {
                print_r('master pid is null, maybe you delete the pid file we created. you can manually kill the master process with signal SIGUSR1.'.PHP_EOL);
            }
            exit;
        } elseif ($command == 'reload-task') {
            if (!empty($masterPid)) {
                posix_kill($masterPid, SIGUSR2); // reload all task
            } else {
                print_r('master pid is null, maybe you delete the pid file we created. you can manually kill the master process with signal SIGUSR2.' . PHP_EOL);
            }
            exit;
        }
    }

}