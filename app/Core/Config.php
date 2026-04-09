<?php
/**
 * 收租管理系统 - 配置管理类
 * 
 * 负责加载和管理应用配置
 */

namespace App\Core;

class Config
{
    /**
     * @var array 配置数组
     */
    private $config = [];
    
    /**
     * 构造函数
     * 
     * @param array $config 初始配置数组
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        
        // 加载默认配置
        $this->loadDefaultConfig();
        
        // 加载环境配置
        $this->loadEnvironmentConfig();
    }
    
    /**
     * 加载默认配置
     * 
     * @return void
     */
    private function loadDefaultConfig(): void
    {
        $defaultConfig = [
            'app' => [
                'name' => '收租管理系统',
                'version' => '1.0.0',
                'debug' => true,
                'timezone' => 'Asia/Shanghai',
                'locale' => 'zh_CN',
                'url' => 'http://localhost',
                'asset_url' => '/assets',
                'csrf_protection' => true,
                'maintenance_mode' => false
            ],
            
            'database' => [
                'driver' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'easy_rent',
                'username' => '',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'options' => [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]
            ],
            
            'session' => [
                'name' => 'easyrent_session',
                'lifetime' => 7200, // 2小时
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax'
            ],
            
            'auth' => [
                'guard' => 'session',
                'providers' => [
                    'users' => [
                        'driver' => 'eloquent',
                        'model' => 'App\Models\User'
                    ]
                ],
                'passwords' => [
                    'users' => [
                        'provider' => 'users',
                        'table' => 'password_resets',
                        'expire' => 60,
                        'throttle' => 60
                    ]
                ]
            ],
            
            'mail' => [
                'driver' => 'smtp',
                'host' => 'smtp.mailtrap.io',
                'port' => 2525,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from' => [
                    'address' => 'noreply@easyrent.local',
                    'name' => '收租管理系统'
                ]
            ],
            
            'logging' => [
                'default' => 'single',
                'channels' => [
                    'single' => [
                        'driver' => 'single',
                        'path' => __DIR__ . '/../../storage/logs/app.log',
                        'level' => 'debug'
                    ],
                    'daily' => [
                        'driver' => 'daily',
                        'path' => __DIR__ . '/../../storage/logs/app.log',
                        'level' => 'debug',
                        'days' => 14
                    ]
                ]
            ],
            
            'cache' => [
                'default' => 'file',
                'stores' => [
                    'file' => [
                        'driver' => 'file',
                        'path' => __DIR__ . '/../../storage/cache'
                    ],
                    'array' => [
                        'driver' => 'array',
                        'serialize' => false
                    ]
                ]
            ],
            
            'filesystems' => [
                'default' => 'local',
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => __DIR__ . '/../../storage/app'
                    ],
                    'public' => [
                        'driver' => 'local',
                        'root' => __DIR__ . '/../../storage/app/public',
                        'url' => '/storage',
                        'visibility' => 'public'
                    ]
                ]
            ],
            
            'view' => [
                'paths' => [
                    __DIR__ . '/../Views'
                ],
                'compiled' => __DIR__ . '/../../storage/framework/views'
            ]
        ];
        
        // 合并默认配置
        $this->config = array_replace_recursive($defaultConfig, $this->config);
    }
    
    /**
     * 加载环境配置
     * 
     * @return void
     */
    private function loadEnvironmentConfig(): void
    {
        // 环境配置文件路径
        $envFile = __DIR__ . '/../../.env';
        
        if (file_exists($envFile)) {
            $envConfig = $this->parseEnvFile($envFile);
            $this->config = array_replace_recursive($this->config, $envConfig);
        }
    }
    
    /**
     * 解析环境配置文件
     * 
     * @param string $filePath 文件路径
     * @return array 解析后的配置数组
     */
    private function parseEnvFile(string $filePath): array
    {
        $config = [];
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // 跳过注释
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // 解析键值对
            $pos = strpos($line, '=');
            if ($pos !== false) {
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                
                // 移除引号（仅在值非空时）
                if (!empty($value)) {
                    if (($value[0] === '"' && substr($value, -1) === '"') ||
                        ($value[0] === "'" && substr($value, -1) === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }
                
                // 转换为数组格式
                $keys = explode('.', $key);
                $current = &$config;
                
                foreach ($keys as $k) {
                    if (!isset($current[$k])) {
                        $current[$k] = [];
                    }
                    $current = &$current[$k];
                }
                
                $current = $value;
            }
        }
        
        return $config;
    }
    
    /**
     * 获取配置值
     * 
     * @param string $key 配置键（支持点语法）
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * 设置配置值
     * 
     * @param string $key 配置键（支持点语法）
     * @param mixed $value 配置值
     * @return void
     */
    public function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;
        
        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }
    }
    
    /**
     * 检查配置是否存在
     * 
     * @param string $key 配置键（支持点语法）
     * @return bool 是否存在
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return false;
            }
            $value = $value[$k];
        }
        
        return true;
    }
    
    /**
     * 获取所有配置
     * 
     * @return array 所有配置
     */
    public function all(): array
    {
        return $this->config;
    }
    
    /**
     * 从文件加载配置
     * 
     * @param string $file 配置文件路径
     * @return void
     * @throws \Exception 文件不存在或无法读取时抛出异常
     */
    public function loadFromFile(string $file): void
    {
        if (!file_exists($file)) {
            throw new \Exception("配置文件不存在: {$file}");
        }
        
        $config = require $file;
        
        if (!is_array($config)) {
            throw new \Exception("配置文件必须返回数组: {$file}");
        }
        
        $this->config = array_replace_recursive($this->config, $config);
    }
    
    /**
     * 保存配置到文件
     * 
     * @param string $file 文件路径
     * @return bool 是否保存成功
     */
    public function saveToFile(string $file): bool
    {
        $content = "<?php\n\nreturn " . var_export($this->config, true) . ";\n";
        
        // 确保目录存在
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return file_put_contents($file, $content) !== false;
    }
    
    /**
     * 获取环境变量
     * 
     * @param string $key 环境变量键
     * @param mixed $default 默认值
     * @return mixed 环境变量值
     */
    public function env(string $key, $default = null)
    {
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        // 转换布尔值
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }
        
        // 转换数字
        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return (float) $value;
            }
            return (int) $value;
        }
        
        return $value;
    }
    
    /**
     * 获取应用环境
     * 
     * @return string 环境名称
     */
    public function environment(): string
    {
        return $this->env('APP_ENV', 'production');
    }
    
    /**
     * 检查是否为指定环境
     * 
     * @param string|array $environments 环境名称或数组
     * @return bool 是否为指定环境
     */
    public function isEnvironment($environments): bool
    {
        $current = $this->environment();
        
        if (is_array($environments)) {
            return in_array($current, $environments);
        }
        
        return $current === $environments;
    }
    
    /**
     * 检查是否为本地环境
     * 
     * @return bool 是否为本地环境
     */
    public function isLocal(): bool
    {
        return $this->isEnvironment('local');
    }
    
    /**
     * 检查是否为开发环境
     * 
     * @return bool 是否为开发环境
     */
    public function isDevelopment(): bool
    {
        return $this->isEnvironment(['local', 'development', 'dev']);
    }
    
    /**
     * 检查是否为生产环境
     * 
     * @return bool 是否为生产环境
     */
    public function isProduction(): bool
    {
        return $this->isEnvironment('production');
    }
}