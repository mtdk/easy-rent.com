<?php
// 租户管理列表页模板（改进版，支持分页和筛选）
function tenantListTemplate(array $tenants, string $keyword = '', string $status = '', int $page = 1, int $totalPages = 1, int $total = 0): string {
    $rows = '';
    foreach ($tenants as $t) {
        $statusBadge = ($t['status'] ?? '在住') === '在住' 
            ? '<span class="badge bg-success">在住</span>' 
            : '<span class="badge bg-secondary">迁出</span>';
        $rows .= '<tr>'
            . '<td>' . (int)$t['id'] . '</td>'
            . '<td>' . htmlspecialchars($t['name']) . '</td>'
            . '<td>' . htmlspecialchars($t['nation'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($t['gender'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($t['id_number'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($t['phone'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($t['address'] ?? '') . '</td>'
            . '<td>' . $statusBadge . '</td>'
            . '<td class="text-nowrap">'
            . '<a href="/admin/tenants/' . (int)$t['id'] . '" class="btn btn-sm btn-info">详情</a> '
            . '<a href="/admin/tenants/' . (int)$t['id'] . '/edit" class="btn btn-sm btn-outline-primary" title="编辑"><i class="bi bi-pencil"></i></a> '
            . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="9" class="text-center text-muted py-4">暂无租户数据</td></tr>';
    }
    
    // 状态筛选选项
    $statusOptions = [
        '' => '全部状态',
        '在住' => '在住',
        '迁出' => '迁出',
    ];
    $statusSelect = '<select name="status" class="form-select" style="width: auto;">';
    foreach ($statusOptions as $value => $label) {
        $selected = $status === $value ? ' selected' : '';
        $statusSelect .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }
    $statusSelect .= '</select>';
    
    // 分页导航
    $pagination = '';
    if ($totalPages > 1) {
        $pagination .= '<nav aria-label="Page navigation"><ul class="pagination pagination-sm justify-content-center">';
        // 上一页
        if ($page > 1) {
            $prevPage = $page - 1;
            $pagination .= '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $prevPage])) . '">&laquo; 上一页</a></li>';
        }
        // 页码范围
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $page ? ' active' : '';
            $pagination .= '<li class="page-item' . $active . '"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '">' . $i . '</a></li>';
        }
        // 下一页
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $pagination .= '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $nextPage])) . '">下一页 &raquo;</a></li>';
        }
        $pagination .= '</ul></nav>';
    }
    
    $navbar = function_exists('app_unified_navbar') ? app_unified_navbar(['active' => 'tenants']) : '';
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>租户管理</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css"><style>.table th { font-weight: 600; }</style></head><body>'
        . $navbar
        . '<div class="container mt-4">'
        . '<div class="d-flex justify-content-between align-items-center mb-4">'
        . '<h3 class="mb-0"><i class="bi bi-people me-2"></i>租户管理</h3>'
        . '<a href="/admin/tenants/create" class="btn btn-success"><i class="bi bi-plus-circle me-1"></i>新建租户</a>'
        . '</div>'
        . '<div class="card shadow-sm border-0">'
        . '<div class="card-body">'
        . '<div class="row mb-3">'
        . '<div class="col-md-8">'
        . '<form method="GET" class="row g-2 align-items-center">'
        . '<div class="col-auto">'
        . '<input type="text" name="keyword" value="' . htmlspecialchars($keyword) . '" placeholder="按姓名或身份证号搜索" class="form-control">'
        . '</div>'
        . '<div class="col-auto">'
        . $statusSelect
        . '</div>'
        . '<div class="col-auto">'
        . '<button class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>搜索</button>'
        . '</div>'
        . '<div class="col-auto">'
        . '<a href="/admin/tenants" class="btn btn-outline-secondary">重置</a>'
        . '</div>'
        . '</form>'
        . '</div>'
        . '<div class="col-md-4 text-end">'
        . '<small class="text-muted">共 ' . $total . ' 位租户，第 ' . $page . ' 页 / 共 ' . $totalPages . ' 页</small>'
        . '</div>'
        . '</div>'
        . '<div class="table-responsive">'
        . '<table class="table table-hover table-bordered align-middle">'
        . '<thead class="table-light">'
        . '<tr>'
        . '<th width="60">ID</th>'
        . '<th>姓名</th>'
        . '<th>民族</th>'
        . '<th>性别</th>'
        . '<th>身份证号</th>'
        . '<th>电话</th>'
        . '<th>户籍地址</th>'
        . '<th width="80">状态</th>'
        . '<th width="130">操作</th>'
        . '</tr>'
        . '</thead>'
        . '<tbody>'
        . $rows
        . '</tbody>'
        . '</table>'
        . '</div>'
        . '<div class="d-flex justify-content-between align-items-center mt-3">'
        . '<div>' . $pagination . '</div>'
        . '<small class="text-muted">每页显示 10 条</small>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}
