<?php
/**
 * 收租管理系统 - 数据库配置文件
 */

return [
    /*
    |--------------------------------------------------------------------------
    | 默认数据库连接
    |--------------------------------------------------------------------------
    |
    | 这里指定了应用程序将使用的默认数据库连接。
    | 当然，您可以根据需要使用多个连接。
    |
    */
    'default' => env('DB_CONNECTION', 'mysql'),
    
    /*
    |--------------------------------------------------------------------------
    | 数据库连接
    |--------------------------------------------------------------------------
    |
    | 这里是所有数据库连接的配置。这些配置的示例已给出，
    | 但您可以根据需要添加其他连接。
    |
    | 所有数据库工作在Laravel中都使用PDO，因此您应该知道
    | 驱动程序特定的PDO选项。
    |
    */
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'easy_rent'),
            'username' => env('DB_USERNAME', 'xmtdk'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
        
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
        
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
        
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 迁移存储表
    |--------------------------------------------------------------------------
    |
    | 此表跟踪已运行的所有迁移。使用此信息，我们可以确定
    | 哪些迁移尚未运行。
    |
    */
    'migrations' => 'migrations',
    
    /*
    |--------------------------------------------------------------------------
    | Redis数据库
    |--------------------------------------------------------------------------
    |
    | Redis是一个开源的、快速的、高级的键值存储，也提供了
    | 比典型键值系统更丰富的命令集，例如PHP。
    |
    */
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'easyrent_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 数据库连接池配置
    |--------------------------------------------------------------------------
    |
    | 数据库连接池的相关配置。
    |
    */
    'pool' => [
        'enabled' => true,
        'max_connections' => 20,
        'min_connections' => 5,
        'max_idle_time' => 300, // 5分钟
        'validation_interval' => 60, // 1分钟
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 查询日志配置
    |--------------------------------------------------------------------------
    |
    | 查询日志的相关配置。
    |
    */
    'query_logging' => [
        'enabled' => env('APP_DEBUG', false),
        'slow_query_threshold' => 1000, // 毫秒
        'log_channel' => 'daily',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 数据库备份配置
    |--------------------------------------------------------------------------
    |
    | 数据库备份的相关配置。
    |
    */
    'backup' => [
        'enabled' => true,
        'schedule' => 'daily', // daily, weekly, monthly
        'retention_days' => 30,
        'storage_path' => STORAGE_PATH . '/backups',
        'compress' => true,
        'encrypt' => false,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 数据库维护配置
    |--------------------------------------------------------------------------
    |
    | 数据库维护的相关配置。
    |
    */
    'maintenance' => [
        'optimize_tables' => true,
        'analyze_tables' => true,
        'check_tables' => true,
        'repair_tables' => true,
        'schedule' => 'weekly', // daily, weekly, monthly
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 数据库性能配置
    |--------------------------------------------------------------------------
    |
    | 数据库性能优化的相关配置。
    |
    */
    'performance' => [
        'query_cache' => true,
        'result_cache_ttl' => 300, // 5分钟
        'prepared_statements' => true,
        'persistent_connections' => false,
        'fetch_mode' => PDO::FETCH_ASSOC,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 数据库安全配置
    |--------------------------------------------------------------------------
    |
    | 数据库安全的相关配置。
    |
    */
    'security' => [
        'sql_injection_protection' => true,
        'parameter_binding' => true,
        'escape_identifiers' => true,
        'limit_queries' => true,
        'max_query_size' => 65536, // 64KB
        'audit_logging' => true,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | 数据库监控配置
    |--------------------------------------------------------------------------
    |
    | 数据库监控的相关配置。
    |
    */
    'monitoring' => [
        'enabled' => true,
        'metrics' => [
            'connection_count',
            'query_count',
            'slow_queries',
            'error_count',
            'uptime',
        ],
        'alert_thresholds' => [
            'max_connections' => 100,
            'max_slow_queries' => 10,
            'max_errors' => 5,
        ],
    ],
];