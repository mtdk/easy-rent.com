<?php
// 租户管理列表页模板（改进版）
function tenantListTemplate(array $tenants, string $keyword = ''): string {
    $rows = '';
    foreach ($tenants as $t) {
        $status = $t['status'] ?? '在住';
        $statusBadge = $status === '在住' 
            ? '<span class="badge bg-success">在住</span>' 
            : '<span class="badge bg-secondary">迁出</span>';
        $rows .= '<tr>'
            . '<td>' . (int)$t['id'] . '</td>'
            . '<td>' . htmlspecialchars($t['name']) . '</td>'
            . '<td>' . htmlspecialchars($t['nation'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($t['gender']) . '</td>'
            . '<td>' . htmlspecialchars($t['id_number']) . '</td>'
            . '<td>' . htmlspecialchars($t['phone']) . '</td>'
            . '<td>' . htmlspecialchars($t['address']) . '</td>'
            . '<td>' . $statusBadge . '</td>'
            . '<td class="text-nowrap">'
            . '<a href="/admin/tenants/' . (int)$t['id'] . '" class="btn btn-sm btn-info">详情</a> '
            . '<a href="/admin/tenants/' . (int)$t['id'] . '/edit" class="btn btn-sm btn-outline-primary" title="编辑"><i class="bi bi-pencil"></i></a> ';
        // 根据状态显示迁出或重新迁入按钮
        if ($status === '在住') {
            $rows .= '<form method="POST" action="/admin/tenants/' . (int)$t['id'] . '/moveout" style="display:inline" onsubmit="return confirm(\'确认将该租户及其共同居住人全部迁出？\')">'
                . '<button type="submit" class="btn btn-sm btn-warning ms-1">迁出</button>'
                . '</form>';
        } else {
            $rows .= '<form method="POST" action="/admin/tenants/' . (int)$t['id'] . '/restore" style="display:inline" onsubmit="return confirm(\'确认重新迁入此租户？\')">'
                . '<button type="submit" class="btn btn-sm btn-success ms-1">重新迁入</button>'
                . '</form>';
        }
        $rows .= '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="9" class="text-center text-muted py-4">暂无租户数据</td></tr>';
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
        . '<div class="col-md-6">'
        . '<form method="GET" class="d-flex">'
        . '<input type="text" name="keyword" value="' . htmlspecialchars($keyword) . '" placeholder="按姓名或身份证号搜索" class="form-control me-2">'
        . '<button class="btn btn-outline-primary" style="white-space: nowrap;"><i class="bi bi-search me-1"></i>搜索</button>'
        . '</form>'
        . '</div>'
        . '<div class="col-md-6 text-end">'
        . '<small class="text-muted">共 ' . count($tenants) . ' 位租户</small>'
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
        . '<th width="180">操作</th>'
        . '</tr>'
        . '</thead>'
        . '<tbody>'
        . $rows
        . '</tbody>'
        . '</table>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}
