<?php
// 租户表单模板（含共同居住人管理，简化版）
function tenantFormTemplate($title, $action, $method, $tenant = [], $cohabitants = []) {
    $isEdit = !empty($tenant['id']);
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $fields = [
        ['name' => 'name', 'label' => '姓名', 'type' => 'text'],
        ['name' => 'nation', 'label' => '民族', 'type' => 'text'],
        ['name' => 'gender', 'label' => '性别', 'type' => 'select', 'options' => ['男', '女']],
        ['name' => 'id_number', 'label' => '身份证号', 'type' => 'text'],
        ['name' => 'phone', 'label' => '电话', 'type' => 'text'],
        ['name' => 'address', 'label' => '户籍地址', 'type' => 'text'],
    ];
    $form = '<form method="POST" action="' . htmlspecialchars($action) . '" class="card shadow-sm mb-4"><div class="card-body">';
    $form .= '<input type="hidden" name="_token" value="' . $csrf . '">';
    $form .= '<div class="row">';
    foreach ($fields as $i => $f) {
        $value = $tenant[$f['name']] ?? '';
        $form .= '<div class="col-md-4 mb-3">';
        $form .= '<label class="form-label">' . $f['label'] . '</label>';
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
    $form .= '<div class="d-flex gap-2">';
    $form .= '<button class="btn btn-primary">保存</button>';
    $form .= '<a href="/admin/tenants" class="btn btn-secondary">返回</a>';
    if ($isEdit) {
        $form .= '<button type="submit" formaction="' . htmlspecialchars($action) . '/delete" class="btn btn-danger ms-auto" onclick="return confirm(\'确认删除租户？\')">删除租户</button>';
    }
    $form .= '</div>';
    $form .= '</div></form>';

    // 共同居住人管理
    if ($isEdit) {
        $form .= '<div class="card mb-4 shadow-sm">';
        $form .= '<div class="card-header bg-light"><strong>共同居住人</strong></div>';
        $form .= '<div class="card-body pb-2">';
        // 添加共同居住人表单
        $form .= '<form method="POST" action="/admin/tenants/' . (int)$tenant['id'] . '/cohabitants/save" class="mb-3">';
        $form .= '<input type="hidden" name="_token" value="' . $csrf . '"><input type="hidden" name="id" value="">';
        $form .= '<div class="row">';
        $coFields = [
            ['name' => 'name', 'label' => '姓名', 'type' => 'text'],
            ['name' => 'nation', 'label' => '民族', 'type' => 'text'],
            ['name' => 'gender', 'label' => '性别', 'type' => 'select', 'options' => ['男', '女']],
            ['name' => 'id_number', 'label' => '身份证号', 'type' => 'text'],
            ['name' => 'phone', 'label' => '电话', 'type' => 'text'],
            ['name' => 'address', 'label' => '户籍地址', 'type' => 'text'],
        ];
        foreach ($coFields as $i => $f) {
            $form .= '<div class="col-md-4 mb-2">';
            $form .= '<label class="form-label">' . $f['label'] . '</label>';
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
        $form .= '</div>';
        $form .= '<div class="row"><div class="col-12"><button class="btn btn-success w-100">添加</button></div></div>';
        $form .= '</form>';

        // 共同居住人列表
        $form .= '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0">';
        $form .= '<thead class="table-light"><tr>';
        foreach ($coFields as $f) {
            $form .= '<th>' . $f['label'] . '</th>';
        }
        $form .= '<th>操作</th></tr></thead><tbody>';
        if (!empty($cohabitants)) {
            foreach ($cohabitants as $c) {
                $form .= '<tr>';
                foreach ($coFields as $f) {
                    $form .= '<td>' . htmlspecialchars($c[$f['name']] ?? '') . '</td>';
                }
                $form .= '<td class="text-nowrap">'
                    . '<form method="POST" action="/admin/tenants/' . (int)$tenant['id'] . '/cohabitants/' . (int)$c['id'] . '/delete" style="display:inline;margin:0">'
                    . '<input type="hidden" name="_token" value="' . $csrf . '">' 
                    . '<button class="btn btn-sm btn-danger">删除</button>'
                    . '</form>'
                    . '<form method="POST" action="/admin/tenants/' . (int)$tenant['id'] . '/cohabitants/' . (int)$c['id'] . '/moveout" style="display:inline;margin:0">'
                    . '<input type="hidden" name="_token" value="' . $csrf . '">' 
                    . '<button class="btn btn-sm btn-warning">迁出</button>'
                    . '</form>'
                    . '</td>';
                $form .= '</tr>';
            }
        } else {
            $form .= '<tr><td colspan="7" class="text-center text-muted">暂无共同居住人</td></tr>';
        }
        $form .= '</tbody></table></div>';
        $form .= '</div></div>';
    }
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"></head><body><div class="container mt-4"><h3 class="mb-4">' . htmlspecialchars($title) . '</h3>' . $form . '</div></body></html>';
}
