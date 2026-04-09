<?php
/**
 * 收租管理系统 - 认证控制器
 * 
 * 处理用户认证相关请求
 */

namespace App\Controllers;

use App\Core\Response;
use App\Core\HttpException;

class AuthController
{
    /**
     * 显示登录表单
     * 
     * @return Response 响应对象
     */
    public function showLoginForm(): Response
    {
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 收租管理系统</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/css/custom.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
        }
        
        .login-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-control:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 0.25rem rgba(79, 70, 229, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);
        }
        
        .login-footer {
            text-align: center;
            padding: 20px;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
        }
        
        .system-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .system-description {
            font-size: 14px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <h1 class="system-name">
                <i class="bi bi-house-door-fill"></i> 收租管理系统
            </h1>
            <p class="system-description">专为局域网环境设计的租金管理解决方案</p>
        </div>
        
        <div class="login-body">
            <h3 class="text-center mb-4">用户登录</h3>
            
            <form id="loginForm" action="/auth/login" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="bi bi-person me-1"></i> 用户名
                    </label>
                    <input type="text" class="form-control" id="username" name="username" 
                           placeholder="请输入用户名" required autofocus>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">
                        <i class="bi bi-lock me-1"></i> 密码
                    </label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="请输入密码" required>
                </div>
                
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="remember" name="remember">
                    <label class="form-check-label" for="remember">记住我</label>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-2"></i> 登录
                    </button>
                </div>
                
                <input type="hidden" name="_token" value="' . csrf_token() . '">
            </form>
            
        </div>
        
        <div class="login-footer">
            <small>
                <i class="bi bi-info-circle me-1"></i>
                收租管理系统 v1.0.0 · 完全本地化部署
            </small>
        </div>
    </div>
    
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        document.getElementById(\'loginForm\').addEventListener(\'submit\', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // 显示加载状态
            const submitBtn = this.querySelector(\'button[type="submit"]\');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = \'<span class="spinner-border spinner-border-sm me-2"></span>登录中...\';
            submitBtn.disabled = true;
            
            // 发送登录请求
            fetch(this.action, {
                method: \'POST\',
                body: formData
            })
            .then(async response => {
                let data = null;

                try {
                    data = await response.json();
                } catch (e) {
                    data = null;
                }

                if (!response.ok || !data || data.success !== true) {
                    throw new Error((data && data.message) ? data.message : "登录失败，请检查输入信息");
                }

                return data;
            })
            .then(data => {
                // 登录成功，重定向到仪表板
                window.location.href = "/dashboard";
            })
            .catch(error => {
                const isNetworkError = error && (error.name === "TypeError" || error.message === "Failed to fetch");
                EasyRent.utils.showToast(
                    isNetworkError ? "网络错误，请稍后重试" : (error.message || "登录失败，请检查输入信息"),
                    "error"
                );
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // 页面加载完成
        document.addEventListener(\'DOMContentLoaded\', function() {
            console.log(\'登录页面已加载\');
        });
    </script>
</body>
</html>';
        
        return Response::html($html);
    }
    
    /**
     * 处理登录请求
     * 
     * @return Response 响应对象
     */
    public function login(): Response
    {
        // 验证CSRF令牌
        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json([
                'success' => false,
                'message' => 'CSRF令牌无效'
            ], 403);
        }
        
        // 获取输入数据
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);
        
        // 验证输入
        if (empty($username) || empty($password)) {
            return Response::json([
                'success' => false,
                'message' => '用户名和密码不能为空'
            ], 400);
        }
        
        // 尝试登录
        $auth = auth();
        if ($auth->login($username, $password, $remember)) {
            return Response::json([
                'success' => true,
                'message' => '登录成功',
                'redirect' => '/dashboard',
                'user' => [
                    'id' => $auth->id(),
                    'role' => $auth->role()
                ]
            ]);
        }
        
        return Response::json([
            'success' => false,
            'message' => '用户名或密码错误'
        ], 401);
    }
    
    /**
     * 处理登出请求
     * 
     * @return Response 响应对象
     */
    public function logout(): Response
    {
        auth()->logout();

        return Response::redirect('/auth/login', 302, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]);
    }
    
    /**
     * 显示注册表单
     * 
     * @return Response 响应对象
     */
    public function showRegistrationForm(): Response
    {
        // 检查权限（只有管理员可以注册新用户）
        if (!auth()->check() || !auth()->isAdmin()) {
            throw HttpException::unauthorized('只有管理员可以注册新用户');
        }
        
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册用户 - 收租管理系统</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/css/custom.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="bi bi-person-plus me-2"></i> 注册新用户
                        </h4>
                    </div>
                    <div class="card-body">
                        <form id="registerForm" action="/auth/register" method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">用户名</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">邮箱</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">密码</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="real_name" class="form-label">真实姓名</label>
                                <input type="text" class="form-control" id="real_name" name="real_name" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="role" class="form-label">角色</label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="">请选择角色</option>
                                    <option value="admin">管理员</option>
                                    <option value="landlord">房东</option>
                                </select>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">注册用户</button>
                                <a href="/users" class="btn btn-outline-secondary">返回用户列表</a>
                            </div>
                            
                            <input type="hidden" name="_token" value="' . csrf_token() . '">
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/app.js"></script>
</body>
</html>';
        
        return Response::html($html);
    }
    
    /**
     * 处理注册请求
     * 
     * @return Response 响应对象
     */
    public function register(): Response
    {
        // 检查权限
        if (!auth()->check() || !auth()->isAdmin()) {
            throw HttpException::unauthorized('只有管理员可以注册新用户');
        }
        
        // 验证CSRF令牌
        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json([
                'success' => false,
                'message' => 'CSRF令牌无效'
            ], 403);
        }
        
        // 获取输入数据
        $data = [
            'username' => $_POST['username'] ?? '',
            'email' => $_POST['email'] ?? '',
            'password' => $_POST['password'] ?? '',
            'real_name' => $_POST['real_name'] ?? '',
            'role' => $_POST['role'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'status' => 'active'
        ];
        
        try {
            // 注册用户
            $userId = auth()->register($data);
            
            return Response::json([
                'success' => true,
                'message' => '用户注册成功',
                'user_id' => $userId
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}