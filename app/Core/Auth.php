<?php
/**
 * 收租管理系统 - 认证管理类
 * 
 * 负责用户认证、授权和权限管理
 */

namespace App\Core;

class Auth
{
    /**
     * @var Session 会话实例
     */
    private $session;
    
    /**
     * @var Database 数据库实例
     */
    private $database;
    
    /**
     * @var array 当前用户数据
     */
    private $user = null;
    
    /**
     * @var bool 用户是否已加载
     */
    private $loaded = false;
    
    /**
     * @var array 权限配置
     */
    private $permissions = [
        'admin' => [
            'user.view',
            'user.create',
            'user.edit',
            'user.delete',
            'property.view',
            'property.create',
            'property.edit',
            'property.delete',
            'contract.view',
            'contract.create',
            'contract.edit',
            'contract.delete',
            'payment.view',
            'payment.create',
            'payment.edit',
            'payment.delete',
            'financial.view',
            'financial.create',
            'financial.edit',
            'financial.delete',
            'report.view',
            'report.generate',
            'system.settings',
            'system.backup'
        ],
        'landlord' => [
            'property.view.own',
            'property.create.own',
            'property.edit.own',
            'property.delete.own',
            'contract.view.own',
            'contract.create.own',
            'contract.edit.own',
            'contract.delete.own',
            'payment.view.own',
            'payment.create.own',
            'payment.edit.own',
            'financial.view.own',
            'report.view.own'
        ]
    ];
    
    /**
     * 构造函数
     * 
     * @param Session $session 会话实例
     * @param Database $database 数据库实例
     */
    public function __construct(Session $session, Database $database)
    {
        $this->session = $session;
        $this->database = $database;
    }
    
    /**
     * 用户登录
     * 
     * @param string $username 用户名
     * @param string $password 密码
     * @param bool $remember 是否记住登录
     * @return bool 是否登录成功
     */
    public function login(string $username, string $password, bool $remember = false): bool
    {
        // 获取用户
        $user = $this->getUserByUsername($username);
        
        if (!$user) {
            $this->logFailedAttempt($username);
            return false;
        }
        
        // 检查账户状态
        if ($user['status'] !== 'active') {
            $this->logFailedAttempt($username);
            return false;
        }
        
        // 检查账户是否被锁定
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return false;
        }
        
        // 验证密码
        if (!password_verify($password, $user['password_hash'])) {
            $this->logFailedAttempt($username, $user['id']);
            return false;
        }
        
        // 重置失败尝试次数
        $this->resetFailedAttempts($user['id']);
        
        // 设置会话
        $this->session->set('user_id', $user['id']);
        $this->session->set('user_role', $user['role']);
        $this->session->set('user_logged_in', true);
        $this->session->updateLastActivity();
        
        // 更新最后登录信息
        $this->updateLastLogin($user['id']);
        
        // 记住我功能
        if ($remember) {
            $this->setRememberToken($user['id']);
        }
        
        // 清除用户缓存
        $this->user = null;
        $this->loaded = false;
        
        return true;
    }
    
    /**
     * 用户登出
     * 
     * @return void
     */
    public function logout(): void
    {
        // 清除会话数据
        $this->session->remove('user_id');
        $this->session->remove('user_role');
        $this->session->remove('user_logged_in');
        
        // 清除记住我令牌
        if ($this->check()) {
            $this->clearRememberToken($this->user()['id']);
        }
        
        // 清除用户缓存
        $this->user = null;
        $this->loaded = false;
        
        // 销毁会话
        $this->session->destroy();
    }
    
    /**
     * 检查用户是否已登录
     * 
     * @return bool 是否已登录
     */
    public function check(): bool
    {
        if (!$this->session->get('user_logged_in', false)) {
            // 检查记住我令牌
            if ($this->attemptRememberLogin()) {
                return true;
            }
            return false;
        }
        
        // 检查会话是否过期
        if ($this->session->isExpired()) {
            $this->logout();
            return false;
        }
        
        // 更新最后活动时间
        $this->session->updateLastActivity();
        
        return true;
    }
    
    /**
     * 获取当前用户
     * 
     * @return array|null 用户数据或null
     */
    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }
        
        if (!$this->loaded) {
            $userId = $this->session->get('user_id');
            $this->user = $this->getUserById($userId);
            $this->loaded = true;
        }
        
        return $this->user;
    }
    
    /**
     * 获取当前用户ID
     * 
     * @return int|null 用户ID或null
     */
    public function id(): ?int
    {
        return $this->session->get('user_id');
    }
    
    /**
     * 获取当前用户角色
     * 
     * @return string|null 用户角色或null
     */
    public function role(): ?string
    {
        return $this->session->get('user_role');
    }
    
    /**
     * 检查用户是否有权限
     * 
     * @param string $permission 权限标识
     * @param mixed $resource 资源（可选）
     * @return bool 是否有权限
     */
    public function can(string $permission, $resource = null): bool
    {
        if (!$this->check()) {
            return false;
        }
        
        $role = $this->role();
        
        if (!$role) {
            return false;
        }
        
        // 检查角色权限
        if (!isset($this->permissions[$role])) {
            return false;
        }
        
        // 检查通用权限
        if (in_array($permission, $this->permissions[$role])) {
            return true;
        }
        
        // 检查资源特定权限
        if ($resource !== null) {
            // 检查所有权
            if (strpos($permission, '.own') !== false) {
                $basePermission = str_replace('.own', '', $permission);
                if (in_array($basePermission . '.own', $this->permissions[$role])) {
                    return $this->owns($resource, $basePermission);
                }
            }
        }
        
        return false;
    }
    
    /**
     * 检查用户是否拥有资源
     * 
     * @param mixed $resource 资源
     * @param string $type 资源类型
     * @return bool 是否拥有
     */
    public function owns($resource, string $type = 'property'): bool
    {
        if (!$this->check()) {
            return false;
        }
        
        $userId = $this->id();
        
        if (is_array($resource)) {
            // 资源是数组，检查owner_id字段
            return isset($resource['owner_id']) && $resource['owner_id'] == $userId;
        } elseif (is_numeric($resource)) {
            // 资源是ID，从数据库查询
            switch ($type) {
                case 'property':
                    $property = $this->database->fetch(
                        "SELECT owner_id FROM properties WHERE id = ?",
                        [$resource]
                    );
                    return $property && $property['owner_id'] == $userId;
                    
                case 'contract':
                    $contract = $this->database->fetch(
                        "SELECT p.owner_id FROM contracts c 
                         JOIN properties p ON c.property_id = p.id 
                         WHERE c.id = ?",
                        [$resource]
                    );
                    return $contract && $contract['owner_id'] == $userId;
                    
                default:
                    return false;
            }
        }
        
        return false;
    }
    
    /**
     * 检查用户是否是管理员
     * 
     * @return bool 是否是管理员
     */
    public function isAdmin(): bool
    {
        return $this->role() === 'admin';
    }
    
    /**
     * 检查用户是否是房东
     * 
     * @return bool 是否是房东
     */
    public function isLandlord(): bool
    {
        return $this->role() === 'landlord';
    }

    /**
     * 检查用户是否是租客
     *
     * @return bool 是否是租客
     */
    public function isTenant(): bool
    {
        return $this->role() === 'tenant';
    }
    
    /**
     * 注册新用户
     * 
     * @param array $data 用户数据
     * @return int 用户ID
     * @throws \Exception 注册失败时抛出异常
     */
    public function register(array $data): int
    {
        // 验证必填字段
        $required = ['username', 'email', 'password', 'real_name', 'role'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \Exception("字段 {$field} 为必填项");
            }
        }
        
        // 验证用户名唯一性
        if ($this->getUserByUsername($data['username'])) {
            throw new \Exception("用户名已存在");
        }
        
        // 验证邮箱唯一性
        if ($this->getUserByEmail($data['email'])) {
            throw new \Exception("邮箱已存在");
        }
        
        // 验证角色
        if (!in_array($data['role'], ['admin', 'landlord'])) {
            throw new \Exception("无效的用户角色");
        }
        
        // 哈希密码
        $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        unset($data['password']);
        
        // 设置默认值
        $data['status'] = $data['status'] ?? 'active';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        // 插入用户
        $userId = $this->database->insert('users', $data);
        
        if (!$userId) {
            throw new \Exception("用户注册失败");
        }
        
        return $userId;
    }
    
    /**
     * 更新用户信息
     * 
     * @param int $userId 用户ID
     * @param array $data 更新数据
     * @return bool 是否成功
     */
    public function updateUser(int $userId, array $data): bool
    {
        // 如果包含密码，进行哈希
        if (isset($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        return $this->database->update('users', $data, ['id' => $userId]) > 0;
    }
    
    /**
     * 删除用户
     * 
     * @param int $userId 用户ID
     * @return bool 是否成功
     */
    public function deleteUser(int $userId): bool
    {
        // 不能删除自己
        if ($userId === $this->id()) {
            return false;
        }
        
        return $this->database->delete('users', ['id' => $userId]) > 0;
    }
    
    /**
     * 通过用户名获取用户
     * 
     * @param string $username 用户名
     * @return array|null 用户数据或null
     */
    private function getUserByUsername(string $username): ?array
    {
        return $this->database->fetch(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );
    }
    
    /**
     * 通过邮箱获取用户
     * 
     * @param string $email 邮箱
     * @return array|null 用户数据或null
     */
    private function getUserByEmail(string $email): ?array
    {
        return $this->database->fetch(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
    }
    
    /**
     * 通过ID获取用户
     * 
     * @param int $userId 用户ID
     * @return array|null 用户数据或null
     */
    private function getUserById(int $userId): ?array
    {
        return $this->database->fetch(
            "SELECT id, username, email, real_name, phone, role, avatar, status, 
                    last_login_at, last_login_ip, created_at, updated_at 
             FROM users WHERE id = ?",
            [$userId]
        );
    }
    
    /**
     * 记录失败登录尝试
     * 
     * @param string $username 用户名
     * @param int|null $userId 用户ID（如果已知）
     * @return void
     */
    private function logFailedAttempt(string $username, ?int $userId = null): void
    {
        // 如果用户ID未知，尝试查找
        if (!$userId) {
            $user = $this->getUserByUsername($username);
            $userId = $user['id'] ?? null;
        }
        
        if ($userId) {
            // 更新失败尝试次数
            $this->database->execute(
                "UPDATE users SET 
                 failed_login_attempts = failed_login_attempts + 1,
                 updated_at = NOW()
                 WHERE id = ?",
                [$userId]
            );
            
            // 检查是否需要锁定账户
            $user = $this->getUserById($userId);
            if ($user && $user['failed_login_attempts'] >= 5) {
                $lockUntil = date('Y-m-d H:i:s', time() + 1800); // 锁定30分钟
                $this->database->execute(
                    "UPDATE users SET 
                     locked_until = ?,
                     updated_at = NOW()
                     WHERE id = ?",
                    [$lockUntil, $userId]
                );
            }
        }
        
        // 记录审计日志
        $this->logAudit('login_failed', 'users', $userId, [
            'username' => $username,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    }
    
    /**
     * 重置失败登录尝试
     * 
     * @param int $userId 用户ID
     * @return void
     */
    private function resetFailedAttempts(int $userId): void
    {
        $this->database->execute(
            "UPDATE users SET 
             failed_login_attempts = 0,
             locked_until = NULL,
             updated_at = NOW()
             WHERE id = ?",
            [$userId]
        );
    }
    
    /**
     * 更新最后登录信息
     * 
     * @param int $userId 用户ID
     * @return void
     */
    private function updateLastLogin(int $userId): void
    {
        $this->database->execute(
            "UPDATE users SET 
             last_login_at = NOW(),
             last_login_ip = ?,
             updated_at = NOW()
             WHERE id = ?",
            [$_SERVER['REMOTE_ADDR'] ?? 'unknown', $userId]
        );
    }
    
    /**
     * 设置记住我令牌
     * 
     * @param int $userId 用户ID
     * @return void
     */
    private function setRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 2592000); // 30天
        
        // 在实际应用中，应该将令牌存储在数据库或缓存中
        // 这里简化实现，使用cookie
        setcookie('remember_token', $token, time() + 2592000, '/', '', false, true);
        
        // 记录审计日志
        $this->logAudit('remember_token_set', 'users', $userId);
    }
    
    /**
     * 清除记住我令牌
     * 
     * @param int $userId 用户ID
     * @return void
     */
    private function clearRememberToken(int $userId): void
    {
        // 清除cookie
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        
        // 记录审计日志
        $this->logAudit('remember_token_cleared', 'users', $userId);
    }
    
    /**
     * 尝试记住我登录
     * 
     * @return bool 是否登录成功
     */
    private function attemptRememberLogin(): bool
    {
        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }
        
        $token = $_COOKIE['remember_token'];
        
        // 在实际应用中，应该从数据库验证令牌
        // 这里简化实现，直接返回false
        return false;
    }
    
    /**
     * 记录审计日志
     *
     * @param string $action 操作
     * @param string $tableName 表名
     * @param int|null $recordId 记录ID
     * @param array $extraData 额外数据
     * @return void
     */
    private function logAudit(string $action, string $tableName, ?int $recordId = null, array $extraData = []): void
    {
        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $this->database->insert('audit_logs', [
                'user_id' => $this->id(),
                'action' => $action,
                'table_name' => $tableName,
                'record_id' => $recordId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // 如果有额外数据，可以记录到其他表或日志文件
            if (!empty($extraData)) {
                error_log("审计日志额外数据: " . json_encode($extraData));
            }
        } catch (\Exception $e) {
            // 审计日志失败不应影响主要功能
            error_log("审计日志记录失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取用户列表
     *
     * @param array $filters 过滤条件
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @return array 用户列表和分页信息
     */
    public function getUsers(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];
        
        // 构建查询条件
        if (!empty($filters['role'])) {
            $where[] = "role = ?";
            $params[] = $filters['role'];
        }
        
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(username LIKE ? OR email LIKE ? OR real_name LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // 构建WHERE子句
        $whereClause = '';
        if (!empty($where)) {
            $whereClause = 'WHERE ' . implode(' AND ', $where);
        }
        
        // 计算总数
        $total = $this->database->fetchColumn(
            "SELECT COUNT(*) FROM users {$whereClause}",
            $params
        );
        
        // 计算分页
        $offset = ($page - 1) * $perPage;
        
        // 获取用户列表
        $users = $this->database->fetchAll(
            "SELECT id, username, email, real_name, phone, role, avatar, status,
                    last_login_at, created_at, updated_at
             FROM users {$whereClause}
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
        
        return [
            'users' => $users,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ]
        ];
    }
    
    /**
     * 验证密码强度
     *
     * @param string $password 密码
     * @return array 验证结果
     */
    public function validatePasswordStrength(string $password): array
    {
        $errors = [];
        
        // 最小长度
        if (strlen($password) < 8) {
            $errors[] = '密码至少需要8个字符';
        }
        
        // 需要大写字母
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = '密码需要至少一个大写字母';
        }
        
        // 需要小写字母
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = '密码需要至少一个小写字母';
        }
        
        // 需要数字
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = '密码需要至少一个数字';
        }
        
        // 需要特殊字符
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = '密码需要至少一个特殊字符';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * 生成随机密码
     *
     * @param int $length 密码长度
     * @return string 随机密码
     */
    public function generateRandomPassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
    
    /**
     * 发送密码重置邮件
     *
     * @param string $email 邮箱
     * @return bool 是否发送成功
     */
    public function sendPasswordResetEmail(string $email): bool
    {
        // 在实际应用中，这里应该发送真正的邮件
        // 这里简化实现，只记录日志
        error_log("密码重置邮件已发送到: {$email}");
        
        return true;
    }
    
    /**
     * 验证密码重置令牌
     *
     * @param string $token 令牌
     * @return bool 是否有效
     */
    public function validatePasswordResetToken(string $token): bool
    {
        // 在实际应用中，这里应该验证令牌的有效期和存在性
        // 这里简化实现，假设令牌有效
        return !empty($token);
    }
    
    /**
     * 重置密码
     *
     * @param string $token 令牌
     * @param string $newPassword 新密码
     * @return bool 是否成功
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        // 在实际应用中，这里应该根据令牌找到用户并更新密码
        // 这里简化实现，直接返回true
        return true;
    }
}
