<?php
// 租户管理列表页模板（简化版）
function tenantListTemplate(array $tenants, string $keyword = ''): string {
    $rows = '';
    foreach ($tenants as $t) {
        $rows .= '<tr>'
            . '<td>' . (int)$t['id'] . '</td>'
            . '<td>' . htmlspecialchars($t['name']) . '</td>'
            . '<td>' . htmlspecialchars($t['nation'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($t['gender']) . '</td>'
            . '<td>' . htmlspecialchars($t['id_number']) . '</td>'
            . '<td>' . htmlspecialchars($t['phone']) . '</td>'
            . '<td>' . htmlspecialchars($t['address']) . '</td>'
            . '<td>'
            . '<a href="/admin/tenants/' . (int)$t['id'] . '/edit" class="btn btn-sm btn-primary">编辑</a> '
            . '<form method="POST" action="/admin/tenants/' . (int)$t['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'确认删除？\')">'
            . '<button type="submit" class="btn btn-sm btn-danger">删除</button></form> '
            . '<form method="POST" action="/admin/tenants/' . (int)$t['id'] . '/moveout" style="display:inline" onsubmit="return confirm(\'确认将该租户及其共同居住人全部迁出？\')">'
            . '<button type="submit" class="btn btn-sm btn-warning ms-1">迁出</button></form>'
            . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="7" class="text-center text-muted py-4">暂无租户数据</td></tr>';
    }
    $navbar = function_exists('app_unified_navbar') ? app_unified_navbar(['active' => 'tenants']) : '';
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>租户管理</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css"></head><body>'
        . $navbar
        . '<div class="container mt-4">'
        . '<div class="d-flex justify-content-between align-items-center mb-3"><h3>租户管理</h3><a href="/admin/tenants/create" class="btn btn-success">新建租户</a></div>'
        . '<form class="mb-3" method="GET"><input type="text" name="keyword" value="' . htmlspecialchars($keyword) . '" placeholder="姓名/身份证号" class="form-control" style="max-width:300px;display:inline-block"> <button class="btn btn-outline-primary ms-2">搜索</button></form>'
        . '<div class="card"><div class="card-body table-responsive"><table class="table table-bordered"><thead><tr><th>ID</th><th>姓名</th><th>民族</th><th>性别</th><th>身份证号</th><th>电话</th><th>户籍地址</th><th>操作</th></tr></thead><tbody>'
        . $rows . '</tbody></table></div></div></div></body></html>';
}
