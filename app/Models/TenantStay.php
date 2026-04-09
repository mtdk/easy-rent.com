<?php
// app/Models/TenantStay.php
namespace App\Models;

class TenantStay
{
    public static function create($tenantId, $startDate)
    {
        return db()->insert('tenant_stays', [
            'tenant_id' => $tenantId,
            'start_date' => $startDate,
        ]);
    }

    public static function endStay($tenantId, $endDate)
    {
        // 只更新未结束的最新一条
        return db()->execute('UPDATE tenant_stays SET end_date = ? WHERE tenant_id = ? AND end_date IS NULL ORDER BY id DESC LIMIT 1', [$endDate, $tenantId]);
    }

    public static function allByTenant($tenantId)
    {
        return db()->fetchAll('SELECT * FROM tenant_stays WHERE tenant_id = ? ORDER BY start_date DESC', [$tenantId]);
    }
}
