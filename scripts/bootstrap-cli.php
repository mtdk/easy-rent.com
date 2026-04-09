<?php

declare(strict_types=1);

/**
 * CLI 脚本公共引导
 *
 * 作用：在命令行环境注册 application/database/session/auth 等核心服务，
 * 使 db()/auth()/session() 辅助函数可用。
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/bootstrap.php';

$app = App\Core\Application::getInstance();

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
