<?php
/**
 * 收租管理系统 - 首页控制器
 * 
 * 处理首页和公共页面请求
 */

namespace App\Controllers;

use App\Core\Response;

class HomeController
{
    /**
     * 显示首页
     * 
     * @return Response 响应对象
     */
    public function index(): Response
    {
        // 读取public/index.php的内容并返回
        $content = file_get_contents(PUBLIC_PATH . '/index.php');
        
        return Response::html($content);
    }
    
    /**
     * 显示关于页面
     * 
     * @return Response 响应对象
     */
    public function about(): Response
    {
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>关于 - 收租管理系统</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/css/custom.css">
    <style>
        .about-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 80px 0;
            text-align: center;
            margin-bottom: 50px;
        }
        
        .feature-icon {
            font-size: 3rem;
            color: #4f46e5;
            margin-bottom: 20px;
        }
        
        .feature-card {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s;
            height: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
        }
        
        .tech-stack {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="bi bi-house-door-fill me-2"></i>
                收租管理系统
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/">
                            <i class="bi bi-house me-1"></i> 首页
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="/about">
                            <i class="bi bi-info-circle me-1"></i> 关于
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/auth/login">
                            <i class="bi bi-box-arrow-in-right me-1"></i> 登录
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- 关于头部 -->
    <div class="about-header">
        <div class="container">
            <h1 class="display-4">
                <i class="bi bi-info-circle-fill me-3"></i>关于收租管理系统
            </h1>
            <p class="lead">专为局域网环境设计的现代化租金管理解决方案</p>
        </div>
    </div>

    <!-- 主要内容 -->
    <div class="container">
        <!-- 系统介绍 -->
        <div class="row mb-5">
            <div class="col-lg-8 mx-auto">
                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">系统简介</h3>
                        <p class="card-text">
                            收租管理系统是一款专为房东和管理员设计的租金管理软件，特别针对局域网环境优化，
                            所有资源完全本地化，无需外部网络连接即可正常运行。
                        </p>
                        <p class="card-text">
                            系统采用现代化的PHP 8.2+架构，结合MariaDB数据库和Bootstrap 5.3前端框架，
                            提供了直观易用的用户界面和强大的功能支持。
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 核心特性 -->
        <h2 class="text-center mb-4">核心特性</h2>
        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="card feature-card">
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="bi bi-wifi-off"></i>
                        </div>
                        <h5 class="card-title">完全离线运行</h5>
                        <p class="card-text">
                            Bootstrap资源完全本地化，支持纯局域网环境运行，
                            无需连接外部网络，确保数据安全和隐私。
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card feature-card">
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h5 class="card-title">安全可靠</h5>
                        <p class="card-text">
                            采用密码哈希存储、CSRF防护、SQL注入防护等多重安全措施，
                            确保用户数据和系统安全。
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card feature-card">
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <h5 class="card-title">两级权限系统</h5>
                        <p class="card-text">
                            支持管理员和房东两种角色，权限分离明确，
                            管理员拥有完全控制权，房东只能管理自己的房产。
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 技术栈 -->
        <div class="tech-stack mb-5">
            <h3 class="text-center mb-4">技术栈</h3>
            <div class="row">
                <div class="col-md-6">
                    <h5>后端技术</h5>
                    <ul>
                        <li><strong>PHP 8.2+</strong> - 现代化的PHP版本，性能优异</li>
                        <li><strong>MariaDB 10.6+</strong> - 高性能关系型数据库</li>
                        <li><strong>MVC架构</strong> - 清晰的代码分离和可维护性</li>
                        <li><strong>PDO扩展</strong> - 安全的数据库操作接口</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h5>前端技术</h5>
                    <ul>
                        <li><strong>Bootstrap 5.3</strong> - 响应式前端框架（完全本地化）</li>
                        <li><strong>Bootstrap Icons 1.11</strong> - 图标库（完全本地化）</li>
                        <li><strong>JavaScript ES6+</strong> - 现代化的前端交互</li>
                        <li><strong>AJAX/Fetch API</strong> - 异步数据加载</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 功能模块 -->
        <h2 class="text-center mb-4">功能模块</h2>
        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <i class="bi bi-people feature-icon"></i>
                        <h6>用户管理</h6>
                        <small>管理员和房东账户管理</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <i class="bi bi-building feature-icon"></i>
                        <h6>房产管理</h6>
                        <small>房产信息与房间配置</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <i class="bi bi-file-earmark-text feature-icon"></i>
                        <h6>合同管理</h6>
                        <small>租赁合同与租客信息</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <i class="bi bi-cash-coin feature-icon"></i>
                        <h6>租金管理</h6>
                        <small>租金收取与支付记录</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 开发计划 -->
        <div class="card mb-5">
            <div class="card-body">
                <h3 class="card-title">开发计划</h3>
                <p class="card-text">
                    收租管理系统按照11周开发计划进行，目前已完成第一阶段（环境搭建与资源准备）
                    和第二阶段的部分功能（用户认证与权限管理）。
                </p>
                <div class="progress mb-3">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                         role="progressbar" style="width: 25%" 
                         aria-valuenow="25" aria-valuemin="0" aria-valuemax="100">
                        已完成 25%
                    </div>
                </div>
                <p class="card-text">
                    <strong>当前阶段：</strong> 阶段二 - 核心功能开发（第2-8周）<br>
                    <strong>下一阶段：</strong> 房产管理模块开发
                </p>
            </div>
        </div>

        <!-- 系统要求 -->
        <div class="card">
            <div class="card-body">
                <h3 class="card-title">系统要求</h3>
                <div class="row">
                    <div class="col-md-6">
                        <h5>服务器要求</h5>
                        <ul>
                            <li>PHP 8.2.0 或更高版本</li>
                            <li>MariaDB 10.6+ 或 MySQL 5.7+</li>
                            <li>Apache 2.4+ 或 Nginx 1.18+</li>
                            <li>至少 100MB 可用磁盘空间</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h5>PHP扩展要求</h5>
                        <ul>
                            <li>PDO 扩展（pdo_mysql）</li>
                            <li>MBString 扩展</li>
                            <li>JSON 扩展</li>
                            <li>OpenSSL 扩展</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 页脚 -->
    <footer class="bg-dark text-white mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="bi bi-house-door me-2"></i>收租管理系统</h5>
                    <p class="mb-0">专为局域网环境设计的租金管理解决方案</p>
                    <small class="text-muted">版本 1.0.0 · 开发中</small>
                </div>
                <div class="col-md-6 text-end">
                    <p class="mb-1">
                        <i class="bi bi-cpu me-1"></i> PHP 8.2+ & MariaDB
                    </p>
                    <p class="mb-0">
                        <i class="bi bi-bootstrap me-1"></i> Bootstrap 5.3 (本地化)
                    </p>
                </div>
            </div>
            <hr class="bg-secondary">
            <div class="text-center">
                <small>&copy; 2026 收租管理系统. 所有资源均已本地化，支持离线使用.</small>
            </div>
        </div>
    </footer>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/app.js"></script>
</body>
</html>';
        
        return Response::html($html);
    }
}