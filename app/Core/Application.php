<?php
/**
 * 收租管理系统 - 应用核心类
 * 
 * 负责初始化应用、路由分发、依赖注入等核心功能
 */

namespace App\Core;

use App\Core\Router;
use App\Core\Database;
use App\Core\Config;
use App\Core\Session;
use App\Core\Auth;

class Application
{
    /**
     * @var Application 单例实例
     */
    private static $instance;
    
    /**
     * @var Router 路由器实例
     */
    private $router;
    
    /**
     * @var Database 数据库实例
     */
    private $database;
    
    /**
     * @var Config 配置实例
     */
    private $config;
    
    /**
     * @var Session 会话实例
     */
    private $session;
    
    /**
     * @var Auth 认证实例
     */
    private $auth;
    
    /**
     * @var array 服务容器
     */
    private $services = [];

    /**
     * @var bool 应用是否已初始化
     */
    private $initialized = false;
    
    /**
     * 私有构造函数（单例模式）
     */
    private function __construct()
    {
        // 防止外部实例化
    }
    
    /**
     * 获取应用单例实例
     * 
     * @return Application
     */
    public static function getInstance(): Application
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * 初始化应用
     * 
     * @param array $config 配置数组
     * @return void
     */
    public function init(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        // 先加载配置（错误处理需要配置）
        $this->config = new Config($config);
        
        // 设置错误处理
        $this->setupErrorHandling();
        
        // 初始化会话
        $this->session = new Session($this->config->get('session', []));
        $this->session->start();
        
        // 初始化数据库连接
        $this->initDatabase();
        
        // 初始化认证系统
        $this->auth = new Auth($this->session, $this->database);
        
        // 初始化路由器
        $this->router = new Router();
        $this->router->registerDefaultRoutes();
        
        // 注册核心服务
        $this->registerCoreServices();
        
        // 设置时区
        date_default_timezone_set($this->config->get('app.timezone', 'Asia/Shanghai'));
        
        // 记录初始化日志
        error_log('收租管理系统应用已初始化 - ' . date('Y-m-d H:i:s'));

        $this->initialized = true;
    }
    
    /**
     * 初始化数据库连接
     * 
     * @return void
     */
    private function initDatabase(): void
    {
        $dbConfig = [
            'host' => $this->config->get('database.host', 'localhost'),
            'port' => $this->config->get('database.port', 3306),
            'database' => $this->config->get('database.database', 'easy_rent'),
            'username' => $this->config->get('database.username', ''),
            'password' => $this->config->get('database.password', ''),
            'charset' => $this->config->get('database.charset', 'utf8mb4'),
            'collation' => $this->config->get('database.collation', 'utf8mb4_unicode_ci')
        ];
        
        $this->database = new Database($dbConfig);
        
        // 测试数据库连接
        try {
            $this->database->connect();
            error_log('数据库连接成功');
        } catch (\Exception $e) {
            error_log('数据库连接失败: ' . $e->getMessage());
            // Day 1 收口：数据库异常不直接中断启动，交由后续请求按需处理。
        }
    }
    
    /**
     * 设置错误处理
     * 
     * @return void
     */
    private function setupErrorHandling(): void
    {
        // 设置错误报告级别
        error_reporting(E_ALL);
        
        // 自定义错误处理函数
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            // 将错误转换为异常
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        
        // 自定义异常处理函数
        set_exception_handler(function($exception) {
            $this->handleException($exception);
        });
        
        // 设置PHP错误显示
        ini_set('display_errors', $this->config->get('app.debug', false) ? '1' : '0');
        ini_set('display_startup_errors', $this->config->get('app.debug', false) ? '1' : '0');
    }
    
    /**
     * 处理异常
     * 
     * @param \Throwable $exception 异常对象
     * @return void
     */
    private function handleException(\Throwable $exception): void
    {
        // 记录异常
        $errorMessage = sprintf(
            "异常: %s\n文件: %s (第 %d 行)\n堆栈跟踪:\n%s",
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
        
        error_log($errorMessage);
        
        // 根据环境显示错误信息
        if ($this->config->get('app.debug', false)) {
            // 开发环境：显示详细错误
            http_response_code(500);
            echo '<!DOCTYPE html>';
            echo '<html lang="zh-CN">';
            echo '<head><title>系统错误</title>';
            echo '<style>';
            echo 'body { font-family: Arial, sans-serif; padding: 20px; background: #f8f9fa; }';
            echo '.error-box { background: white; border: 1px solid #dc3545; border-radius: 5px; padding: 20px; margin: 20px 0; }';
            echo '.error-title { color: #dc3545; margin-top: 0; }';
            echo '.error-details { background: #f8f9fa; padding: 15px; border-radius: 3px; font-family: monospace; white-space: pre-wrap; }';
            echo '</style>';
            echo '</head>';
            echo '<body>';
            echo '<h1 class="error-title">系统错误</h1>';
            echo '<div class="error-box">';
            echo '<h3>' . htmlspecialchars($exception->getMessage()) . '</h3>';
            echo '<p><strong>文件:</strong> ' . htmlspecialchars($exception->getFile()) . ' (第 ' . $exception->getLine() . ' 行)</p>';
            echo '<p><strong>时间:</strong> ' . date('Y-m-d H:i:s') . '</p>';
            echo '<div class="error-details">' . htmlspecialchars($exception->getTraceAsString()) . '</div>';
            echo '</div>';
            echo '</body>';
            echo '</html>';
        } else {
            // 生产环境：显示友好错误页面
            http_response_code(500);
            echo '<!DOCTYPE html>';
            echo '<html lang="zh-CN">';
            echo '<head><title>系统维护中</title>';
            echo '<style>';
            echo 'body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f8f9fa; }';
            echo '.container { max-width: 600px; margin: 0 auto; }';
            echo 'h1 { color: #dc3545; }';
            echo '.message { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }';
            echo '</style>';
            echo '</head>';
            echo '<body>';
            echo '<div class="container">';
            echo '<div class="message">';
            echo '<h1>系统维护中</h1>';
            echo '<p>抱歉，系统暂时无法处理您的请求。</p>';
            echo '<p>我们的技术团队已经收到通知，正在紧急处理中。</p>';
            echo '<p>请稍后再试，或联系系统管理员。</p>';
            echo '<p><small>错误代码: 500</small></p>';
            echo '</div>';
            echo '</div>';
            echo '</body>';
            echo '</html>';
        }
        
        exit(1);
    }
    
    /**
     * 注册核心服务
     * 
     * @return void
     */
    private function registerCoreServices(): void
    {
        // 注册核心服务到容器
        $this->services['config'] = $this->config;
        $this->services['database'] = $this->database;
        $this->services['session'] = $this->session;
        $this->services['auth'] = $this->auth;
        $this->services['router'] = $this->router;
    }
    
    /**
     * 运行应用
     * 
     * @return void
     */
    public function run(): void
    {
        try {
            if (!$this->initialized) {
                $this->init();
            }

            // 分发路由
            $result = $this->router->dispatch();

            if ($result instanceof Response) {
                $result->send();
                return;
            }

            if (is_string($result)) {
                Response::html($result)->send();
                return;
            }

            if ($result !== null) {
                Response::json($result)->send();
                return;
            }
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * 获取配置实例
     * 
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }
    
    /**
     * 获取数据库实例
     * 
     * @return Database
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }
    
    /**
     * 获取会话实例
     * 
     * @return Session
     */
    public function getSession(): Session
    {
        return $this->session;
    }
    
    /**
     * 获取认证实例
     * 
     * @return Auth
     */
    public function getAuth(): Auth
    {
        return $this->auth;
    }
    
    /**
     * 获取路由器实例
     * 
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }
    
    /**
     * 获取服务
     * 
     * @param string $name 服务名称
     * @return mixed 服务实例
     * @throws \Exception 服务不存在时抛出异常
     */
    public function getService(string $name)
    {
        if (!isset($this->services[$name])) {
            throw new \Exception("服务 '{$name}' 未注册");
        }
        
        return $this->services[$name];
    }
    
    /**
     * 注册服务
     * 
     * @param string $name 服务名称
     * @param mixed $service 服务实例
     * @return void
     */
    public function registerService(string $name, $service): void
    {
        $this->services[$name] = $service;
    }
    
    /**
     * 防止克隆
     */
    private function __clone()
    {
        // 防止克隆
    }
    
    /**
     * 防止反序列化
     */
    public function __wakeup()
    {
        throw new \Exception("不能反序列化单例");
    }
}