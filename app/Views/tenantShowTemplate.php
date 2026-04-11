<?php
// 租户详情页面模板（只读）
function tenantShowTemplate($tenant, $cohabitants, $history) {
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $status = $tenant['status'] ?? '在住';
    $statusBadge = $status === '在住' 
        ? '<span class="badge bg-success">在住</span>' 
        : '<span class="badge bg-secondary">迁出</span>';
    
    $fields = [
        ['name' => 'name', 'label' => '姓名'],
        ['name' => 'nation', 'label' => '民族'],
        ['name' => 'gender', 'label' => '性别'],
        ['name' => 'id_number', 'label' => '身份证号'],
        ['name' => 'phone', 'label' => '电话'],
        ['name' => 'address', 'label' => '户籍地址'],
        ['name' => 'status', 'label' => '状态'],
    ];
    
    $html = '';
    $html .= '<div class="row">';
    $html .= '<div class="col-lg-8">';
    
    // 租户基本信息卡片
    $html .= '<div class="card shadow-sm border-0 mb-4">';
    $html .= '<div class="card-header bg-light d-flex justify-content-between align-items-center">';
    $html .= '<h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>租户信息</h5>';
    $html .= '<div>';
    $html .= '<a href="/admin/tenants/' . (int)$tenant['id'] . '/edit" class="btn btn-sm btn-primary"><i class="bi bi-pencil-square me-1"></i>编辑</a> ';
    $html .= '<a href="/admin/tenants" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left me-1"></i>返回列表</a>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="card-body">';
    $html .= '<div class="row">';
    foreach ($fields as $f) {
        $value = $f['name'] === 'status' ? $statusBadge : htmlspecialchars($tenant[$f['name']] ?? '');
        $html .= '<div class="col-md-6 mb-3">';
        $html .= '<label class="form-label fw-semibold text-muted small">' . $f['label'] . '</label>';
        $html .= '<div class="form-control-plaintext border-bottom pb-1">' . $value . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    $html .= '</div></div>';
    
    // 入住历史记录卡片
    if (!empty($history)) {
        $html .= '<div class="card shadow-sm border-0 mb-4">';
        $html .= '<div class="card-header bg-light"><h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>入住历史记录</h5></div>';
        $html .= '<div class="card-body p-0">';
        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-hover mb-0">';
        $html .= '<thead class="table-light"><tr><th>入住日期</th><th>迁出日期</th><th>居住时长</th></tr></thead>';
        $html .= '<tbody>';
        foreach ($history as $h) {
            $start = htmlspecialchars($h['start_date']);
            $end = $h['end_date'] ? htmlspecialchars($h['end_date']) : '<em class="text-muted">至今</em>';
            $duration = '';
            if ($h['end_date']) {
                $startDate = new DateTime($h['start_date']);
                $endDate = new DateTime($h['end_date']);
                $interval = $startDate->diff($endDate);
                $duration = $interval->format('%a 天');
            } else {
                $duration = '进行中';
            }
            $html .= '<tr><td>' . $start . '</td><td>' . $end . '</td><td>' . $duration . '</td></tr>';
        }
        $html .= '</tbody></table></div></div></div>';
    }
    
    $html .= '</div>'; // 关闭 col-lg-8
    
    $html .= '<div class="col-lg-4">';
    
    // 共同居住人卡片（详细信息）
    $html .= '<div class="card shadow-sm border-0">';
    $html .= '<div class="card-header bg-light d-flex justify-content-between align-items-center">';
    $html .= '<h5 class="mb-0"><i class="bi bi-people me-2"></i>共同居住人</h5>';
    $html .= '<span class="badge bg-primary">' . count($cohabitants) . ' 人</span>';
    $html .= '</div>';
    $html .= '<div class="card-body pb-2">';
    if (!empty($cohabitants)) {
        $html .= '<div class="row g-3">';
        foreach ($cohabitants as $c) {
            $coStatus = $c['status'] ?? '在住';
            $coBadge = $coStatus === '在住' ? '<span class="badge bg-success">在住</span>' : '<span class="badge bg-secondary">迁出</span>';
            $html .= '<div class="col-12">';
            $html .= '<div class="card border h-100">';
            $html .= '<div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">';
            $html .= '<strong>' . htmlspecialchars($c['name']) . '</strong>';
            $html .= $coBadge;
            $html .= '</div>';
            $html .= '<div class="card-body p-3">';
            $html .= '<div class="row g-2">';
            // 民族
            $html .= '<div class="col-6"><small class="text-muted">民族</small><div class="fw-semibold">' . htmlspecialchars($c['nation'] ?? '') . '</div></div>';
            // 性别
            $html .= '<div class="col-6"><small class="text-muted">性别</small><div class="fw-semibold">' . htmlspecialchars($c['gender'] ?? '') . '</div></div>';
            // 身份证号
            $html .= '<div class="col-12"><small class="text-muted">身份证号</small><div class="fw-semibold text-truncate">' . htmlspecialchars($c['id_number'] ?? '') . '</div></div>';
            // 电话
            $html .= '<div class="col-12"><small class="text-muted">电话</small><div class="fw-semibold">' . htmlspecialchars($c['phone'] ?? '') . '</div></div>';
            // 地址
            $html .= '<div class="col-12"><small class="text-muted">地址</small><div class="fw-semibold small">' . htmlspecialchars($c['address'] ?? '') . '</div></div>';
            $html .= '</div>';
            $html .= '</div>'; // card-body
            $html .= '</div>'; // card
            $html .= '</div>'; // col
        }
        $html .= '</div>'; // row
    } else {
        $html .= '<p class="text-muted text-center small py-3">暂无共同居住人</p>';
    }
    $html .= '</div></div>';
    
    // 操作提示卡片
    $html .= '<div class="card shadow-sm border-0 mt-4">';
    $html .= '<div class="card-body">';
    $html .= '<h6><i class="bi bi-info-circle me-2"></i>操作提示</h6>';
    $html .= '<ul class="small text-muted mb-0 ps-3">';
    $html .= '<li>此页面仅为查看，不会保存任何修改</li>';
    $html .= '<li>如需修改租户信息，请点击“编辑”按钮</li>';
    $html .= '<li>迁出/重新迁入操作请在编辑页面进行</li>';
    $html .= '<li>共同居住人信息可在编辑页面管理</li>';
    $html .= '</ul>';
    $html .= '</div></div>';
    
    $html .= '</div>'; // 关闭 col-lg-4
    $html .= '</div>'; // 关闭 row
    
    $navbar = function_exists('app_unified_navbar') ? app_unified_navbar(['active' => 'tenants']) : '';
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>租户详情 - ' . htmlspecialchars($tenant['name']) . '</title>'
        . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css">'
        . '<link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
        . '<style>body { background-color: #f8f9fa; } .card { border-radius: 0.75rem; } .form-control-plaintext { min-height: 1.5em; }</style></head><body>'
        . $navbar
        . '<div class="container mt-4">'
        . '<h3 class="mb-4"><i class="bi bi-person-lines-fill me-2"></i>租户详情</h3>'
        . $html
        . '</div></body></html>';
}
