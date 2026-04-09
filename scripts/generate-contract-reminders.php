#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 自动生成合同到期提醒通知
 *
 * 用法:
 *  php scripts/generate-contract-reminders.php --days=30
 *  php scripts/generate-contract-reminders.php --days=30 --dry-run
 *  php scripts/generate-contract-reminders.php --owner-id=12 --dry-run
 */

require __DIR__ . '/bootstrap-cli.php';

$days = null;
$dryRun = false;
$ownerId = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $days = max(1, (int) substr($arg, 7));
    }

    if ($arg === '--dry-run') {
        $dryRun = true;
    }

    if (str_starts_with($arg, '--owner-id=')) {
        $ownerId = max(1, (int) substr($arg, 11));
    }
}

if ($days === null) {
    $setting = db()->fetch('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', ['notification.contract_expiry_days']);
    $days = max(1, (int) ($setting['setting_value'] ?? 30));
}

$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');

$sql = '
    SELECT
        c.id,
        c.contract_number,
        c.tenant_name,
        c.end_date,
        p.owner_id,
        p.property_name,
        DATEDIFF(c.end_date, CURDATE()) AS days_left
    FROM contracts c
    JOIN properties p ON p.id = c.property_id
    WHERE c.contract_status IN ("active", "pending")
      AND DATEDIFF(c.end_date, CURDATE()) BETWEEN 0 AND ?
';

$params = [$days];
if ($ownerId !== null) {
    $sql .= ' AND p.owner_id = ?';
    $params[] = $ownerId;
}

$sql .= ' ORDER BY c.end_date ASC';

$contracts = db()->fetchAll($sql, $params);

$created = 0;
$skipped = 0;
$errors = 0;

foreach ($contracts as $contract) {
    $exists = (int) db()->fetchColumn(
        'SELECT COUNT(1) FROM notifications WHERE related_type = ? AND related_id = ? AND type = ? AND created_at BETWEEN ? AND ?',
        ['contract', (int) $contract['id'], 'reminder', $todayStart, $todayEnd]
    );

    if ($exists > 0) {
        $skipped++;
        continue;
    }

    $daysLeft = max(0, (int) ($contract['days_left'] ?? 0));
    $priority = $daysLeft <= 7 ? 'high' : 'normal';

    $row = [
        'user_id' => (int) $contract['owner_id'],
        'type' => 'reminder',
        'title' => '合同续约提醒',
        'content' => '合同 ' . (string) $contract['contract_number'] . '（租客：' . (string) $contract['tenant_name'] . '，房产：' . (string) $contract['property_name'] . '）将在 ' . $daysLeft . ' 天后到期，请尽快跟进续约。',
        'related_type' => 'contract',
        'related_id' => (int) $contract['id'],
        'priority' => $priority,
        'is_read' => 0,
        'action_url' => '/contracts/' . (int) $contract['id'],
        'action_text' => '查看合同',
        'expires_at' => date('Y-m-d H:i:s', strtotime((string) $contract['end_date'] . ' +1 day')),
        'created_at' => date('Y-m-d H:i:s'),
    ];

    if ($dryRun) {
        $created++;
        continue;
    }

    try {
        db()->insert('notifications', $row);
        $created++;
    } catch (\Throwable $e) {
        $errors++;
    }
}

echo ($dryRun ? '[dry-run] ' : '') . '扫描合同: ' . count($contracts) . PHP_EOL;
echo ($dryRun ? '[dry-run] ' : '') . '新建提醒: ' . $created . PHP_EOL;
echo ($dryRun ? '[dry-run] ' : '') . '跳过重复: ' . $skipped . PHP_EOL;
if (!$dryRun) {
    echo '写入失败: ' . $errors . PHP_EOL;
}
echo '阈值天数: ' . $days . PHP_EOL;
if ($ownerId !== null) {
    echo '限定房东ID: ' . $ownerId . PHP_EOL;
}
