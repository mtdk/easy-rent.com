#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 清理已读通知脚本
 *
 * 用法:
 *  php scripts/cleanup-read-notifications.php --days=30
 *  php scripts/cleanup-read-notifications.php --days=30 --dry-run
 */

require __DIR__ . '/bootstrap-cli.php';

$days = 30;
$dryRun = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $days = max(1, (int) substr($arg, 7));
    }

    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$threshold = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
$countSql = 'SELECT COUNT(1) FROM notifications WHERE is_read = 1 AND read_at IS NOT NULL AND read_at < ?';
$targetCount = (int) db()->fetchColumn($countSql, [$threshold]);

if ($dryRun) {
    echo '[dry-run] 将清理已读通知数量: ' . $targetCount . PHP_EOL;
    echo '[dry-run] 条件: read_at < ' . $threshold . PHP_EOL;
    exit(0);
}

$deleteSql = 'DELETE FROM notifications WHERE is_read = 1 AND read_at IS NOT NULL AND read_at < ?';
$deleted = db()->execute($deleteSql, [$threshold]);

echo '已清理通知数量: ' . $deleted . PHP_EOL;
echo '阈值时间: ' . $threshold . PHP_EOL;
