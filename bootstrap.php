<?php
/**
 * 收租管理系统 - 应用启动文件
 * 
 * 负责初始化应用环境、加载配置和启动应用
 */

// 定义应用根目录（如果尚未定义）
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__FILE__));
}

// 定义公共目录
define('PUBLIC_PATH', APP_ROOT . '/public');

// 定义应用目录
define('APP_PATH', APP_ROOT . '/app');

// 定义存储目录
define('STORAGE_PATH', APP_ROOT . '/storage');

// 定义视图目录
define('VIEW_PATH', APP_PATH . '/Views');

// 定义配置目录
define('CONFIG_PATH', APP_PATH . '/Config');

// 定义核心目录
define('CORE_PATH', APP_PATH . '/Core');

// 定义控制器目录
define('CONTROLLER_PATH', APP_PATH . '/Controllers');

// 定义模型目录
define('MODEL_PATH', APP_PATH . '/Models');

// 定义环境文件路径
define('ENV_FILE', APP_ROOT . '/.env');

// 检查PHP版本
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    die('收租管理系统需要PHP 8.2.0或更高版本。当前版本: ' . PHP_VERSION);
}

// 检查必要的扩展
$requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl'];
$missingExtensions = [];

foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $missingExtensions[] = $extension;
    }
}

if (!empty($missingExtensions)) {
    die('收租管理系统需要以下PHP扩展: ' . implode(', ', $missingExtensions));
}

// 设置错误报告
if (file_exists(ENV_FILE)) {
    // 加载环境变量
    $envContent = file_get_contents(ENV_FILE);
    $lines = explode("\n", $envContent);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            
            // 移除引号（仅在值非空时）
            if (!empty($value)) {
                if (($value[0] === '"' && substr($value, -1) === '"') ||
                    ($value[0] === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
    
    // 根据环境设置错误报告
    $appEnv = getenv('APP_ENV') ?: 'production';
    $appDebug = getenv('APP_DEBUG') === 'true';
    
    if ($appEnv === 'local' || $appDebug) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
    } else {
        error_reporting(E_ALL & ~E_DEPRECATED);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }
} else {
    // 默认设置（生产环境）
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// 设置时区
$timezone = getenv('APP_TIMEZONE') ?: 'Asia/Shanghai';
date_default_timezone_set($timezone);

// 设置区域设置
$locale = getenv('APP_LOCALE') ?: 'zh_CN';
setlocale(LC_ALL, $locale . '.UTF-8');

// 设置内部字符编码
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// 自动加载类
spl_autoload_register(function ($className) {
    // 将命名空间分隔符转换为目录分隔符
    $className = str_replace('\\', DIRECTORY_SEPARATOR, $className);
    
    // 可能的文件路径
    $possiblePaths = [
        APP_ROOT . '/' . $className . '.php',
        CORE_PATH . '/' . $className . '.php',
        CONTROLLER_PATH . '/' . $className . '.php',
        MODEL_PATH . '/' . $className . '.php',
        CONFIG_PATH . '/' . $className . '.php',
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
    
    // 尝试从类名中提取基本名称
    $baseName = basename(str_replace('\\', '/', $className));
    
    // 检查核心类
    $coreClassPath = CORE_PATH . '/' . $baseName . '.php';
    if (file_exists($coreClassPath)) {
        require_once $coreClassPath;
        return;
    }
    
    // 如果找不到类，记录错误但不中断（可能在其他地方定义）
    if (getenv('APP_DEBUG') === 'true') {
        error_log("自动加载失败: 类 {$className} 未找到");
    }
});

// 加载辅助函数
require_once CORE_PATH . '/helpers.php';

// 注册自定义错误处理
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // 将错误转换为异常
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// 注册自定义异常处理
set_exception_handler(function($exception) {
    // 记录异常
    $errorMessage = sprintf(
        "未捕获的异常: %s\n文件: %s (第 %d 行)\n堆栈跟踪:\n%s",
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    
    error_log($errorMessage);
    
    // 根据环境显示错误
    $appEnv = getenv('APP_ENV') ?: 'production';
    $appDebug = getenv('APP_DEBUG') === 'true';
    
    if ($appEnv === 'local' || $appDebug) {
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
});

// 注册关闭函数
register_shutdown_function(function() {
    $error = error_get_last();
    
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // 致命错误处理
        $errorMessage = sprintf(
            "致命错误: %s\n文件: %s (第 %d 行)",
            $error['message'],
            $error['file'],
            $error['line']
        );
        
        error_log($errorMessage);
        
        // 根据环境显示错误
        $appEnv = getenv('APP_ENV') ?: 'production';
        $appDebug = getenv('APP_DEBUG') === 'true';
        
        if ($appEnv === 'local' || $appDebug) {
            echo '<h1>致命错误</h1>';
            echo '<p>' . htmlspecialchars($error['message']) . '</p>';
            echo '<p>文件: ' . htmlspecialchars($error['file']) . ' (第 ' . $error['line'] . ' 行)</p>';
        } else {
            echo '<h1>系统错误</h1>';
            echo '<p>抱歉，系统发生严重错误。请联系系统管理员。</p>';
        }
    }
});

// 仅在直接执行 bootstrap.php 时自动运行应用。
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        // 加载配置
        $config = require_once CONFIG_PATH . '/app.php';

        // 创建应用实例
        $app = \App\Core\Application::getInstance();

        // 初始化应用
        $app->init($config);

        // 注册默认路由
        $app->getRouter()->registerDefaultRoutes();

        // 运行应用
        $app->run();
    } catch (Exception $e) {
        // 应用启动失败
        $errorMessage = sprintf(
            "应用启动失败: %s\n文件: %s (第 %d 行)\n堆栈跟踪:\n%s",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        error_log($errorMessage);

        http_response_code(500);
        echo '<!DOCTYPE html>';
        echo '<html lang="zh-CN">';
        echo '<head><title>应用启动失败</title>';
        echo '<style>';
        echo 'body { font-family: Arial, sans-serif; padding: 20px; background: #f8f9fa; }';
        echo '.error-box { background: white; border: 1px solid #dc3545; border-radius: 5px; padding: 20px; margin: 20px 0; }';
        echo '.error-title { color: #dc3545; margin-top: 0; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="error-box">';
        echo '<h1 class="error-title">应用启动失败</h1>';
        echo '<p>收租管理系统无法启动。请检查以下问题：</p>';
        echo '<ol>';
        echo '<li>确保数据库配置正确</li>';
        echo '<li>确保必要的PHP扩展已安装</li>';
        echo '<li>检查文件权限</li>';
        echo '<li>查看日志文件获取更多信息</li>';
        echo '</ol>';
        echo '<p><strong>错误信息:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</div>';
        echo '</body>';
        echo '</html>';

        exit(1);
    }
}