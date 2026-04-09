<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>收租管理系统 - 首页</title>
    
    <!-- Bootstrap 5.3 本地化资源 -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    
    <!-- 自定义CSS -->
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', 'Microsoft YaHei', sans-serif;
        }
        
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .system-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .feature-card {
            transition: transform 0.3s;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .feature-icon {
            font-size: 2.5rem;
            color: #3498db;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-house-door-fill me-2"></i>
                收租管理系统
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#">
                            <i class="bi bi-house me-1"></i> 首页
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-info-circle me-1"></i> 关于
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-gear me-1"></i> 设置
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- 主内容区 -->
    <div class="container mt-5">
        <!-- 系统标题 -->
        <div class="text-center mb-5">
            <h1 class="display-4 text-primary">
                <i class="bi bi-house-heart"></i> 收租管理系统
            </h1>
            <p class="lead text-muted">专为房东和管理员设计的本地化租金管理解决方案</p>
            <div class="mb-3">
                <a href="/auth/login" class="btn btn-primary btn-lg">
                    <i class="bi bi-box-arrow-in-right me-2"></i>前往登录页
                </a>
            </div>
            <div class="alert alert-info d-inline-block">
                <i class="bi bi-wifi-off me-2"></i>
                完全离线运行 · 数据本地存储 · 局域网环境优化
            </div>
        </div>

        <!-- 功能特性展示 -->
        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="card feature-card h-100">
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <h5 class="card-title">用户管理</h5>
                        <p class="card-text">支持管理员、房东（含租客端入口）角色体系，提供账号新增、状态维护与访问权限隔离。</p>
                        <a href="#" class="btn btn-outline-primary">了解更多</a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card feature-card h-100">
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="bi bi-building"></i>
                        </div>
                        <h5 class="card-title">房产管理</h5>
                        <p class="card-text">维护房产基础信息与状态，支持按城市/区域筛选，并可联动查看合同、账单与出租率统计。</p>
                        <a href="#" class="btn btn-outline-primary">了解更多</a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card feature-card h-100">
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                        <h5 class="card-title">合同管理</h5>
                        <p class="card-text">覆盖合同创建、编辑与状态管理，支持到期提醒、一键续约及租客信息关联，便于日常跟进。</p>
                        <a href="#" class="btn btn-outline-primary">了解更多</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 登录/注册区域 -->
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="login-container">
                    <h3 class="system-title">
                        <i class="bi bi-box-arrow-in-right me-2"></i>系统登录
                    </h3>
                    
                    <div class="d-grid gap-2">
                        <a href="/auth/login" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right me-2"></i> 前往登录页
                        </a>
                        <a href="/auth/register" class="btn btn-outline-secondary">
                            <i class="bi bi-person-plus me-2"></i> 注册新账户
                        </a>
                    </div>
                    
                    <div class="mt-3 text-center">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            支持管理员和房东两种角色登录
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 系统信息 -->
        <div class="row mt-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-info-circle me-2"></i>系统信息
                        </h5>
                        <div class="row">
                            <div class="col-md-6">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        <strong>技术栈：</strong> PHP 8.2+ + MariaDB + Bootstrap 5.3
                                    </li>
                                    <li class="list-group-item">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        <strong>部署环境：</strong> 纯局域网，无外部网络依赖
                                    </li>
                                    <li class="list-group-item">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                        <strong>资源状态：</strong> Bootstrap资源已完全本地化
                                    </li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item">
                                        <i class="bi bi-clock-history me-2"></i>
                                        <strong>开发阶段：</strong> 阶段一 - 环境搭建与资源准备
                                    </li>
                                    <li class="list-group-item">
                                        <i class="bi bi-people me-2"></i>
                                        <strong>用户角色：</strong> 管理员、房东（租客功能预留）
                                    </li>
                                    <li class="list-group-item">
                                        <i class="bi bi-calendar-check me-2"></i>
                                        <strong>开发计划：</strong> 11周完整开发周期
                                    </li>
                                </ul>
                            </div>
                        </div>
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

    <!-- Bootstrap JS Bundle (本地化) -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    
</body>
</html>