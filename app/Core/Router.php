<?php
/**
 * 收租管理系统 - 路由管理类
 * 
 * 负责URL路由解析和请求分发
 */

namespace App\Core;

class Router
{
    /**
     * @var array 路由表
     */
    private $routes = [];
    
    /**
     * @var array 命名路由
     */
    private $namedRoutes = [];
    
    /**
     * @var string 当前请求方法
     */
    private $method;
    
    /**
     * @var string 当前请求路径
     */
    private $path;
    
    /**
     * @var array 路由参数
     */
    private $params = [];
    
    /**
     * @var array 路由组前缀
     */
    private $groupPrefix = '';
    
    /**
     * @var array 路由组中间件
     */
    private $groupMiddleware = [];
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // 支持HTML表单通过 _method 覆盖HTTP方法（如 PUT/DELETE）
        if ($this->method === 'POST' && isset($_POST['_method'])) {
            $overrideMethod = strtoupper((string) $_POST['_method']);
            if (in_array($overrideMethod, ['PUT', 'PATCH', 'DELETE'], true)) {
                $this->method = $overrideMethod;
            }
        }

        $this->path = $this->getCurrentPath();
    }
    
    /**
     * 添加GET路由
     * 
     * @param string $pattern 路由模式
     * @param mixed $handler 处理程序
     * @param string|null $name 路由名称
     * @return void
     */
    public function get(string $pattern, $handler, ?string $name = null): void
    {
        $this->addRoute('GET', $pattern, $handler, $name);
    }
    
    /**
     * 添加POST路由
     * 
     * @param string $pattern 路由模式
     * @param mixed $handler 处理程序
     * @param string|null $name 路由名称
     * @return void
     */
    public function post(string $pattern, $handler, ?string $name = null): void
    {
        $this->addRoute('POST', $pattern, $handler, $name);
    }
    
    /**
     * 添加PUT路由
     * 
     * @param string $pattern 路由模式
     * @param mixed $handler 处理程序
     * @param string|null $name 路由名称
     * @return void
     */
    public function put(string $pattern, $handler, ?string $name = null): void
    {
        $this->addRoute('PUT', $pattern, $handler, $name);
    }
    
    /**
     * 添加DELETE路由
     * 
     * @param string $pattern 路由模式
     * @param mixed $handler 处理程序
     * @param string|null $name 路由名称
     * @return void
     */
    public function delete(string $pattern, $handler, ?string $name = null): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $name);
    }
    
    /**
     * 添加PATCH路由
     * 
     * @param string $pattern 路由模式
     * @param mixed $handler 处理程序
     * @param string|null $name 路由名称
     * @return void
     */
    public function patch(string $pattern, $handler, ?string $name = null): void
    {
        $this->addRoute('PATCH', $pattern, $handler, $name);
    }
    
    /**
     * 添加任意方法路由
     * 
     * @param string $pattern 路由模式
     * @param mixed $handler 处理程序
     * @param string|null $name 路由名称
     * @return void
     */
    public function any(string $pattern, $handler, ?string $name = null): void
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];
        foreach ($methods as $method) {
            $this->addRoute($method, $pattern, $handler, $name);
        }
    }
    
    /**
     * 添加路由组
     * 
     * @param array $options 组选项
     * @param callable $callback 回调函数
     * @return void
     */
    public function group(array $options, callable $callback): void
    {
        // 保存当前组状态
        $oldPrefix = $this->groupPrefix;
        $oldMiddleware = $this->groupMiddleware;
        
        // 设置新组选项
        if (isset($options['prefix'])) {
            $this->groupPrefix = rtrim($oldPrefix, '/') . '/' . ltrim($options['prefix'], '/');
        }
        
        if (isset($options['middleware'])) {
            $this->groupMiddleware = array_merge($oldMiddleware, (array)$options['middleware']);
        }
        
        // 执行回调
        $callback($this);
        
        // 恢复旧组状态
        $this->groupPrefix = $oldPrefix;
        $this->groupMiddleware = $oldMiddleware;
    }
    
    /**
     * 添加路由
     * 
     * @param string $method HTTP方法
     * @param string $pattern 路由模式
     * @param mixed $handler 处理程序
     * @param string|null $name 路由名称
     * @return void
     */
    private function addRoute(string $method, string $pattern, $handler, ?string $name = null): void
    {
        // 应用组前缀
        $pattern = $this->groupPrefix . $pattern;
        
        // 规范化模式
        $pattern = '/' . trim($pattern, '/');
        if ($pattern !== '/') {
            $pattern = rtrim($pattern, '/');
        }
        
        // 转换模式为正则表达式
        $regex = $this->patternToRegex($pattern);
        
        // 创建路由
        $route = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
            'params' => $this->extractParamNames($pattern)
        ];
        
        $this->routes[$method][] = $route;
        
        // 注册命名路由
        if ($name !== null) {
            $this->namedRoutes[$name] = $pattern;
        }
    }
    
    /**
     * 将路由模式转换为正则表达式
     * 
     * @param string $pattern 路由模式
     * @return string 正则表达式
     */
    private function patternToRegex(string $pattern): string
    {
        // 先将参数占位符替换为临时标记，避免被 preg_quote 转义。
        $tokens = [];
        $index = 0;

        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?\}/', function (array $matches) use (&$tokens, &$index): string {
            $token = '__ROUTE_PARAM_' . $index . '__';
            $tokens[$token] = isset($matches[2]) && $matches[2] === '?'
                ? '(?P<' . $matches[1] . '>[^/]*)'
                : '(?P<' . $matches[1] . '>[^/]+)';
            $index++;

            return $token;
        }, $pattern) ?? $pattern;

        // 转义静态路径文本。
        $pattern = preg_quote($pattern, '#');

        // 将临时标记替换回参数正则。
        if (!empty($tokens)) {
            $pattern = strtr($pattern, $tokens);
        }

        return '#^' . $pattern . '$#';
    }
    
    /**
     * 从模式中提取参数名
     * 
     * @param string $pattern 路由模式
     * @return array 参数名数组
     */
    private function extractParamNames(string $pattern): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?\}/', $pattern, $matches);
        return $matches[1] ?? [];
    }
    
    /**
     * 获取当前请求路径
     * 
     * @return string 请求路径
     */
    private function getCurrentPath(): string
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        
        // 移除查询字符串
        if (($pos = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $pos);
        }
        
        // 规范化路径
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        
        return $path;
    }
    
    /**
     * 分发路由
     * 
     * @return mixed 路由处理结果
     * @throws \Exception 路由未找到时抛出异常
     */
    public function dispatch()
    {
        $route = $this->match();
        
        if ($route === null) {
            throw HttpException::notFound("路由未找到: {$this->method} {$this->path}");
        }
        
        // 执行中间件
        $this->executeMiddleware($route['middleware']);
        
        // 执行处理程序
        return $this->executeHandler($route['handler'], $route['params']);
    }
    
    /**
     * 匹配当前请求的路由
     * 
     * @return array|null 匹配的路由或null
     */
    private function match(): ?array
    {
        if (!isset($this->routes[$this->method])) {
            return null;
        }
        
        foreach ($this->routes[$this->method] as $route) {
            if (preg_match($route['regex'], $this->path, $matches)) {
                // 提取参数
                $params = [];
                foreach ($route['params'] as $param) {
                    if (isset($matches[$param])) {
                        $params[$param] = $matches[$param];
                    }
                }
                
                $route['params'] = $params;
                return $route;
            }
        }
        
        return null;
    }
    
    /**
     * 执行中间件
     * 
     * @param array $middleware 中间件数组
     * @return void
     */
    private function executeMiddleware(array $middleware): void
    {
        foreach ($middleware as $mw) {
            if (is_string($mw)) {
                $mw = $this->resolveMiddleware($mw);
            }
            
            if (is_callable($mw)) {
                $mw();
            }
        }
    }
    
    /**
     * 解析中间件
     * 
     * @param string $name 中间件名称
     * @return callable 中间件回调
     * @throws \Exception 中间件未找到时抛出异常
     */
    private function resolveMiddleware(string $name): callable
    {
        switch ($name) {
            case 'auth':
                return function(): void {
                    if (!auth()->check()) {
                        if ($this->isApiRequest()) {
                            Response::json([
                                'success' => false,
                                'message' => '未登录或会话已过期'
                            ], 401)->send();
                        } else {
                            Response::redirect('/auth/login')->send();
                        }
                        exit;
                    }

                    $this->sendNoCacheHeaders();
                };

            case 'guest':
                return function(): void {
                    if (auth()->check()) {
                        Response::redirect('/dashboard')->send();
                        exit;
                    }
                };

            case 'api':
                return function(): void {
                    $tokenAuth = $this->authenticateApiToken($this->extractApiToken());
                    if ($tokenAuth !== null) {
                        session()->set('user_id', (int) $tokenAuth['user_id']);
                        session()->set('user_role', (string) $tokenAuth['role']);
                        session()->set('user_logged_in', true);
                        session()->updateLastActivity();

                        $this->logApiAccess(
                            200,
                            'token',
                            (int) $tokenAuth['user_id'],
                            (int) $tokenAuth['token_id'],
                            'token authorized'
                        );
                        return;
                    }

                    if (auth()->check()) {
                        $this->logApiAccess(200, 'session', (int) auth()->id(), null, 'session authorized');
                        return;
                    }

                    $this->logApiAccess(401, 'none', null, null, 'unauthorized');
                    Response::json([
                        'success' => false,
                        'message' => 'API访问未授权'
                    ], 401)->send();
                    exit;
                };

            default:
                throw HttpException::internalServerError("中间件未找到: {$name}");
        }
    }

    /**
     * 判断当前请求是否为API请求
     *
     * @return bool
     */
    private function isApiRequest(): bool
    {
        if (strpos($this->path, '/api') === 0) {
            return true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return stripos($accept, 'application/json') !== false
            || strtolower($requestedWith) === 'xmlhttprequest';
    }

    /**
     * 为受保护页面设置防缓存响应头，避免浏览器后退显示旧页面。
     *
     * @return void
     */
    private function sendNoCacheHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }

    /**
     * 提取API令牌
     *
     * 支持:
     * - Authorization: Bearer <token>
     * - X-API-Token: <token>
     * - ?api_token=<token>
     *
     * @return string|null
     */
    private function extractApiToken(): ?string
    {
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            $token = trim((string) ($matches[1] ?? ''));
            if ($token !== '') {
                return $token;
            }
        }

        $headerToken = trim((string) ($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
        if ($headerToken !== '') {
            return $headerToken;
        }

        $queryToken = trim((string) ($_GET['api_token'] ?? ''));
        if ($queryToken !== '') {
            return $queryToken;
        }

        return null;
    }

    /**
     * 验证API令牌
     *
     * @param string|null $rawToken
     * @return array|null
     */
    private function authenticateApiToken(?string $rawToken): ?array
    {
        if ($rawToken === null || $rawToken === '') {
            return null;
        }

        try {
            $tokenHash = hash('sha256', $rawToken);
            $row = db()->fetch(
                'SELECT
                    t.id AS token_id,
                    t.user_id,
                    t.is_active,
                    t.expires_at,
                    u.role,
                    u.status
                 FROM api_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = ?
                 LIMIT 1',
                [$tokenHash]
            );
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        if ((int) ($row['is_active'] ?? 0) !== 1) {
            return null;
        }

        if ((string) ($row['status'] ?? '') !== 'active') {
            return null;
        }

        $expiresAt = (string) ($row['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            return null;
        }

        try {
            db()->update('api_tokens', [
                'last_used_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['token_id']]);
        } catch (\Throwable $e) {
            // 访问时间更新失败不阻断主流程
        }

        return [
            'token_id' => (int) $row['token_id'],
            'user_id' => (int) $row['user_id'],
            'role' => (string) $row['role'],
        ];
    }

    /**
     * 记录API访问日志
     *
     * @param int $statusCode
     * @param string $authType
     * @param int|null $userId
     * @param int|null $tokenId
     * @param string $message
     * @return void
     */
    private function logApiAccess(int $statusCode, string $authType, ?int $userId, ?int $tokenId, string $message): void
    {
        try {
            db()->insert('api_access_logs', [
                'user_id' => $userId,
                'token_id' => $tokenId,
                'request_path' => $this->path,
                'request_method' => $this->method,
                'status_code' => $statusCode,
                'auth_type' => $authType,
                'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'message' => $message,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不阻断请求
        }
    }
    
    /**
     * 执行处理程序
     * 
     * @param mixed $handler 处理程序
     * @param array $params 路由参数
     * @return mixed 处理结果
     * @throws \Exception 处理程序执行失败时抛出异常
     */
    private function executeHandler($handler, array $params)
    {
        if (is_callable($handler)) {
            return call_user_func_array($handler, $params);
        }
        
        if (is_string($handler)) {
            return $this->executeControllerAction($handler, $params);
        }
        
        if (is_array($handler) && count($handler) === 2) {
            $controller = $handler[0];
            $action = $handler[1];
            return $this->executeControllerAction("{$controller}@{$action}", $params);
        }
        
        throw new \Exception("无效的路由处理程序");
    }
    
    /**
     * 执行控制器动作
     * 
     * @param string $handler 控制器动作字符串
     * @param array $params 路由参数
     * @return mixed 执行结果
     * @throws \Exception 控制器或动作未找到时抛出异常
     */
    private function executeControllerAction(string $handler, array $params)
    {
        list($controller, $action) = explode('@', $handler, 2);
        
        // 规范化控制器类名
        $controller = 'App\\Controllers\\' . str_replace('/', '\\', $controller);
        
        // 检查控制器是否存在
        if (!class_exists($controller)) {
            throw HttpException::notFound("控制器未找到: {$controller}");
        }
        
        // 创建控制器实例
        $controllerInstance = new $controller();
        
        // 检查动作是否存在
        if (!method_exists($controllerInstance, $action)) {
            throw HttpException::notFound("动作未找到: {$controller}::{$action}");
        }
        
        // 执行动作
        return call_user_func_array([$controllerInstance, $action], $params);
    }
    
    /**
     * 生成URL
     * 
     * @param string $name 路由名称
     * @param array $params 路由参数
     * @return string 生成的URL
     * @throws \Exception 路由未找到时抛出异常
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \Exception("命名路由未找到: {$name}");
        }
        
        $pattern = $this->namedRoutes[$name];
        
        // 替换参数
        foreach ($params as $key => $value) {
            $pattern = str_replace('{' . $key . '}', $value, $pattern);
            $pattern = str_replace('{' . $key . '?}', $value, $pattern);
        }
        
        // 移除未提供的可选参数
        $pattern = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\?\}/', '', $pattern);
        
        // 移除空段
        $pattern = preg_replace('/\/+/', '/', $pattern);
        $pattern = rtrim($pattern, '/');
        
        return $pattern ?: '/';
    }
    
    /**
     * 重定向到命名路由
     * 
     * @param string $name 路由名称
     * @param array $params 路由参数
     * @param int $status 状态码
     * @return void
     */
    public function redirectToRoute(string $name, array $params = [], int $status = 302): void
    {
        $url = $this->url($name, $params);
        header("Location: {$url}", true, $status);
        exit;
    }
    
    /**
     * 获取所有路由
     * 
     * @return array 所有路由
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
    
    /**
     * 获取命名路由
     * 
     * @return array 命名路由
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }
    
    /**
     * 加载路由文件
     * 
     * @param string $file 路由文件路径
     * @return void
     */
    public function loadRoutes(string $file): void
    {
        if (file_exists($file)) {
            require $file;
        }
    }
    
    /**
     * 注册默认路由
     * 
     * @return void
     */
    public function registerDefaultRoutes(): void
    {
        // favicon.ico 返回空内容，避免404错误
        $this->get('/favicon.ico', function() {
            header('HTTP/1.1 204 No Content');
            exit;
        });

        // 首页
        $this->get('/', 'HomeController@index', 'home');
        
        // 认证路由
        $this->group(['prefix' => 'auth'], function($router) {
            $router->get('/login', 'AuthController@showLoginForm', 'auth.login');
            $router->post('/login', 'AuthController@login', 'auth.login.post');
            $router->get('/logout', 'AuthController@logout', 'auth.logout');
            $router->get('/register', 'AuthController@showRegistrationForm', 'auth.register');
            $router->post('/register', 'AuthController@register', 'auth.register.post');
        });
        
        // 需要认证的路由
        $this->group(['middleware' => ['auth']], function($router) {
            // 租户管理
            $router->get('/admin/tenants', 'TenantAdminController@index');
            $router->get('/admin/tenants/create', 'TenantAdminController@create');
            $router->post('/admin/tenants', 'TenantAdminController@store');
            $router->get('/admin/tenants/{id}', 'TenantAdminController@show');
            $router->get('/admin/tenants/{id}/edit', 'TenantAdminController@edit');
            $router->post('/admin/tenants/{id}', 'TenantAdminController@update');
            $router->post('/admin/tenants/{id}/delete', 'TenantAdminController@delete');
            $router->post('/admin/tenants/{tenantId}/cohabitants/save', 'TenantAdminController@saveCohabitant');
            $router->post('/admin/tenants/{tenantId}/cohabitants/{id}/delete', 'TenantAdminController@deleteCohabitant');
            $router->post('/admin/tenants/{tenantId}/cohabitants/{id}/moveout', 'TenantAdminController@moveOutCohabitant');
            $router->post('/admin/tenants/{id}/moveout', 'TenantAdminController@moveOut');
            $router->post('/admin/tenants/{id}/restore', 'TenantAdminController@restore');
            // 仪表板
            $router->get('/dashboard', 'DashboardController@index', 'dashboard');
            $router->get('/profile', 'UserController@profile', 'profile');
            $router->get('/settings', 'SettingsController@index', 'settings');
            $router->post('/settings', 'SettingsController@update', 'settings.update');
            
            // 用户管理
            $router->get('/users', 'UserController@index', 'users.index');
            $router->get('/users/create', 'UserController@create', 'users.create');
            $router->post('/users', 'UserController@store', 'users.store');
            $router->get('/users/{id}', 'UserController@show', 'users.show');
            $router->get('/users/{id}/edit', 'UserController@edit', 'users.edit');
            $router->get('/users/{id}/password', 'UserController@showPasswordForm', 'users.password.edit');
            $router->put('/users/{id}/password', 'UserController@updatePassword', 'users.password.update');
            $router->put('/users/{id}', 'UserController@update', 'users.update');
            $router->delete('/users/{id}', 'UserController@destroy', 'users.destroy');
            
            // 房产管理
            $router->get('/properties', 'PropertyController@index', 'properties.index');
            $router->get('/properties/rent-adjustments', 'PropertyController@rentAdjustments', 'properties.rent_adjustments');
            $router->get('/properties/create', 'PropertyController@create', 'properties.create');
            $router->post('/properties', 'PropertyController@store', 'properties.store');
            $router->post('/properties/{id}/rent-adjustment', 'PropertyController@updateMonthlyRent', 'properties.rent_adjustments.update');
            $router->get('/properties/{id}', 'PropertyController@show', 'properties.show');
            $router->get('/properties/{id}/edit', 'PropertyController@edit', 'properties.edit');
            $router->put('/properties/{id}', 'PropertyController@update', 'properties.update');
            $router->delete('/properties/{id}', 'PropertyController@destroy', 'properties.destroy');
            
            // 合同管理
            $router->get('/contracts', 'ContractController@index', 'contracts.index');
            $router->get('/contracts/create', 'ContractController@create', 'contracts.create');
            $router->get('/contracts/expiring', 'ContractController@expiring', 'contracts.expiring');
            $router->post('/contracts/{id}/remind', 'ContractController@remind', 'contracts.remind');
            $router->post('/contracts/{id}/renew', 'ContractController@renew', 'contracts.renew');
            $router->post('/contracts/{id}/meters', 'ContractController@addMeter', 'contracts.meters.add');
            $router->post('/contracts/{id}/meters/{meterId}', 'ContractController@updateMeter', 'contracts.meters.update');
            $router->post('/contracts/{id}/meters/{meterId}/deactivate', 'ContractController@deactivateMeter', 'contracts.meters.deactivate');
            $router->post('/contracts', 'ContractController@store', 'contracts.store');
            $router->get('/contracts/{id}', 'ContractController@show', 'contracts.show');
            $router->get('/contracts/{id}/edit', 'ContractController@edit', 'contracts.edit');
            $router->put('/contracts/{id}', 'ContractController@update', 'contracts.update');
            $router->delete('/contracts/{id}', 'ContractController@destroy', 'contracts.destroy');

            // 支付与账单
            $router->get('/payments', 'PaymentController@index', 'payments.index');
            $router->get('/payments/reconciliation', 'PaymentController@reconciliation', 'payments.reconciliation');
            $router->get('/payments/reconciliation/export', 'PaymentController@reconciliationExport', 'payments.reconciliation.export');
            $router->get('/payments/create', 'PaymentController@create', 'payments.create');
            $router->get('/payments/export', 'PaymentController@export', 'payments.export');
            $router->post('/payments', 'PaymentController@store', 'payments.store');
            $router->post('/payments/generate', 'PaymentController@generate', 'payments.generate');
            $router->post('/payments/{id}/record', 'PaymentController@record', 'payments.record');
            $router->get('/payments/{id}/receipt', 'PaymentController@receipt', 'payments.receipt');
            $router->get('/meter-types', 'PaymentController@meterTypes', 'meter_types.index');
            $router->post('/meter-types', 'PaymentController@meterTypeStore', 'meter_types.store');
            $router->post('/meter-types/{id}', 'PaymentController@meterTypeUpdate', 'meter_types.update');

            // 支出管理
            $router->get('/expenses', 'ExpenseController@index', 'expenses.index');
            $router->get('/expenses/create', 'ExpenseController@create', 'expenses.create');
            $router->post('/expenses', 'ExpenseController@store', 'expenses.store');
            $router->get('/expenses/categories', 'ExpenseController@categories', 'expenses.categories');
            $router->post('/expenses/categories', 'ExpenseController@categoryStore', 'expenses.categories.store');
            $router->post('/expenses/categories/{id}', 'ExpenseController@categoryUpdate', 'expenses.categories.update');

            // 财务报表
            $router->get('/reports/financial', 'PaymentController@financialReport', 'reports.financial');
            $router->get('/reports/financial/export', 'PaymentController@financialReportExport', 'reports.financial.export');
            $router->get('/reports/personal', 'PaymentController@personalReport', 'reports.personal');
            $router->get('/reports/personal/export', 'PaymentController@personalReportExport', 'reports.personal.export');
            $router->get('/reports/occupancy', 'PaymentController@occupancyReport', 'reports.occupancy');
            $router->get('/reports/occupancy/export', 'PaymentController@occupancyReportExport', 'reports.occupancy.export');

            // 通知中心
            $router->get('/notifications', 'NotificationController@index', 'notifications.index');
            $router->post('/notifications/mark-all-read', 'NotificationController@markAllRead', 'notifications.mark_all_read');
            $router->post('/notifications/{id}/read', 'NotificationController@markRead', 'notifications.mark_read');

            // API Token 管理（管理员）
            $router->get('/api-tokens', 'ApiTokenController@index', 'api_tokens.index');
            $router->post('/api-tokens', 'ApiTokenController@store', 'api_tokens.store');
            $router->post('/api-tokens/{id}/revoke', 'ApiTokenController@revoke', 'api_tokens.revoke');
            $router->post('/api-tokens/{id}/rotate', 'ApiTokenController@rotate', 'api_tokens.rotate');

            // API 访问审计（管理员）
            $router->get('/api-access-logs', 'ApiAccessLogController@index', 'api_access_logs.index');
            $router->get('/api-access-logs/export', 'ApiAccessLogController@exportCsv', 'api_access_logs.export');

            // 租客中心（MVP）
            $router->get('/tenant/bills', 'TenantController@bills', 'tenant.bills');
            $router->get('/tenant/notifications', 'TenantController@notifications', 'tenant.notifications');
        });
        
        // API路由
        $this->group(['prefix' => 'api', 'middleware' => ['api']], function($router) {
            $router->get('/users', 'Api\UserController@index');
            $router->post('/users', 'Api\UserController@store');
            $router->get('/users/{id}', 'Api\UserController@show');
            $router->put('/users/{id}', 'Api\UserController@update');
            $router->delete('/users/{id}', 'Api\UserController@destroy');

            // 报表API（只读）
            $router->get('/reports/financial-summary', 'Api\ReportController@financialSummary');
            $router->get('/reports/occupancy-summary', 'Api\ReportController@occupancySummary');

            // 通知API（只读）
            $router->get('/notifications', 'Api\NotificationController@index');
            $router->get('/notifications/unread-count', 'Api\NotificationController@unreadCount');
        });
    }
}