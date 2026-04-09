<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-cli.php';

// 用法:
// php scripts/list-api-tokens.php [user_id] [all]
// - user_id: 可选，仅查看指定用户
// - all: 可选，传入 all 查看含失效 token（默认仅 active）

$userId = (int) ($argv[1] ?? 0);
$includeInactive = strtolower((string) ($argv[2] ?? '')) === 'all';

$where = [];
$params = [];

if ($userId > 0) {
    $where[] = 't.user_id = ?';
    $params[] = $userId;
}

if (!$includeInactive) {
    $where[] = 't.is_active = 1';
}

$whereSql = '';
if ($where !== []) {
    $whereSql = ' WHERE ' . implode(' AND ', $where);
}

$rows = db()->fetchAll(
    'SELECT
        t.id,
        t.user_id,
        u.username,
        u.role,
        t.token_name,
        t.token_prefix,
        t.is_active,
        t.expires_at,
        t.last_used_at,
        t.created_at
     FROM api_tokens t
     INNER JOIN users u ON u.id = t.user_id'
    . $whereSql
    . ' ORDER BY t.id DESC',
    $params
);

echo "API Token 列表\n";
echo "- 数量: " . count($rows) . "\n";
echo "- 过滤 user_id: " . ($userId > 0 ? (string) $userId : 'all') . "\n";
echo "- 包含失效: " . ($includeInactive ? 'yes' : 'no') . "\n\n";

if ($rows === []) {
    echo "无匹配 token\n";
    exit(0);
}

printf("%-6s %-7s %-14s %-10s %-18s %-8s %-20s %-20s\n", 'id', 'user_id', 'username', 'role', 'token_name', 'active', 'expires_at', 'last_used_at');
printf("%-6s %-7s %-14s %-10s %-18s %-8s %-20s %-20s\n", str_repeat('-', 6), str_repeat('-', 7), str_repeat('-', 14), str_repeat('-', 10), str_repeat('-', 18), str_repeat('-', 8), str_repeat('-', 20), str_repeat('-', 20));

foreach ($rows as $row) {
    printf(
        "%-6s %-7s %-14s %-10s %-18s %-8s %-20s %-20s\n",
        (string) ($row['id'] ?? ''),
        (string) ($row['user_id'] ?? ''),
        (string) ($row['username'] ?? ''),
        (string) ($row['role'] ?? ''),
        (string) ($row['token_name'] ?? ''),
        ((int) ($row['is_active'] ?? 0) === 1) ? 'yes' : 'no',
        (string) (($row['expires_at'] ?? null) ?: 'never'),
        (string) (($row['last_used_at'] ?? null) ?: '-')
    );
}
