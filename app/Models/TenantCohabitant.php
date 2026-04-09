<?php
// app/Models/TenantCohabitant.php
namespace App\Models;

class TenantCohabitant
{
    public static function findByTenantId($tenantId)
    {
        return db()->fetchAll('SELECT * FROM tenant_cohabitants WHERE tenant_id = ? ORDER BY id ASC', [$tenantId]);
    }

    public static function create($data)
    {
        return db()->insert('tenant_cohabitants', $data);
    }

    public static function update($id, $data)
    {
        return db()->update('tenant_cohabitants', $data, ['id' => $id]);
    }

    public static function delete($id)
    {
        return db()->delete('tenant_cohabitants', ['id' => $id]);
    }
}
