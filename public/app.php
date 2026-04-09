<?php
/**
 * 收租管理系统 - 应用入口点
 * 
 * 所有请求都通过此文件路由
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 检查常量是否已定义
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// 加载启动文件
require_once APP_ROOT . '/bootstrap.php';

// 创建应用实例
$app = App\Core\Application::getInstance();

// 加载应用配置并初始化（Day 1: 确保核心服务和路由先就绪）
$config = [];
$appConfigFile = APP_ROOT . '/app/Config/app.php';
$dbConfigFile = APP_ROOT . '/app/Config/database.php';

if (file_exists($appConfigFile)) {
    $config = require $appConfigFile;
}

if (file_exists($dbConfigFile)) {
    $databaseConfig = require $dbConfigFile;
    if (isset($databaseConfig['connections']['mysql']) && is_array($databaseConfig['connections']['mysql'])) {
        $mysql = $databaseConfig['connections']['mysql'];
        $config['database'] = [
            'driver' => $mysql['driver'] ?? 'mysql',
            'host' => $mysql['host'] ?? '127.0.0.1',
            'port' => (int) ($mysql['port'] ?? 3306),
            'database' => $mysql['database'] ?? 'easy_rent',
            'username' => $mysql['username'] ?? '',
            'password' => $mysql['password'] ?? '',
            'charset' => $mysql['charset'] ?? 'utf8mb4',
            'collation' => $mysql['collation'] ?? 'utf8mb4_unicode_ci',
        ];
    }
}

$app->init($config);

// 运行应用
$app->run();