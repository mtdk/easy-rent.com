<?php
/**
 * 收租管理系统 - 辅助函数
 * 
 * 提供全局可用的辅助函数
 */

if (!function_exists('env')) {
    /**
     * 获取环境变量的值
     * 
     * @param string $key 环境变量键
     * @param mixed $default 默认值
     * @return mixed 环境变量值
     */
    function env(string $key, $default = null)
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
}

if (!function_exists('config')) {
    /**
     * 获取配置值
     * 
     * @param string $key 配置键（支持点语法）
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    function config(string $key, $default = null)
    {
        static $config = null;
        
        if ($config === null) {
            $config = require CONFIG_PATH . '/app.php';
        }
        
        $keys = explode('.', $key);
        $value = $config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
}

if (!function_exists('app')) {
    /**
     * 获取应用实例或服务
     * 
     * @param string|null $service 服务名称
     * @return mixed 应用实例或服务
     */
    function app(?string $service = null)
    {
        $app = \App\Core\Application::getInstance();
        
        if ($service === null) {
            return $app;
        }
        
        return $app->getService($service);
    }
}

if (!function_exists('database_path')) {
    /**
     * 获取数据库目录下的文件路径
     *
     * @param string $path 相对数据库目录的路径
     * @return string 完整路径
     */
    function database_path(string $path = ''): string
    {
        $basePath = APP_ROOT . '/database';
        if ($path === '') {
            return $basePath;
        }

        return $basePath . '/' . ltrim($path, '/');
    }
}

if (!function_exists('db')) {
    /**
     * 获取数据库实例
     * 
     * @return \App\Core\Database 数据库实例
     */
    function db(): \App\Core\Database
    {
        return app('database');
    }
}

if (!function_exists('auth')) {
    /**
     * 获取认证实例
     * 
     * @return \App\Core\Auth 认证实例
     */
    function auth(): \App\Core\Auth
    {
        return app('auth');
    }
}

if (!function_exists('session')) {
    /**
     * 获取会话实例或值
     * 
     * @param string|null $key 会话键
     * @param mixed $default 默认值
     * @return mixed 会话实例或值
     */
    function session(?string $key = null, $default = null)
    {
        $session = app('session');
        
        if ($key === null) {
            return $session;
        }
        
        return $session->get($key, $default);
    }
}

if (!function_exists('view')) {
    /**
     * 渲染视图
     * 
     * @param string $view 视图名称
     * @param array $data 视图数据
     * @return string 渲染后的HTML
     */
    function view(string $view, array $data = []): string
    {
        $viewPath = VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php';
        
        if (!file_exists($viewPath)) {
            throw new \Exception("视图文件不存在: {$viewPath}");
        }
        
        // 提取数据到当前符号表
        extract($data, EXTR_SKIP);
        
        // 开始输出缓冲
        ob_start();
        
        try {
            include $viewPath;
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        }
        
        return ob_get_clean();
    }
}

if (!function_exists('asset')) {
    /**
     * 生成资源URL
     * 
     * @param string $path 资源路径
     * @return string 完整的资源URL
     */
    function asset(string $path): string
    {
        $baseUrl = config('app.url', 'http://localhost');
        $assetUrl = config('app.asset_url', '/assets');
        
        // 移除路径开头的斜杠
        $path = ltrim($path, '/');
        
        return rtrim($baseUrl, '/') . '/' . ltrim($assetUrl, '/') . '/' . $path;
    }
}

if (!function_exists('url')) {
    /**
     * 生成URL
     * 
     * @param string $path 路径
     * @param array $parameters 查询参数
     * @return string 完整的URL
     */
    function url(string $path = '', array $parameters = []): string
    {
        $baseUrl = config('app.url', 'http://localhost');
        
        // 构建URL
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        
        // 添加查询参数
        if (!empty($parameters)) {
            $url .= '?' . http_build_query($parameters);
        }
        
        return $url;
    }
}

if (!function_exists('route')) {
    /**
     * 生成路由URL
     * 
     * @param string $name 路由名称
     * @param array $parameters 路由参数
     * @return string 路由URL
     */
    function route(string $name, array $parameters = []): string
    {
        $router = app('router');
        return $router->url($name, $parameters);
    }
}

if (!function_exists('app_unified_navbar_styles')) {
    /**
     * 获取统一导航样式
     *
     * @return string 导航样式块
     */
    function app_unified_navbar_styles(): string
    {
        return '<style>
        .top-nav {
            position: relative;
            z-index: 1035;
        }

        .top-nav .dropdown-menu {
            z-index: 1045;
        }

        .top-nav .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.2px;
            color: #f8fbff !important;
        }

        .top-nav .navbar-brand:hover,
        .top-nav .navbar-brand:focus {
            color: #ffffff !important;
        }

        .top-nav .nav-link {
            color: rgba(255, 255, 255, 0.8);
            border-radius: 0.5rem;
            padding: 0.45rem 0.8rem;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .top-nav .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.12);
        }

        .top-nav .nav-link.active {
            color: #fff;
            background: rgba(13, 110, 253, 0.45);
            font-weight: 600;
        }

        .top-nav .logout-nav {
            margin-left: auto;
        }

        .top-nav .logout-nav .logout-link {
            color: #ffb8bf;
            border: 1px solid rgba(220, 53, 69, 0.55);
            background: rgba(220, 53, 69, 0.14);
            font-weight: 600;
        }

        .top-nav .logout-nav .logout-link:hover,
        .top-nav .logout-nav .logout-link:focus {
            color: #ffffff;
            border-color: rgba(255, 99, 132, 0.8);
            background: rgba(220, 53, 69, 0.35);
        }

        .top-nav .logout-nav .logout-link:active {
            color: #ffffff;
            background: rgba(220, 53, 69, 0.5);
        }

        @media (max-width: 767.98px) {
            .top-nav .navbar-collapse {
                margin-top: 0.6rem;
                padding-top: 0.6rem;
                border-top: 1px solid rgba(255, 255, 255, 0.2);
            }

            .top-nav .logout-nav {
                margin-left: 0;
            }
        }
        </style>';
    }
}

if (!function_exists('app_unified_navbar')) {
    /**
     * 渲染统一导航组件
     *
     * @param array $options 配置项
     * @return string 导航HTML
     */
    function app_unified_navbar(array $options = []): string
    {
        $active = (string) ($options['active'] ?? 'dashboard');
        $isAdmin = (bool) ($options['is_admin'] ?? false);
        $showUserMenu = (bool) ($options['show_user_menu'] ?? true);
        $collapseId = (string) ($options['collapse_id'] ?? 'appUnifiedNavbar');
        $userLabel = (string) ($options['user_label'] ?? '');
        $usersSubjectId = (int) ($options['users_subject_id'] ?? 0);

        if ($usersSubjectId <= 0 && function_exists('auth') && auth()->check()) {
            $usersSubjectId = (int) auth()->id();
        }

        if ($userLabel === '' && function_exists('auth') && auth()->check()) {
            if (auth()->isAdmin()) {
                $userLabel = '系统管理员';
            } else {
                $authUser = auth()->user();
                $userLabel = (string) ($authUser['real_name'] ?? $authUser['username'] ?? '用户中心');
            }
        }

        $userLabel = htmlspecialchars($userLabel !== '' ? $userLabel : '用户中心', ENT_QUOTES, 'UTF-8');

        $items = [
            ['key' => 'dashboard', 'label' => '仪表板', 'icon' => 'bi-speedometer2', 'href' => '/dashboard', 'admin_only' => false],
            ['key' => 'users', 'label' => '用户管理', 'icon' => 'bi-people-fill', 'href' => '/users', 'admin_only' => true],
            ['key' => 'settings', 'label' => '系统设置', 'icon' => 'bi-sliders2', 'href' => '/settings', 'admin_only' => true],
            ['key' => 'api_management', 'label' => 'API管理', 'icon' => 'bi-cloud-check-fill', 'href' => '/api-tokens', 'admin_only' => true],
            ['key' => 'properties', 'label' => '房产管理', 'icon' => 'bi-house-heart-fill', 'href' => '/properties', 'admin_only' => false],
            // 支持租户管理所有相关路由高亮
            ['key' => 'tenants', 'label' => '租户管理', 'icon' => 'bi-person-vcard-fill', 'href' => '/admin/tenants', 'admin_only' => false, 'landlord_only' => true, 'active_patterns' => [
                '/admin/tenants',
                '/admin/tenants/create',
                '/admin/tenants/[0-9]+/edit',
                '/admin/tenants/[0-9]+',
                '/admin/tenants/[0-9]+/delete',
                '/admin/tenants/[0-9]+/cohabitants',
            ]],
            ['key' => 'contracts', 'label' => '合同管理', 'icon' => 'bi-file-earmark-text-fill', 'href' => '/contracts', 'admin_only' => false],
            ['key' => 'payments', 'label' => '账单与收款', 'icon' => 'bi-cash-stack', 'href' => '/payments', 'admin_only' => false],
            ['key' => 'expenses', 'label' => '支出管理', 'icon' => 'bi-wrench-adjustable-circle-fill', 'href' => '/expenses', 'admin_only' => false],
            ['key' => 'notifications', 'label' => '通知中心', 'icon' => 'bi-bell-fill', 'href' => '/notifications', 'admin_only' => false],
        ];

        $leftItems = '';
        $isLandlord = function_exists('auth') && auth()->isLandlord();
        $currentPath = $_SERVER['REQUEST_URI'] ?? '';
        foreach ($items as $item) {
            if ($item['admin_only'] && !$isAdmin) {
                continue;
            }
            if (isset($item['landlord_only']) && $item['landlord_only'] && !$isAdmin && !$isLandlord) {
                continue;
            }

            if ($item['key'] === 'users') {
                $isUsersActive = in_array($active, ['users', 'users_password'], true);
                $passwordHref = $usersSubjectId > 0 ? '/users/' . $usersSubjectId . '/password' : '/users';

                $leftItems .= '<li class="nav-item dropdown">'
                    . '<a class="nav-link dropdown-toggle' . ($isUsersActive ? ' active" aria-current="page' : '') . '" href="#" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi ' . $item['icon'] . ' me-1"></i>' . $item['label'] . '</a>'
                    . '<ul class="dropdown-menu">'
                    . '<li><a class="dropdown-item" href="/users"><i class="bi bi-eye me-1"></i>信息查看</a></li>'
                    . '<li><a class="dropdown-item" href="' . htmlspecialchars($passwordHref, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-shield-lock me-1"></i>密码修改</a></li>'
                    . '</ul>'
                    . '</li>';
                continue;
            }

            if ($item['key'] === 'expenses') {
                $isExpensesActive = in_array($active, ['expenses', 'expenses_create', 'expenses_categories'], true);

                $leftItems .= '<li class="nav-item dropdown">'
                    . '<a class="nav-link dropdown-toggle' . ($isExpensesActive ? ' active" aria-current="page' : '') . '" href="#" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi ' . $item['icon'] . ' me-1"></i>' . $item['label'] . '</a>'
                    . '<ul class="dropdown-menu">'
                    . '<li><a class="dropdown-item" href="/expenses"><i class="bi bi-list-ul me-1"></i>支出记录</a></li>'
                    . '<li><a class="dropdown-item" href="/expenses/create"><i class="bi bi-plus-square me-1"></i>新增支出</a></li>'
                    . '<li><a class="dropdown-item" href="/expenses/categories"><i class="bi bi-tags me-1"></i>分类管理</a></li>'
                    . '</ul>'
                    . '</li>';
                continue;
            }

            if ($item['key'] === 'payments') {
                $isPaymentsActive = in_array($active, ['payments', 'payments_create', 'payments_reconciliation', 'meter_types'], true);

                $leftItems .= '<li class="nav-item dropdown">'
                    . '<a class="nav-link dropdown-toggle' . ($isPaymentsActive ? ' active" aria-current="page' : '') . '" href="#" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi ' . $item['icon'] . ' me-1"></i>' . $item['label'] . '</a>'
                    . '<ul class="dropdown-menu">'
                    . '<li><a class="dropdown-item' . ($active === 'payments' ? ' active' : '') . '" href="/payments"><i class="bi bi-list-check me-1"></i>账单列表</a></li>'
                    . '<li><a class="dropdown-item' . ($active === 'payments_create' ? ' active' : '') . '" href="/payments/create"><i class="bi bi-plus-square me-1"></i>新建月度账单</a></li>'
                    . '<li><a class="dropdown-item' . ($active === 'payments_reconciliation' ? ' active' : '') . '" href="/payments/reconciliation"><i class="bi bi-clipboard2-data me-1"></i>月度对账</a></li>'
                    . ($isAdmin ? '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item' . ($active === 'meter_types' ? ' active' : '') . '" href="/meter-types"><i class="bi bi-speedometer me-1"></i>计量类型管理</a></li>' : '')
                    . '</ul>'
                    . '</li>';
                continue;
            }

            if ($item['key'] === 'api_management') {
                $isApiActive = in_array($active, ['api_tokens', 'api_access_logs', 'api_management'], true);
                $isApiTokenActive = $active === 'api_tokens';
                $isApiAuditActive = $active === 'api_access_logs';

                $leftItems .= '<li class="nav-item dropdown">'
                    . '<a class="nav-link dropdown-toggle' . ($isApiActive ? ' active" aria-current="page' : '') . '" href="#" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi ' . $item['icon'] . ' me-1"></i>' . $item['label'] . '</a>'
                    . '<ul class="dropdown-menu">'
                    . '<li><a class="dropdown-item' . ($isApiTokenActive ? ' active' : '') . '" href="/api-tokens"><i class="bi bi-key-fill me-1"></i>API Token</a></li>'
                    . '<li><a class="dropdown-item' . ($isApiAuditActive ? ' active' : '') . '" href="/api-access-logs"><i class="bi bi-clipboard-data-fill me-1"></i>API 审计</a></li>'
                    . '</ul>'
                    . '</li>';
                continue;
            }

            $isActive = $item['key'] === $active;
            // 支持正则高亮
            if (!$isActive && isset($item['active_patterns']) && is_array($item['active_patterns'])) {
                foreach ($item['active_patterns'] as $pattern) {
                    $regex = '#^' . str_replace(['/', '[0-9]+'], ['\/', '\d+'], $pattern) . '$#';
                    if (preg_match($regex, $currentPath)) {
                        $isActive = true;
                        break;
                    }
                }
            }
            $leftItems .= '<li class="nav-item"><a class="nav-link' . ($isActive ? ' active" aria-current="page' : '') . '" href="' . $item['href'] . '"><i class="bi ' . $item['icon'] . ' me-1"></i>' . $item['label'] . '</a></li>';
        }

        if ($showUserMenu) {
            $leftItems .= '<li class="nav-item dropdown">'
                . '<a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-circle me-1"></i>' . $userLabel . '</a>'
                . '<ul class="dropdown-menu"><li><a class="dropdown-item" href="/profile"><i class="bi bi-person-badge me-1"></i>个人资料</a></li></ul>'
                . '</li>';
        }

        return '<nav class="navbar navbar-expand-sm navbar-dark bg-dark top-nav" aria-label="Main navigation">'
            . '<div class="container-fluid">'
            . '<a class="navbar-brand" href="/"><i class="bi bi-house-door-fill me-2"></i>收租管理系统</a>'
            . '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#' . htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') . '" aria-controls="' . htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') . '" aria-expanded="false" aria-label="Toggle navigation">'
            . '<span class="navbar-toggler-icon"></span></button>'
            . '<div class="collapse navbar-collapse" id="' . htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') . '">'
            . '<ul class="navbar-nav me-auto mb-2 mb-sm-0">' . $leftItems . '</ul>'
            . '<ul class="navbar-nav logout-nav mb-2 mb-sm-0"><li class="nav-item"><a class="nav-link logout-link" href="/auth/logout"><i class="bi bi-box-arrow-right me-1"></i>退出登录</a></li></ul>'
            . '</div></div></nav>'
            . '<script src="/assets/js/unified-navbar.js" defer></script>';
    }
}

if (!function_exists('redirect')) {
    /**
     * 重定向到指定URL
     * 
     * @param string $url 目标URL
     * @param int $status 状态码
     * @return void
     */
    function redirect(string $url, int $status = 302): void
    {
        header("Location: {$url}", true, $status);
        exit;
    }
}

if (!function_exists('back')) {
    /**
     * 重定向回上一页
     * 
     * @return void
     */
    function back(): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? url('/');
        redirect($referer);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * 生成CSRF令牌
     * 
     * @return string CSRF令牌
     */
    function csrf_token(): string
    {
        $session = app('session');
        
        if (!$session->has('_token')) {
            $session->set('_token', bin2hex(random_bytes(32)));
        }
        
        return $session->get('_token');
    }
}

if (!function_exists('csrf_field')) {
    /**
     * 生成CSRF隐藏字段
     * 
     * @return string CSRF隐藏字段HTML
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('old')) {
    /**
     * 获取旧输入值
     * 
     * @param string $key 输入键
     * @param mixed $default 默认值
     * @return mixed 旧输入值
     */
    function old(string $key, $default = null)
    {
        $session = app('session');
        static $consumedOldInput = null;

        if ($consumedOldInput === null) {
            $oldInput = $session->get('_old_input', []);
            $consumedOldInput = is_array($oldInput) ? $oldInput : [];
            $session->set('_old_input', []);
        }
        
        return $consumedOldInput[$key] ?? $default;
    }
}

if (!function_exists('flash')) {
    /**
     * 设置闪存消息
     * 
     * @param string $key 消息键
     * @param mixed $value 消息值
     * @return void
     */
    function flash(string $key, $value): void
    {
        $session = app('session');
        $session->flash($key, $value);
    }
}

if (!function_exists('has_flash')) {
    /**
     * 检查是否有闪存消息
     * 
     * @param string $key 消息键
     * @return bool 是否有闪存消息
     */
    function has_flash(string $key): bool
    {
        $session = app('session');
        return $session->hasFlash($key);
    }
}

if (!function_exists('get_flash')) {
    /**
     * 获取闪存消息
     * 
     * @param string $key 消息键
     * @param mixed $default 默认值
     * @return mixed 闪存消息
     */
    function get_flash(string $key, $default = null)
    {
        $session = app('session');
        return $session->getFlash($key, $default);
    }
}

if (!function_exists('abort')) {
    /**
     * 抛出HTTP异常
     * 
     * @param int $code HTTP状态码
     * @param string $message 错误消息
     * @return void
     * @throws \App\Core\HttpException
     */
    function abort(int $code, string $message = ''): void
    {
        throw new \App\Core\HttpException($code, $message);
    }
}

if (!function_exists('abort_if')) {
    /**
     * 如果条件为真则抛出HTTP异常
     * 
     * @param bool $condition 条件
     * @param int $code HTTP状态码
     * @param string $message 错误消息
     * @return void
     * @throws \App\Core\HttpException
     */
    function abort_if(bool $condition, int $code, string $message = ''): void
    {
        if ($condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('abort_unless')) {
    /**
     * 除非条件为真否则抛出HTTP异常
     * 
     * @param bool $condition 条件
     * @param int $code HTTP状态码
     * @param string $message 错误消息
     * @return void
     * @throws \App\Core\HttpException
     */
    function abort_unless(bool $condition, int $code, string $message = ''): void
    {
        if (!$condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('response')) {
    /**
     * 创建响应
     * 
     * @param mixed $content 响应内容
     * @param int $status 状态码
     * @param array $headers 响应头
     * @return \App\Core\Response 响应对象
     */
    function response($content = '', int $status = 200, array $headers = []): \App\Core\Response
    {
        return new \App\Core\Response($content, $status, $headers);
    }
}

if (!function_exists('json')) {
    /**
     * 创建JSON响应
     * 
     * @param mixed $data JSON数据
     * @param int $status 状态码
     * @param array $headers 响应头
     * @return \App\Core\JsonResponse JSON响应对象
     */
    function json($data, int $status = 200, array $headers = []): \App\Core\JsonResponse
    {
        return new \App\Core\JsonResponse($data, $status, $headers);
    }
}

if (!function_exists('dd')) {
    /**
     * 调试并终止
     * 
     * @param mixed ...$vars 要调试的变量
     * @return void
     */
    function dd(...$vars): void
    {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
        
        exit(1);
    }
}

if (!function_exists('dump')) {
    /**
     * 调试变量
     * 
     * @param mixed ...$vars 要调试的变量
     * @return void
     */
    function dump(...$vars): void
    {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
    }
}

if (!function_exists('now')) {
    /**
     * 获取当前日期时间
     * 
     * @param string|null $timezone 时区
     * @return \DateTime 当前日期时间
     */
    function now(?string $timezone = null): DateTime
    {
        return new DateTime('now', $timezone ? new DateTimeZone($timezone) : null);
    }
}

if (!function_exists('today')) {
    /**
     * 获取当前日期
     * 
     * @param string|null $timezone 时区
     * @return \DateTime 当前日期
     */
    function today(?string $timezone = null): DateTime
    {
        return (new DateTime('now', $timezone ? new DateTimeZone($timezone) : null))
            ->setTime(0, 0, 0);
    }
}

if (!function_exists('format_date')) {
    /**
     * 格式化日期
     * 
     * @param DateTime|string $date 日期
     * @param string $format 格式
     * @return string 格式化后的日期
     */
    function format_date($date, string $format = 'Y-m-d'): string
    {
        if (!$date instanceof DateTime) {
            $date = new DateTime($date);
        }
        
        return $date->format($format);
    }
}

if (!function_exists('format_datetime')) {
    /**
     * 格式化日期时间
     * 
     * @param DateTime|string $datetime 日期时间
     * @param string $format 格式
     * @return string 格式化后的日期时间
     */
    function format_datetime($datetime, string $format = 'Y-m-d H:i:s'): string
    {
        if (!$datetime instanceof DateTime) {
            $datetime = new DateTime($datetime);
        }
        
        return $datetime->format($format);
    }
}

if (!function_exists('format_currency')) {
    /**
     * 格式化货币
     * 
     * @param float $amount 金额
     * @param string $currency 货币符号
     * @param int $decimals 小数位数
     * @return string 格式化后的货币
     */
    function format_currency(float $amount, string $currency = '¥', int $decimals = 2): string
    {
        return $currency . number_format($amount, $decimals);
    }
}

if (!function_exists('str_limit')) {
    /**
     * 限制字符串长度
     * 
     * @param string $string 字符串
     * @param int $limit 限制长度
     * @param string $end 结尾字符串
     * @return string 限制后的字符串
     */
    function str_limit(string $string, int $limit = 100, string $end = '...'): string
    {
        if (mb_strlen($string) <= $limit) {
            return $string;
        }
        
        return rtrim(mb_substr($string, 0, $limit, 'UTF-8')) . $end;
    }
}

if (!function_exists('e')) {
    /**
     * 转义HTML特殊字符
     * 
     * @param string $value 要转义的值
     * @return string 转义后的值
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('trans')) {
    /**
     * 翻译字符串
     * 
     * @param string $key 翻译键
     * @param array $replace 替换参数
     * @return string 翻译后的字符串
     */
    function trans(string $key, array $replace = []): string
    {
        // 简化实现，实际应用中应该从语言文件加载
        $translations = [
            'auth.failed' => '提供的凭据与我们的记录不匹配。',
            'auth.password' => '提供的密码不正确。',
            'auth.throttle' => '登录尝试过多。请在 :seconds 秒后重试。',
            'validation.required' => ':attribute 字段是必需的。',
            'validation.email' => ':attribute 必须是有效的电子邮件地址。',
            'validation.min' => ':attribute 必须至少为 :min 个字符。',
            'validation.max' => ':attribute 不能超过 :max 个字符。',
        ];
        
        $translation = $translations[$key] ?? $key;
        
        // 替换参数
        foreach ($replace as $key => $value) {
            $translation = str_replace(':' . $key, $value, $translation);
        }
        
        return $translation;
    }
}

if (!function_exists('__')) {
    /**
     * 翻译字符串（别名）
     * 
     * @param string $key 翻译键
     * @param array $replace 替换参数
     * @return string 翻译后的字符串
     */
    function __(string $key, array $replace = []): string
    {
        return trans($key, $replace);
    }
}