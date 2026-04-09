<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-cli.php';

// 用法:
// php scripts/manage-api-access-logs.php [mode] [retention_days] [archive_dir]
// - mode: preview | commit (默认 preview)
// - retention_days: 保留天数，早于该天数的数据将归档并清理（默认 90）
// - archive_dir: 归档目录（默认 storage/logs/api-access-archives）

$mode = strtolower(trim((string) ($argv[1] ?? 'preview')));
$retentionDays = (int) ($argv[2] ?? 90);
$archiveDir = trim((string) ($argv[3] ?? 'storage/logs/api-access-archives'));

if (!in_array($mode, ['preview', 'commit'], true)) {
    fwrite(STDERR, "错误: mode 必须是 preview 或 commit\n");
    fwrite(STDERR, "用法: php scripts/manage-api-access-logs.php [mode] [retention_days] [archive_dir]\n");
    exit(1);
}

if ($retentionDays < 1) {
    fwrite(STDERR, "错误: retention_days 必须 >= 1\n");
    exit(1);
}

if ($archiveDir === '') {
    fwrite(STDERR, "错误: archive_dir 不能为空\n");
    exit(1);
}

$cutoff = date('Y-m-d H:i:s', strtotime('-' . $retentionDays . ' days'));

$countRow = db()->fetch(
    'SELECT
        COUNT(1) AS total,
        MIN(created_at) AS oldest_created_at,
        MAX(created_at) AS newest_created_at,
        MIN(id) AS min_id,
        MAX(id) AS max_id
     FROM api_access_logs
     WHERE created_at < ?',
    [$cutoff]
);

$total = (int) ($countRow['total'] ?? 0);
$oldest = (string) (($countRow['oldest_created_at'] ?? null) ?: '-');
$newest = (string) (($countRow['newest_created_at'] ?? null) ?: '-');
$minId = (string) (($countRow['min_id'] ?? null) ?: '-');
$maxId = (string) (($countRow['max_id'] ?? null) ?: '-');

$summary = static function () use ($mode, $retentionDays, $cutoff, $total, $oldest, $newest, $minId, $maxId): void {
    echo "========================================\n";
    echo "API 访问日志保留策略\n";
    echo "模式: {$mode}\n";
    echo "时间: " . date('Y-m-d H:i:s') . "\n";
    echo "保留天数: {$retentionDays}\n";
    echo "截止时间: {$cutoff}\n";
    echo "待处理数量: {$total}\n";
    echo "ID 范围: {$minId} ~ {$maxId}\n";
    echo "时间范围: {$oldest} ~ {$newest}\n";
    echo "========================================\n";
};

$summary();

if ($total === 0) {
    echo "无可归档/清理记录，任务结束。\n";
    exit(0);
}

if ($mode === 'preview') {
    echo "预览模式不会写入文件或删除数据。\n";
    exit(0);
}

$archivePath = rtrim($archiveDir, '/') . '/api-access-logs_' . date('Ymd_His') . '.csv';
$archiveAbsDir = $archiveDir;
if (!str_starts_with($archiveAbsDir, '/')) {
    $archiveAbsDir = APP_ROOT . '/' . ltrim($archiveAbsDir, '/');
}
if (!is_dir($archiveAbsDir) && !mkdir($archiveAbsDir, 0775, true) && !is_dir($archiveAbsDir)) {
    fwrite(STDERR, "错误: 无法创建归档目录 {$archiveAbsDir}\n");
    exit(1);
}

$archiveAbsPath = $archivePath;
if (!str_starts_with($archiveAbsPath, '/')) {
    $archiveAbsPath = APP_ROOT . '/' . ltrim($archiveAbsPath, '/');
}

$rows = db()->fetchAll(
    'SELECT
        id,
        user_id,
        token_id,
        request_path,
        request_method,
        status_code,
        auth_type,
        ip_address,
        user_agent,
        message,
        created_at
     FROM api_access_logs
     WHERE created_at < ?
     ORDER BY id ASC',
    [$cutoff]
);

$fp = fopen($archiveAbsPath, 'w');
if ($fp === false) {
    fwrite(STDERR, "错误: 无法写入归档文件 {$archiveAbsPath}\n");
    exit(1);
}

fputcsv($fp, ['id', 'user_id', 'token_id', 'request_path', 'request_method', 'status_code', 'auth_type', 'ip_address', 'user_agent', 'message', 'created_at'], ',', '"', '');

foreach ($rows as $row) {
    fputcsv($fp, [
        (int) ($row['id'] ?? 0),
        ($row['user_id'] ?? null) === null ? '' : (int) $row['user_id'],
        ($row['token_id'] ?? null) === null ? '' : (int) $row['token_id'],
        (string) ($row['request_path'] ?? ''),
        (string) ($row['request_method'] ?? ''),
        (int) ($row['status_code'] ?? 0),
        (string) ($row['auth_type'] ?? ''),
        (string) ($row['ip_address'] ?? ''),
        (string) (($row['user_agent'] ?? '') ?: ''),
        (string) (($row['message'] ?? '') ?: ''),
        (string) ($row['created_at'] ?? ''),
    ], ',', '"', '');
}

fclose($fp);

$pdo = db()->getPdo();
$pdo->beginTransaction();

try {
    $deleted = db()->execute('DELETE FROM api_access_logs WHERE created_at < ?', [$cutoff]);
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "错误: 清理失败，事务已回滚: " . $e->getMessage() . "\n");
    exit(1);
}

echo "归档与清理完成\n";
echo "- 归档文件: {$archivePath}\n";
echo "- 归档绝对路径: {$archiveAbsPath}\n";
echo "- 归档记录数: " . count($rows) . "\n";
echo "- 删除记录数: {$deleted}\n";
