<?php
namespace App\Controllers;

use App\Core\Response;
use App\Core\HttpException;
use App\Models\Tenant;
use App\Models\TenantCohabitant;

require_once APP_PATH . '/Views/tenantListTemplate.php';
require_once APP_PATH . '/Views/tenantFormTemplate.php';
require_once APP_PATH . '/Views/tenantShowTemplate.php';

class TenantAdminController
{
    // 迁出租户及其共同居住人
    public function moveOut(int $id): Response
    {
        $moveOutDate = trim($_POST['move_out_date'] ?? '');
        if ($moveOutDate === '') {
            $moveOutDate = date('Y-m-d');
        }
        // 租户迁出
        Tenant::update($id, ['status' => '迁出']);
        // 共同居住人全部迁出
        db()->execute('UPDATE tenant_cohabitants SET status = ? WHERE tenant_id = ?', ['迁出', $id]);
        // 迁出时补全历史
        \App\Models\TenantStay::endStay($id, $moveOutDate);
        return Response::redirect('/admin/tenants');
    }

    // 恢复在住（重新迁入）
    public function restore(int $id): Response
    {
        Tenant::update($id, ['status' => '在住']);
        \App\Models\TenantStay::create($id, date('Y-m-d'));
        return Response::redirect('/admin/tenants');
    }

    // 共同居住人单独迁出
    public function moveOutCohabitant(int $tenantId, int $id): Response
    {
        TenantCohabitant::update($id, ['status' => '迁出']);
        return Response::redirect("/admin/tenants/$tenantId/edit");
    }
    public function index(): Response
    {
        $keyword = trim($_GET['keyword'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        
        // 构建查询条件
        $whereConditions = [];
        $params = [];
        
        if ($keyword !== '') {
            $whereConditions[] = '(name LIKE ? OR id_number LIKE ?)';
            $params[] = "%$keyword%";
            $params[] = "%$keyword%";
        }
        
        if ($status !== '') {
            $whereConditions[] = 'status = ?';
            $params[] = $status;
        }
        
        $whereClause = $whereConditions ? ' WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // 获取总记录数
        $countSql = 'SELECT COUNT(*) AS total FROM tenants' . $whereClause;
        $totalResult = db()->fetch($countSql, $params);
        $total = (int) ($totalResult['total'] ?? 0);
        
        // 获取分页数据
        $sql = 'SELECT * FROM tenants' . $whereClause . ' ORDER BY id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $tenants = db()->fetchAll($sql, $params);
        
        // 计算总页数
        $totalPages = max(1, ceil($total / $limit));
        
        return Response::html(tenantListTemplate($tenants, $keyword, $status, $page, $totalPages, $total));
    }

    // 新建租户表单
    public function create(): Response
    {
        return Response::html(tenantFormTemplate('新建租户', '/admin/tenants', 'POST'));
    }

    // 查看租户详情
    public function show(int $id): Response
    {
        $tenant = Tenant::findById($id);
        if (!$tenant) throw HttpException::notFound('租户不存在');
        $cohabitants = TenantCohabitant::findByTenantId($id);
        $history = \App\Models\TenantStay::allByTenant($id);
        return Response::html(tenantShowTemplate($tenant, $cohabitants, $history));
    }

    // 编辑租户表单
    public function edit(int $id): Response
    {
        $tenant = Tenant::findById($id);
        if (!$tenant) throw HttpException::notFound('租户不存在');
        $cohabitants = TenantCohabitant::findByTenantId($id);
        $history = \App\Models\TenantStay::allByTenant($id);
        return Response::html(tenantFormTemplate('编辑租户', "/admin/tenants/$id", 'POST', $tenant, $cohabitants, $history));
    }

    // 保存新建租户
    public function store(): Response
    {
        $data = $this->parseTenantInput($_POST);
        $id = Tenant::create($data);
        // 新建租户即为迁入，写入历史
        $moveInDate = trim($_POST['move_in_date'] ?? '');
        if ($moveInDate === '') {
            $moveInDate = date('Y-m-d');
        }
        \App\Models\TenantStay::create($id, $moveInDate);
        return Response::redirect('/admin/tenants');
    }

    // 保存编辑租户
    public function update(int $id): Response
    {
        $data = $this->parseTenantInput($_POST);
        Tenant::update($id, $data);
        return Response::redirect('/admin/tenants');
    }

    // 删除租户（禁止删除）
    public function delete(int $id): Response
    {
        throw HttpException::badRequest('租户信息不允许删除');
    }

    // 新建/编辑共同居住人
    public function saveCohabitant(int $tenantId): Response
    {
        $data = $this->parseCohabitantInput($_POST);
        $data['tenant_id'] = $tenantId;
        if (!empty($data['id'])) {
            TenantCohabitant::update($data['id'], $data);
        } else {
            unset($data['id']); // 避免空id传入insert
            TenantCohabitant::create($data);
        }
        return Response::redirect("/admin/tenants/$tenantId/edit");
    }

    // 删除共同居住人（禁止删除）
    public function deleteCohabitant(int $tenantId, int $id): Response
    {
        throw HttpException::badRequest('共同居住人信息不允许删除');
    }

    // ...模板和输入解析方法略...

    // 解析租户输入，支持民族
    private function parseTenantInput($input): array
    {
        $idNumber = trim($input['id_number'] ?? '');
        return [
            'name' => trim($input['name'] ?? ''),
            'nation' => trim($input['nation'] ?? ''),
            'gender' => trim($input['gender'] ?? '男'),
            'id_number' => $idNumber === '' ? null : $idNumber,
            'phone' => trim($input['phone'] ?? ''),
            'address' => trim($input['address'] ?? ''),
        ];
    }

    // 解析共同居住人输入，支持民族
    private function parseCohabitantInput($input): array
    {
        $idNumber = trim($input['id_number'] ?? '');
        return [
            'id' => $input['id'] ?? null,
            'name' => trim($input['name'] ?? ''),
            'nation' => trim($input['nation'] ?? ''),
            'gender' => trim($input['gender'] ?? '未知'),
            'id_number' => $idNumber === '' ? null : $idNumber,
            'phone' => trim($input['phone'] ?? ''),
            'address' => trim($input['address'] ?? ''),
        ];
    }

    // 编辑共同居住人
    public function editCohabitant(int $tenantId, int $id): Response
    {
        $tenant = \App\Models\Tenant::findById($tenantId);
        if (!$tenant) throw \App\Core\HttpException::notFound('租户不存在');
        $cohabitant = \App\Models\TenantCohabitant::findById($id);
        if (!$cohabitant || $cohabitant['tenant_id'] != $tenantId) throw \App\Core\HttpException::notFound('共同居住人不存在');
        
        $csrf = csrf_token();
        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>编辑共同居住人</title>
<link rel="stylesheet" href="/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/bootstrap-icons.css">
<style>body { background-color: #f8f9fa; } .card { border-radius: 0.75rem; }</style></head><body>
<div class="container mt-4">
    <h3 class="mb-4"><i class="bi bi-person-lines-fill me-2"></i>编辑共同居住人 - ' . htmlspecialchars($cohabitant['name']) . '</h3>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="/admin/tenants/' . $tenantId . '/cohabitants/' . $id . '/update">
                <input type="hidden" name="_token" value="' . $csrf . '">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">姓名</label>
                        <input type="text" class="form-control" name="name" value="' . htmlspecialchars($cohabitant['name'] ?? '') . '" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">民族</label>
                        <input type="text" class="form-control" name="nation" value="' . htmlspecialchars($cohabitant['nation'] ?? '') . '">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">性别</label>
                        <select class="form-select" name="gender">
                            <option value="男"' . ($cohabitant['gender'] === '男' ? ' selected' : '') . '>男</option>
                            <option value="女"' . ($cohabitant['gender'] === '女' ? ' selected' : '') . '>女</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">身份证号</label>
                        <input type="text" class="form-control" name="id_number" value="' . htmlspecialchars($cohabitant['id_number'] ?? '') . '">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">电话</label>
                        <input type="text" class="form-control" name="phone" value="' . htmlspecialchars($cohabitant['phone'] ?? '') . '">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">地址</label>
                        <input type="text" class="form-control" name="address" value="' . htmlspecialchars($cohabitant['address'] ?? '') . '">
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">保存</button>
                    <a href="/admin/tenants/' . $tenantId . '/edit#cohabitants" class="btn btn-outline-secondary">取消</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body></html>';
        return \App\Core\Response::html($html);
    }

    // 更新共同居住人
    public function updateCohabitant(int $tenantId, int $id): Response
    {
        $tenant = \App\Models\Tenant::findById($tenantId);
        if (!$tenant) throw \App\Core\HttpException::notFound('租户不存在');
        $cohabitant = \App\Models\TenantCohabitant::findById($id);
        if (!$cohabitant || $cohabitant['tenant_id'] != $tenantId) throw \App\Core\HttpException::notFound('共同居住人不存在');

        $data = $this->parseCohabitantInput($_POST);
        // 移除id字段，因为更新时不需要
        unset($data['id']);
        \App\Models\TenantCohabitant::update($id, $data);
        return \App\Core\Response::redirect("/admin/tenants/{$tenantId}/edit#cohabitants");
    }
}
