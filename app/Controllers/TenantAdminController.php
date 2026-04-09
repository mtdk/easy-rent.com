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
        // 租户迁出
        Tenant::update($id, ['status' => '迁出']);
        // 共同居住人全部迁出
        db()->execute('UPDATE tenant_cohabitants SET status = ? WHERE tenant_id = ?', ['迁出', $id]);
        // 迁出时补全历史
        \App\Models\TenantStay::endStay($id, date('Y-m-d'));
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
        $tenants = Tenant::all($keyword);
        return Response::html(tenantListTemplate($tenants, $keyword));
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
        \App\Models\TenantStay::create($id, date('Y-m-d'));
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
        return [
            'name' => trim($input['name'] ?? ''),
            'nation' => trim($input['nation'] ?? ''),
            'gender' => trim($input['gender'] ?? '未知'),
            'id_number' => trim($input['id_number'] ?? ''),
            'phone' => trim($input['phone'] ?? ''),
            'address' => trim($input['address'] ?? ''),
        ];
    }

    // 解析共同居住人输入，支持民族
    private function parseCohabitantInput($input): array
    {
        return [
            'id' => $input['id'] ?? null,
            'name' => trim($input['name'] ?? ''),
            'nation' => trim($input['nation'] ?? ''),
            'gender' => trim($input['gender'] ?? '未知'),
            'id_number' => trim($input['id_number'] ?? ''),
            'phone' => trim($input['phone'] ?? ''),
            'address' => trim($input['address'] ?? ''),
        ];
    }
}
