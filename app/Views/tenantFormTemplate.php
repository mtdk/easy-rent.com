<?php
// 租户表单模板（含共同居住人管理及入住历史记录，改进版）
function tenantFormTemplate($title, $action, $method, $tenant = [], $cohabitants = [], $history = []) {
    $isEdit = !empty($tenant['id']);
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $status = $tenant['status'] ?? '在住';
    $statusBadge = $status === '在住'
        ? '<span class="badge bg-success">在住</span>'
        : '<span class="badge bg-secondary">迁出</span>';
    
    // 新建租户时设置默认入住日期
    if (!$isEdit) {
        $tenant['move_in_date'] = $tenant['move_in_date'] ?? date('Y-m-d');
    }
    
    $fields = [
        ['name' => 'name', 'label' => '姓名', 'type' => 'text'],
        ['name' => 'nation', 'label' => '民族', 'type' => 'text'],
        ['name' => 'gender', 'label' => '性别', 'type' => 'select', 'options' => ['男', '女']],
        ['name' => 'id_number', 'label' => '身份证号', 'type' => 'text'],
        ['name' => 'phone', 'label' => '电话', 'type' => 'text'],
        ['name' => 'address', 'label' => '户籍地址', 'type' => 'text'],
    ];
    
    // 新建租户时添加入住日期字段
    if (!$isEdit) {
        $fields[] = ['name' => 'move_in_date', 'label' => '入住日期', 'type' => 'date'];
    }
    
    $form = '';
    $form .= '<div class="row">';
    $form .= '<div class="col-lg-8">';
    
    // 租户基本信息卡片
    $form .= '<div class="card shadow-sm border-0 mb-4">';
    $form .= '<div class="card-header bg-light d-flex justify-content-between align-items-center">';
    $form .= '<h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>租户信息</h5>';
    $form .= '<div>状态：' . $statusBadge . '</div>';
    $form .= '</div>';
    $form .= '<div class="card-body">';
    $form .= '<form method="POST" action="' . htmlspecialchars($action) . '">';
    $form .= '<input type="hidden" name="_token" value="' . $csrf . '">';
    $form .= '<div class="row">';
    foreach ($fields as $i => $f) {
        $value = $tenant[$f['name']] ?? '';
        $form .= '<div class="col-md-6 mb-3">';
        $form .= '<label class="form-label fw-semibold">' . $f['label'] . '</label>';
        if ($f['type'] === 'select') {
            $form .= '<select name="' . $f['name'] . '" class="form-select">';
            foreach ($f['options'] as $opt) {
                $form .= '<option value="' . $opt . '"' . ($value === $opt ? ' selected' : '') . '>' . $opt . '</option>';
            }
            $form .= '</select>';
        } else {
            $form .= '<input type="' . $f['type'] . '" name="' . $f['name'] . '" class="form-control" value="' . htmlspecialchars($value) . '">';
        }
        $form .= '</div>';
    }
    $form .= '</div>';
    $form .= '<div class="d-flex gap-2 pt-2">';
    $form .= '<button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>保存</button>';
    $form .= '<a href="/admin/tenants" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>返回列表</a>';
    $form .= '</div>';
    $form .= '</form>';
    $form .= '</div></div>';
    
    // 入住历史记录卡片（仅编辑模式）
    if ($isEdit && !empty($history)) {
        $form .= '<div class="card shadow-sm border-0 mb-4">';
        $form .= '<div class="card-header bg-light"><h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>入住历史记录</h5></div>';
        $form .= '<div class="card-body p-0">';
        $form .= '<div class="table-responsive">';
        $form .= '<table class="table table-hover mb-0">';
        $form .= '<thead class="table-light"><tr><th>入住日期</th><th>迁出日期</th><th>居住时长</th></tr></thead>';
        $form .= '<tbody>';
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
            $form .= '<tr><td>' . $start . '</td><td>' . $end . '</td><td>' . $duration . '</td></tr>';
        }
        $form .= '</tbody></table></div></div></div>';
    }
    
    $form .= '</div>'; // 关闭 col-lg-8
    
    $form .= '<div class="col-lg-4">';
    
    // 迁出/重新迁入操作卡片
    if ($isEdit) {
        $form .= '<div class="card shadow-sm border-0 mb-4">';
        $form .= '<div class="card-header bg-light"><h5 class="mb-0"><i class="bi bi-house-door me-2"></i>居住操作</h5></div>';
        $form .= '<div class="card-body">';
        if ($status === '在住') {
            $form .= '<form method="POST" action="' . htmlspecialchars($action) . '/moveout" onsubmit="return confirm(\'确认将该租户及其共同居住人全部迁出？\')">';
            $form .= '<input type="hidden" name="_token" value="' . $csrf . '">';
            $form .= '<div class="mb-3">';
            $form .= '<label class="form-label small">迁出日期</label>';
            $form .= '<input type="date" name="move_out_date" class="form-control" value="' . date('Y-m-d') . '">';
            $form .= '</div>';
            $form .= '<button class="btn btn-warning w-100 mb-2"><i class="bi bi-box-arrow-right me-1"></i>迁出租户</button>';
            $form .= '<p class="text-muted small">租户迁出后，其所有共同居住人也将被标记为迁出，并结束当前入住记录。</p>';
            $form .= '</form>';
        } else {
            $form .= '<form method="POST" action="' . htmlspecialchars($action) . '/restore" onsubmit="return confirm(\'确认重新迁入此租户？\')">';
            $form .= '<input type="hidden" name="_token" value="' . $csrf . '">';
            $form .= '<button class="btn btn-success w-100 mb-2"><i class="bi bi-box-arrow-in-left me-1"></i>重新迁入</button>';
            $form .= '<p class="text-muted small">重新迁入将创建新的入住记录，并将租户状态改为在住。</p>';
            $form .= '</form>';
        }
        $form .= '</div></div>';
    }
    
    // 共同居住人管理卡片
    if ($isEdit) {
        $form .= '<div class="card shadow-sm border-0">';
        $form .= '<div class="card-header bg-light d-flex justify-content-between align-items-center">';
        $form .= '<h5 class="mb-0"><i class="bi bi-people me-2"></i>共同居住人</h5>';
        $form .= '<span class="badge bg-primary">' . count($cohabitants) . ' 人</span>';
        $form .= '</div>';
        $form .= '<div class="card-body pb-2">';
        
        // 添加共同居住人表单
        $form .= '<form method="POST" action="/admin/tenants/' . (int)$tenant['id'] . '/cohabitants/save" class="mb-3">';
        $form .= '<input type="hidden" name="_token" value="' . $csrf . '"><input type="hidden" name="id" value="">';
        $coFields = [
            ['name' => 'name', 'label' => '姓名', 'type' => 'text'],
            ['name' => 'nation', 'label' => '民族', 'type' => 'text'],
            ['name' => 'gender', 'label' => '性别', 'type' => 'select', 'options' => ['男', '女']],
            ['name' => 'id_number', 'label' => '身份证号', 'type' => 'text'],
            ['name' => 'phone', 'label' => '电话', 'type' => 'text'],
            ['name' => 'address', 'label' => '户籍地址', 'type' => 'text'],
        ];
        foreach ($coFields as $i => $f) {
            $form .= '<div class="mb-2">';
            $form .= '<label class="form-label small">' . $f['label'] . '</label>';
            if ($f['type'] === 'select') {
                $form .= '<select name="' . $f['name'] . '" class="form-select form-select-sm">';
                foreach ($f['options'] as $opt) {
                    $form .= '<option value="' . $opt . '">' . $opt . '</option>';
                }
                $form .= '</select>';
            } else {
                $form .= '<input type="' . $f['type'] . '" name="' . $f['name'] . '" class="form-control form-control-sm">';
            }
            $form .= '</div>';
        }
        $form .= '<button class="btn btn-success w-100 mt-2 btn-sm"><i class="bi bi-plus-circle me-1"></i>添加共同居住人</button>';
        $form .= '</form>';
        
        // 共同居住人列表
        if (!empty($cohabitants)) {
            $form .= '<div class="table-responsive">';
            $form .= '<table class="table table-sm table-borderless align-middle">';
            $form .= '<thead class="bg-light"><tr>';
            $form .= '<th>姓名</th><th>状态</th><th>操作</th>';
            $form .= '</tr></thead><tbody>';
            foreach ($cohabitants as $c) {
                $coStatus = $c['status'] ?? '在住';
                $coBadge = $coStatus === '在住' ? '<span class="badge bg-success">在住</span>' : '<span class="badge bg-secondary">迁出</span>';
                $form .= '<tr>';
                $form .= '<td>' . htmlspecialchars($c['name']) . '</td>';
                $form .= '<td>' . $coBadge . '</td>';
                $form .= '<td class="text-nowrap">';
                $form .= '<a href="/admin/tenants/' . (int)$tenant['id'] . '/cohabitants/' . (int)$c['id'] . '/edit" class="btn btn-xs btn-outline-primary btn-sm me-1" title="编辑"><i class="bi bi-pencil"></i></a>';
                if ($coStatus === '在住') {
                    $form .= '<form method="POST" action="/admin/tenants/' . (int)$tenant['id'] . '/cohabitants/' . (int)$c['id'] . '/moveout" style="display:inline">';
                    $form .= '<input type="hidden" name="_token" value="' . $csrf . '">';
                    $form .= '<button class="btn btn-xs btn-outline-warning btn-sm" title="迁出"><i class="bi bi-box-arrow-right"></i></button>';
                    $form .= '</form>';
                }
                $form .= '</td>';
                $form .= '</tr>';
            }
            $form .= '</tbody></table></div>';
        } else {
            $form .= '<p class="text-muted text-center small py-3">暂无共同居住人</p>';
        }
        $form .= '</div></div>';
    }
    
    $form .= '</div>'; // 关闭 col-lg-4
    $form .= '</div>'; // 关闭 row
    
    $navbar = function_exists('app_unified_navbar') ? app_unified_navbar(['active' => 'tenants']) : '';
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css">'
        . '<link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
        . '<style>body { background-color: #f8f9fa; } .card { border-radius: 0.75rem; } .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }</style></head><body>'
        . $navbar
        . '<div class="container mt-4">'
        . '<h3 class="mb-4"><i class="bi bi-person-lines-fill me-2"></i>' . htmlspecialchars($title) . '</h3>'
        . $form
        . '</div></body></html>';
}
