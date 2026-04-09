<?php

declare(strict_types=1);

/**
 * 数据库迁移脚本
 *
 * 约定:
 * - up 文件:   database/migrations/<name>.up.sql
 * - down 文件: database/migrations/<name>.down.sql
 * - <name> 推荐使用: YYYYMMDD_HHMMSS_description
 */

require_once __DIR__ . '/bootstrap-cli.php';

const MIGRATION_TABLE = 'schema_migrations';

$command = $argv[1] ?? 'status';
$options = parseOptions(array_slice($argv, 2));

$migrationsDir = APP_ROOT . '/database/migrations';
$step = max(1, (int) ($options['step'] ?? 1));

try {
    ensureMigrationTable();

    if ($command === 'status') {
        commandStatus($migrationsDir);
        exit(0);
    }

    if ($command === 'up') {
        commandUp($migrationsDir, $step);
        exit(0);
    }

    if ($command === 'down') {
        commandDown($migrationsDir, $step);
        exit(0);
    }

    printUsage();
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, '迁移失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function parseOptions(array $args): array
{
    $result = [];
    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0] ?? '';
        $value = $parts[1] ?? '1';
        if ($key !== '') {
            $result[$key] = $value;
        }
    }

    return $result;
}

function printUsage(): void
{
    echo "用法:\n";
    echo "  php scripts/migrate-easy-rent.php status\n";
    echo "  php scripts/migrate-easy-rent.php up [--step=1]\n";
    echo "  php scripts/migrate-easy-rent.php down [--step=1]\n";
}

function ensureMigrationTable(): void
{
    db()->execute(
        'CREATE TABLE IF NOT EXISTS ' . MIGRATION_TABLE . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            batch INT NOT NULL,
            execution_ms INT NOT NULL DEFAULT 0,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function commandStatus(string $migrationsDir): void
{
    $all = discoverMigrations($migrationsDir);
    $applied = appliedMigrations();

    $appliedCount = count($applied);
    $totalCount = count($all);
    $pendingCount = max(0, $totalCount - $appliedCount);

    echo "数据库迁移状态\n";
    echo "- 总迁移数: {$totalCount}\n";
    echo "- 已执行: {$appliedCount}\n";
    echo "- 待执行: {$pendingCount}\n\n";

    if ($totalCount === 0) {
        echo "未发现迁移文件: {$migrationsDir}\n";
        return;
    }

    printf("%-45s %-10s %-8s %-20s\n", '迁移名', '状态', '批次', '执行时间');
    printf("%-45s %-10s %-8s %-20s\n", str_repeat('-', 45), str_repeat('-', 10), str_repeat('-', 8), str_repeat('-', 20));

    foreach ($all as $name => $_paths) {
        if (isset($applied[$name])) {
            $meta = $applied[$name];
            printf(
                "%-45s %-10s %-8s %-20s\n",
                $name,
                'applied',
                (string) ($meta['batch'] ?? '-'),
                (string) ($meta['applied_at'] ?? '-')
            );
        } else {
            printf("%-45s %-10s %-8s %-20s\n", $name, 'pending', '-', '-');
        }
    }
}

function commandUp(string $migrationsDir, int $step): void
{
    $all = discoverMigrations($migrationsDir);
    $applied = appliedMigrations();

    $pending = [];
    foreach ($all as $name => $paths) {
        if (!isset($applied[$name])) {
            $pending[$name] = $paths;
        }
    }

    if (count($pending) === 0) {
        echo "没有待执行迁移。\n";
        return;
    }

    $batch = currentBatch() + 1;
    $selected = array_slice($pending, 0, $step, true);

    echo "开始执行迁移 up，批次: {$batch}，数量: " . count($selected) . "\n";

    foreach ($selected as $name => $paths) {
        $upFile = $paths['up'] ?? null;
        if (!$upFile || !is_file($upFile)) {
            throw new RuntimeException("缺少 up 迁移文件: {$name}");
        }

        $start = microtime(true);
        try {
            executeSqlFile($upFile);
            $ms = (int) round((microtime(true) - $start) * 1000);

            db()->insert(MIGRATION_TABLE, [
                'migration_name' => $name,
                'batch' => $batch,
                'execution_ms' => $ms,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);

            echo "[OK] {$name} ({$ms}ms)\n";
        } catch (Throwable $e) {
            throw new RuntimeException("执行迁移失败 {$name}: " . $e->getMessage(), 0, $e);
        }
    }

    echo "up 完成。\n";
}

function commandDown(string $migrationsDir, int $step): void
{
    $rows = db()->fetchAll(
        'SELECT id, migration_name, batch FROM ' . MIGRATION_TABLE . ' ORDER BY batch DESC, id DESC LIMIT ' . (int) $step
    );

    if (count($rows) === 0) {
        echo "没有可回滚迁移。\n";
        return;
    }

    $all = discoverMigrations($migrationsDir);

    echo "开始执行迁移 down，数量: " . count($rows) . "\n";

    foreach ($rows as $row) {
        $name = (string) $row['migration_name'];
        $downFile = $all[$name]['down'] ?? null;
        if (!$downFile || !is_file($downFile)) {
            throw new RuntimeException("缺少 down 迁移文件: {$name}");
        }

        $start = microtime(true);
        try {
            executeSqlFile($downFile);
            db()->delete(MIGRATION_TABLE, ['id' => (int) $row['id']]);

            $ms = (int) round((microtime(true) - $start) * 1000);
            echo "[OK] rollback {$name} ({$ms}ms)\n";
        } catch (Throwable $e) {
            throw new RuntimeException("回滚迁移失败 {$name}: " . $e->getMessage(), 0, $e);
        }
    }

    echo "down 完成。\n";
}

function discoverMigrations(string $migrationsDir): array
{
    if (!is_dir($migrationsDir)) {
        return [];
    }

    $upFiles = glob($migrationsDir . '/*.up.sql') ?: [];
    sort($upFiles, SORT_NATURAL);

    $result = [];
    foreach ($upFiles as $upFile) {
        $base = basename($upFile, '.up.sql');
        $result[$base] = [
            'up' => $upFile,
            'down' => $migrationsDir . '/' . $base . '.down.sql',
        ];
    }

    return $result;
}

function appliedMigrations(): array
{
    $rows = db()->fetchAll('SELECT migration_name, batch, applied_at FROM ' . MIGRATION_TABLE . ' ORDER BY id ASC');
    $result = [];
    foreach ($rows as $row) {
        $result[(string) $row['migration_name']] = $row;
    }
    return $result;
}

function currentBatch(): int
{
    $batch = db()->fetchColumn('SELECT COALESCE(MAX(batch), 0) FROM ' . MIGRATION_TABLE);
    return (int) $batch;
}

function executeSqlFile(string $path): void
{
    $sql = @file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('无法读取迁移文件: ' . $path);
    }

    $sql = trim($sql);
    if ($sql === '') {
        return;
    }

    $pdo = db()->getPdo();
    $pdo->exec($sql);
}
