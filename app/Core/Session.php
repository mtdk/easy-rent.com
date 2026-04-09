<?php
/**
 * 收租管理系统 - 会话管理类
 * 
 * 负责会话的启动、管理和销毁
 */

namespace App\Core;

class Session
{
    /**
     * @var bool 会话是否已启动
     */
    private $started = false;
    
    /**
     * @var array 会话配置
     */
    private $config;
    
    /**
     * 构造函数
     * 
     * @param array $config 会话配置
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'name' => 'easyrent_session',
            'lifetime' => 7200, // 2小时
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ], $config);
    }
    
    /**
     * 启动会话
     * 
     * @return bool 是否成功启动
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }
        
        // 设置会话配置
        session_name($this->config['name']);
        session_set_cookie_params([
            'lifetime' => $this->config['lifetime'],
            'path' => $this->config['path'],
            'domain' => $this->config['domain'],
            'secure' => $this->config['secure'],
            'httponly' => $this->config['httponly'],
            'samesite' => $this->config['samesite']
        ]);
        
        // 启动会话
        if (session_status() === PHP_SESSION_NONE) {
            $this->started = session_start();
            
            if ($this->started) {
                // 初始化会话数组
                if (!isset($_SESSION['_flash'])) {
                    $_SESSION['_flash'] = [];
                }
                if (!isset($_SESSION['_old_input'])) {
                    $_SESSION['_old_input'] = [];
                }
                if (!isset($_SESSION['_token'])) {
                    $_SESSION['_token'] = bin2hex(random_bytes(32));
                }
            }
        } else {
            $this->started = true;
        }
        
        return $this->started;
    }
    
    /**
     * 获取会话值
     * 
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed 会话值
     */
    public function get(string $key, $default = null)
    {
        $this->ensureStarted();
        
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * 设置会话值
     * 
     * @param string $key 键名
     * @param mixed $value 值
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->ensureStarted();
        
        $_SESSION[$key] = $value;
    }
    
    /**
     * 检查会话键是否存在
     * 
     * @param string $key 键名
     * @return bool 是否存在
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        
        return isset($_SESSION[$key]);
    }
    
    /**
     * 删除会话值
     * 
     * @param string $key 键名
     * @return void
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();
        
        unset($_SESSION[$key]);
    }
    
    /**
     * 获取所有会话数据
     * 
     * @return array 所有会话数据
     */
    public function all(): array
    {
        $this->ensureStarted();
        
        return $_SESSION;
    }
    
    /**
     * 清空会话数据
     * 
     * @return void
     */
    public function flush(): void
    {
        $this->ensureStarted();
        
        $_SESSION = [];
    }
    
    /**
     * 设置闪存数据（下次请求后清除）
     * 
     * @param string $key 键名
     * @param mixed $value 值
     * @return void
     */
    public function flash(string $key, $value): void
    {
        $this->ensureStarted();
        
        $_SESSION['_flash'][$key] = $value;
    }
    
    /**
     * 检查是否有闪存数据
     * 
     * @param string $key 键名
     * @return bool 是否有闪存数据
     */
    public function hasFlash(string $key): bool
    {
        $this->ensureStarted();
        
        return isset($_SESSION['_flash'][$key]);
    }
    
    /**
     * 获取闪存数据
     * 
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed 闪存数据
     */
    public function getFlash(string $key, $default = null)
    {
        $this->ensureStarted();
        
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        
        return $value;
    }
    
    /**
     * 设置旧输入数据
     * 
     * @param array $input 输入数据
     * @return void
     */
    public function flashInput(array $input): void
    {
        $this->ensureStarted();
        
        $_SESSION['_old_input'] = $input;
    }
    
    /**
     * 获取旧输入数据
     * 
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed 旧输入数据
     */
    public function old(string $key, $default = null)
    {
        $this->ensureStarted();
        
        return $_SESSION['_old_input'][$key] ?? $default;
    }
    
    /**
     * 重新生成会话ID
     * 
     * @param bool $deleteOldSession 是否删除旧会话
     * @return bool 是否成功
     */
    public function regenerate(bool $deleteOldSession = false): bool
    {
        $this->ensureStarted();
        
        return session_regenerate_id($deleteOldSession);
    }
    
    /**
     * 销毁会话
     * 
     * @return bool 是否成功
     */
    public function destroy(): bool
    {
        $this->ensureStarted();
        
        // 清除会话数据
        $_SESSION = [];
        
        // 销毁会话cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        
        // 销毁会话
        $this->started = false;
        return session_destroy();
    }
    
    /**
     * 获取会话ID
     * 
     * @return string 会话ID
     */
    public function getId(): string
    {
        $this->ensureStarted();
        
        return session_id();
    }
    
    /**
     * 设置会话ID
     * 
     * @param string $id 会话ID
     * @return void
     */
    public function setId(string $id): void
    {
        if ($this->started) {
            throw new \RuntimeException('无法在会话启动后设置会话ID');
        }
        
        session_id($id);
    }
    
    /**
     * 获取会话名称
     * 
     * @return string 会话名称
     */
    public function getName(): string
    {
        return session_name();
    }
    
    /**
     * 设置会话名称
     * 
     * @param string $name 会话名称
     * @return void
     */
    public function setName(string $name): void
    {
        if ($this->started) {
            throw new \RuntimeException('无法在会话启动后设置会话名称');
        }
        
        session_name($name);
    }
    
    /**
     * 确保会话已启动
     * 
     * @return void
     * @throws \RuntimeException 会话启动失败时抛出异常
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            if (!$this->start()) {
                throw new \RuntimeException('无法启动会话');
            }
        }
    }
    
    /**
     * 保存会话数据
     * 
     * @return void
     */
    public function save(): void
    {
        if ($this->started) {
            session_write_close();
            $this->started = false;
        }
    }
    
    /**
     * 获取CSRF令牌
     * 
     * @return string CSRF令牌
     */
    public function token(): string
    {
        return $this->get('_token', '');
    }
    
    /**
     * 验证CSRF令牌
     * 
     * @param string $token 要验证的令牌
     * @return bool 是否有效
     */
    public function validateToken(string $token): bool
    {
        return hash_equals($this->token(), $token);
    }
    
    /**
     * 获取会话生存时间
     * 
     * @return int 剩余生存时间（秒）
     */
    public function getTimeToLive(): int
    {
        $this->ensureStarted();
        
        return $this->config['lifetime'] - (time() - $_SESSION['_last_activity'] ?? time());
    }
    
    /**
     * 更新最后活动时间
     * 
     * @return void
     */
    public function updateLastActivity(): void
    {
        $this->set('_last_activity', time());
    }
    
    /**
     * 检查会话是否过期
     * 
     * @return bool 是否过期
     */
    public function isExpired(): bool
    {
        $lastActivity = $this->get('_last_activity', 0);
        return (time() - $lastActivity) > $this->config['lifetime'];
    }
    
    /**
     * 析构函数
     */
    public function __destruct()
    {
        if ($this->started) {
            $this->save();
        }
    }
}