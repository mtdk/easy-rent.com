<?php
/**
 * 收租管理系统 - 仪表板控制器
 * 
 * 处理用户仪表板相关请求
 */

namespace App\Controllers;

use App\Core\Response;
use App\Core\HttpException;

class DashboardController
{
    /**
     * 显示仪表板
     * 
     * @return Response 响应对象
     */
    public function index(): Response
    {
        // 检查用户是否已登录
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
        
        $user = auth()->user();
        $isAdmin = auth()->isAdmin();
        $isLandlord = auth()->isLandlord();
        
        // 根据用户角色显示不同的仪表板
        if ($isAdmin) {
            return $this->adminDashboard($user);
        } elseif ($isLandlord) {
            return $this->landlordDashboard($user);
        } elseif (auth()->isTenant()) {
            return Response::redirect('/tenant/bills');
        }
        
        // 默认仪表板
        return $this->defaultDashboard($user);
    }
    
    /**
     * 管理员仪表板
     * 
     * @param array $user 用户数据
     * @return Response 响应对象
     */
    private function adminDashboard(array $user): Response
    {
        $stats = $this->buildAdminStats();
        $recentActivities = $this->fetchRecentActivities();
        $showNoDataHint = $this->isAdminBusinessDataEmpty($stats);
        
        $html = $this->dashboardTemplate('管理员仪表板', $user, $stats, $recentActivities, true, $showNoDataHint);
        return Response::html($html);
    }
    
    /**
     * 房东仪表板
     * 
     * @param array $user 用户数据
     * @return Response 响应对象
     */
    private function landlordDashboard(array $user): Response
    {
        $ownerId = (int) ($user['id'] ?? 0);
        $stats = $this->buildLandlordStats($ownerId);
        $recentActivities = $this->fetchRecentActivities($ownerId);
        $showNoDataHint = $this->isLandlordBusinessDataEmpty($stats);
        
        $html = $this->dashboardTemplate('房东仪表板', $user, $stats, $recentActivities, false, $showNoDataHint);
        return Response::html($html);
    }

    private function buildAdminStats(): array
    {
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        return [
            'total_users' => (int) db()->fetchColumn('SELECT COUNT(*) FROM users'),
            'total_properties' => (int) db()->fetchColumn('SELECT COUNT(*) FROM properties'),
            'active_contracts' => (int) db()->fetchColumn("SELECT COUNT(*) FROM contracts WHERE contract_status = 'active'"),
            'pending_payments' => (int) db()->fetchColumn("SELECT COUNT(*) FROM rent_payments WHERE payment_status IN ('pending','overdue','partial')"),
            'total_income' => (float) db()->fetchColumn("SELECT COALESCE(SUM(amount_paid), 0) FROM rent_payments WHERE payment_status IN ('paid','partial')"),
            'monthly_income' => (float) db()->fetchColumn(
                "SELECT COALESCE(SUM(amount_paid), 0) FROM rent_payments WHERE payment_status IN ('paid','partial') AND paid_date BETWEEN ? AND ?",
                [$monthStart, $monthEnd]
            ),
        ];
    }

    private function buildLandlordStats(int $ownerId): array
    {
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        return [
            'my_properties' => (int) db()->fetchColumn('SELECT COUNT(*) FROM properties WHERE owner_id = ?', [$ownerId]),
            'active_contracts' => (int) db()->fetchColumn(
                "SELECT COUNT(*)
                 FROM contracts c
                 INNER JOIN properties p ON p.id = c.property_id
                 WHERE p.owner_id = ? AND c.contract_status = 'active'",
                [$ownerId]
            ),
            'vacant_rooms' => (int) db()->fetchColumn('SELECT COALESCE(SUM(available_rooms), 0) FROM properties WHERE owner_id = ?', [$ownerId]),
            'pending_payments' => (int) db()->fetchColumn(
                "SELECT COUNT(*)
                 FROM rent_payments rp
                 INNER JOIN contracts c ON c.id = rp.contract_id
                 INNER JOIN properties p ON p.id = c.property_id
                 WHERE p.owner_id = ? AND rp.payment_status IN ('pending','overdue','partial')",
                [$ownerId]
            ),
            'total_income' => (float) db()->fetchColumn(
                "SELECT COALESCE(SUM(rp.amount_paid), 0)
                 FROM rent_payments rp
                 INNER JOIN contracts c ON c.id = rp.contract_id
                 INNER JOIN properties p ON p.id = c.property_id
                 WHERE p.owner_id = ? AND rp.payment_status IN ('paid','partial')",
                [$ownerId]
            ),
            'monthly_income' => (float) db()->fetchColumn(
                "SELECT COALESCE(SUM(rp.amount_paid), 0)
                 FROM rent_payments rp
                 INNER JOIN contracts c ON c.id = rp.contract_id
                 INNER JOIN properties p ON p.id = c.property_id
                 WHERE p.owner_id = ? AND rp.payment_status IN ('paid','partial') AND rp.paid_date BETWEEN ? AND ?",
                [$ownerId, $monthStart, $monthEnd]
            ),
        ];
    }

    private function fetchRecentActivities(int $ownerId = 0): array
    {
        if ($ownerId > 0) {
            $rows = db()->fetchAll(
                "SELECT title, created_at
                 FROM notifications
                 WHERE user_id = ?
                 ORDER BY id DESC
                 LIMIT 5",
                [$ownerId]
            );

            $activities = [];
            foreach ($rows as $row) {
                $activities[] = [
                    'user' => '系统',
                    'action' => (string) ($row['title'] ?? '系统通知'),
                    'time' => (string) ($row['created_at'] ?? '-'),
                ];
            }

            return $activities;
        }

        $rows = db()->fetchAll(
            "SELECT al.action, al.created_at, u.username
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.id DESC
             LIMIT 5"
        );

        $activities = [];
        foreach ($rows as $row) {
            $activities[] = [
                'user' => (string) (($row['username'] ?? '') !== '' ? $row['username'] : '系统'),
                'action' => '执行操作：' . (string) ($row['action'] ?? 'unknown'),
                'time' => (string) ($row['created_at'] ?? '-'),
            ];
        }

        return $activities;
    }
    
    /**
     * 默认仪表板
     * 
     * @param array $user 用户数据
     * @return Response 响应对象
     */
    private function defaultDashboard(array $user): Response
    {
        $stats = [
            'welcome' => '欢迎使用收租管理系统',
            'message' => '请等待管理员分配具体角色和权限'
        ];
        
        $html = $this->dashboardTemplate('用户仪表板', $user, $stats, [], false, false);
        return Response::html($html);
    }
    
    /**
     * 仪表板模板
     * 
     * @param string $title 标题
     * @param array $user 用户数据
     * @param array $stats 统计数据
     * @param array $activities 最近活动
     * @param bool $isAdmin 是否是管理员
     * @return string HTML内容
     */
    private function dashboardTemplate(string $title, array $user, array $stats, array $activities, bool $isAdmin = false, bool $showNoDataHint = false): string
    {
        $userName = htmlspecialchars($user['real_name'] ?? $user['username']);
        $userRole = $isAdmin ? '管理员' : '房东';
        
        // 生成统计卡片
        $statsCards = '';
        foreach ($stats as $key => $value) {
            $label = $this->getStatLabel($key);
            $icon = $this->getStatIcon($key);
            $color = $this->getStatColor($key);
            
            $statsCards .= '
            <div class="col-md-4 mb-4">
                <div class="card border-' . $color . '">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">' . $label . '</h6>
                                <h3 class="card-title">' . $this->formatStatValue($key, $value) . '</h3>
                            </div>
                            <div class="display-4 text-' . $color . '">
                                <i class="bi ' . $icon . '"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
        }
        
        // 生成活动列表
        $activitiesList = '';
        foreach ($activities as $activity) {
            $activitiesList .= '
            <div class="list-group-item">
                <div class="d-flex w-100 justify-content-between">
                    <h6 class="mb-1">' . htmlspecialchars($activity['action']) . '</h6>
                    <small class="text-muted">' . htmlspecialchars($activity['time']) . '</small>
                </div>
                <small class="text-muted">' . htmlspecialchars($activity['user']) . '</small>
            </div>';
        }
        
        if (empty($activitiesList)) {
            $activitiesList = '<div class="text-center py-4 text-muted">暂无活动记录</div>';
        }

        $noDataHintHtml = '';
        if ($showNoDataHint) {
            $noDataHintHtml = '<div class="alert alert-warning d-flex align-items-start gap-2 mb-4">'
                . '<i class="bi bi-exclamation-circle-fill mt-1"></i>'
                . '<div><strong>当前为初始化阶段</strong><div class="small mb-0">尚未录入业务数据，因此房产、合同、收款与收入指标暂为 0。</div></div>'
                . '</div>';
        }
        
        // 生成快速操作按钮
        $quickActions = $isAdmin ? $this->adminQuickActions() : $this->landlordQuickActions();
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'dashboard',
            'is_admin' => $isAdmin,
            'show_user_menu' => true,
            'user_label' => $isAdmin ? '系统管理员' : $userName,
            'collapse_id' => 'dashboardMainNavbar',
        ]);
        
        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . ' - 收租管理系统</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/css/custom.css">
    ' . $navbarStyles . '
    <style>
        .dashboard-main {
            padding-top: 0;
            flex: 1 0 auto;
        }

        .dashboard-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 40px 0;
            margin-bottom: 30px;
        }
        
        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: #4f46e5;
            margin-right: 20px;
        }
        
        .quick-action-card {
            border: none;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: all 0.3s;
            height: 100%;
        }
        
        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        }
        
        .quick-action-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .stat-card {
            border-left: 4px solid;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100 bg-light">
    <!-- 导航栏 -->
    ' . $navigation . '

    <main class="dashboard-main">

    <!-- 仪表板头部 -->
    <div class="dashboard-header">
        <div class="container">
            <div class="d-flex align-items-center">
                <div class="user-avatar">
                    <i class="bi bi-person-fill"></i>
                </div>
                <div>
                    <h1 class="display-6">' . $title . '</h1>
                    <p class="lead mb-0">欢迎回来，' . $userName . ' (' . $userRole . ')</p>
                    <small>最后登录: ' . ($user['last_login_at'] ?? '从未登录') . '</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 主要内容 -->
    <div class="container">
        ' . $noDataHintHtml . '
        <!-- 统计卡片 -->
        <div class="row mb-5">
            ' . $statsCards . '
        </div>

        <div class="row">
            <!-- 快速操作 -->
            <div class="col-lg-8 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-lightning-charge me-2"></i> 快速操作
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            ' . $quickActions . '
                        </div>
                    </div>
                </div>
            </div>

            <!-- 最近活动 -->
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history me-2"></i> 最近活动
                        </h5>
                    </div>
                    <div class="list-group list-group-flush">
                        ' . $activitiesList . '
                    </div>
                </div>
            </div>
        </div>

        <!-- 系统信息 -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-info-circle me-2"></i> 系统信息
                </h5>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><i class="bi bi-check-circle-fill text-success me-2"></i> 系统版本: 1.0.0</li>
                            <li><i class="bi bi-check-circle-fill text-success me-2"></i> 数据库状态: 正常</li>
                            <li><i class="bi bi-check-circle-fill text-success me-2"></i> 会话状态: 活跃</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><i class="bi bi-calendar-check me-2"></i> 当前日期: ' . date('Y-m-d') . '</li>
                            <li><i class="bi bi-clock me-2"></i> 当前时间: ' . date('H:i:s') . '</li>
                            <li><i class="bi bi-server me-2"></i> 服务器: ' . php_uname('n') . '</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </main>

    <!-- 页脚 -->
    <footer class="footer mt-auto py-3 bg-body-tertiary border-top">
        <div class="container">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                <div>
                    <span class="text-body-secondary"><i class="bi bi-house-door me-1"></i> 收租管理系统 · 版本 1.0.0</span>
                </div>
                <div>
                    <span class="text-body-secondary"><i class="bi bi-cpu me-1"></i> PHP ' . PHP_VERSION . ' & MariaDB</span>
                </div>
            </div>
        </div>
    </footer>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        // 页面加载完成
        document.addEventListener(\'DOMContentLoaded\', function() {
            console.log(\'仪表板已加载\');
            
            // 更新页面标题
            document.title = \'' . $title . ' - 收租管理系统\';
            
            // 初始化工具提示
            if (typeof bootstrap !== \'undefined\') {
                var tooltipTriggerList = [].slice.call(document.querySelectorAll(\'[data-bs-toggle="tooltip"]\'));
                tooltipTriggerList.map(function(tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }
        });
    </script>
</body>
</html>';
    }

    private function isAdminBusinessDataEmpty(array $stats): bool
    {
        return ((int) ($stats['total_properties'] ?? 0)) === 0
            && ((int) ($stats['active_contracts'] ?? 0)) === 0
            && ((int) ($stats['pending_payments'] ?? 0)) === 0
            && ((float) ($stats['total_income'] ?? 0)) == 0.0
            && ((float) ($stats['monthly_income'] ?? 0)) == 0.0;
    }

    private function isLandlordBusinessDataEmpty(array $stats): bool
    {
        return ((int) ($stats['my_properties'] ?? 0)) === 0
            && ((int) ($stats['active_contracts'] ?? 0)) === 0
            && ((int) ($stats['vacant_rooms'] ?? 0)) === 0
            && ((int) ($stats['pending_payments'] ?? 0)) === 0
            && ((float) ($stats['total_income'] ?? 0)) == 0.0
            && ((float) ($stats['monthly_income'] ?? 0)) == 0.0;
    }
    
    /**
     * 获取统计标签
     * 
     * @param string $key 统计键
     * @return string 统计标签
     */
    private function getStatLabel(string $key): string
    {
        $labels = [
            'total_users' => '总用户数',
            'total_properties' => '总房产数',
            'active_contracts' => '活跃合同',
            'pending_payments' => '待收租金',
            'total_income' => '总收入',
            'monthly_income' => '本月收入',
            'my_properties' => '我的房产',
            'vacant_rooms' => '空置房间',
            'welcome' => '欢迎',
            'message' => '消息'
        ];
        
        return $labels[$key] ?? $key;
    }
    
    /**
     * 获取统计图标
     * 
     * @param string $key 统计键
     * @return string 统计图标
     */
    private function getStatIcon(string $key): string
    {
        $icons = [
            'total_users' => 'bi-people-fill',
            'total_properties' => 'bi-houses-fill',
            'active_contracts' => 'bi-file-earmark-check-fill',
            'pending_payments' => 'bi-hourglass-split',
            'total_income' => 'bi-cash-stack',
            'monthly_income' => 'bi-graph-up-arrow',
            'my_properties' => 'bi-house-heart-fill',
            'vacant_rooms' => 'bi-door-open-fill',
            'welcome' => 'bi-stars',
            'message' => 'bi-chat-dots-fill'
        ];
        
        return $icons[$key] ?? 'bi-info-circle';
    }
    
    /**
     * 获取统计颜色
     *
     * @param string $key 统计键
     * @return string 统计颜色
     */
    private function getStatColor(string $key): string
    {
        $colors = [
            'total_users' => 'primary',
            'total_properties' => 'success',
            'active_contracts' => 'info',
            'pending_payments' => 'warning',
            'total_income' => 'danger',
            'monthly_income' => 'primary',
            'my_properties' => 'success',
            'vacant_rooms' => 'warning',
            'welcome' => 'info',
            'message' => 'secondary'
        ];
        
        return $colors[$key] ?? 'secondary';
    }
    
    /**
     * 格式化统计值
     *
     * @param string $key 统计键
     * @param mixed $value 统计值
     * @return string 格式化后的值
     */
    private function formatStatValue(string $key, $value): string
    {
        if (strpos($key, 'income') !== false || strpos($key, 'total_income') !== false || strpos($key, 'monthly_income') !== false) {
            return '¥' . number_format($value, 2);
        }
        
        if (is_numeric($value)) {
            return number_format($value);
        }
        
        return htmlspecialchars($value);
    }
    
    /**
     * 管理员快速操作
     *
     * @return string HTML内容
     */
    private function adminQuickActions(): string
    {
        $actions = [
            ['icon' => 'bi-person-plus-fill', 'title' => '添加用户', 'description' => '添加新用户账户', 'url' => '/users/create', 'color' => 'primary'],
            ['icon' => 'bi-house-add-fill', 'title' => '添加房产', 'description' => '添加新的房产信息', 'url' => '/properties/create', 'color' => 'success'],
            ['icon' => 'bi-file-earmark-plus-fill', 'title' => '创建合同', 'description' => '创建新的租赁合同', 'url' => '/contracts/create', 'color' => 'info'],
            ['icon' => 'bi-wallet2', 'title' => '租金管理', 'description' => '查看和管理租金支付', 'url' => '/payments', 'color' => 'warning'],
            ['icon' => 'bi-bar-chart-line-fill', 'title' => '财务报表', 'description' => '查看财务统计报表', 'url' => '/reports/financial', 'color' => 'danger'],
            ['icon' => 'bi-sliders2-vertical', 'title' => '系统设置', 'description' => '管理系统配置', 'url' => '/settings', 'color' => 'secondary']
        ];
        
        $html = '';
        foreach ($actions as $action) {
            $html .= '
            <div class="col-md-4">
                <a href="' . $action['url'] . '" class="card quick-action-card text-decoration-none">
                    <div class="card-body text-center">
                        <div class="quick-action-icon text-' . $action['color'] . '">
                            <i class="bi ' . $action['icon'] . '"></i>
                        </div>
                        <h5 class="card-title">' . $action['title'] . '</h5>
                        <p class="card-text text-muted">' . $action['description'] . '</p>
                    </div>
                </a>
            </div>';
        }
        
        return $html;
    }
    
    /**
     * 房东快速操作
     *
     * @return string HTML内容
     */
    private function landlordQuickActions(): string
    {
        $actions = [
            ['icon' => 'bi-house-add-fill', 'title' => '添加房产', 'description' => '添加新的房产信息', 'url' => '/properties/create', 'color' => 'success'],
            ['icon' => 'bi-file-earmark-plus-fill', 'title' => '创建合同', 'description' => '创建新的租赁合同', 'url' => '/contracts/create', 'color' => 'info'],
            ['icon' => 'bi-cash-stack', 'title' => '收取租金', 'description' => '记录租金收取情况', 'url' => '/payments/create', 'color' => 'warning'],
            ['icon' => 'bi-card-checklist', 'title' => '合同管理', 'description' => '管理租赁合同', 'url' => '/contracts', 'color' => 'primary'],
            ['icon' => 'bi-bell-fill', 'title' => '提醒中心', 'description' => '查看与处理提醒通知', 'url' => '/notifications', 'color' => 'danger'],
            ['icon' => 'bi-graph-up-arrow', 'title' => '我的报表', 'description' => '查看个人报表', 'url' => '/reports/personal', 'color' => 'secondary']
        ];
        
        $html = '';
        foreach ($actions as $action) {
            $html .= '
            <div class="col-md-4">
                <a href="' . $action['url'] . '" class="card quick-action-card text-decoration-none">
                    <div class="card-body text-center">
                        <div class="quick-action-icon text-' . $action['color'] . '">
                            <i class="bi ' . $action['icon'] . '"></i>
                        </div>
                        <h5 class="card-title">' . $action['title'] . '</h5>
                        <p class="card-text text-muted">' . $action['description'] . '</p>
                    </div>
                </a>
            </div>';
        }
        
        return $html;
    }

}
