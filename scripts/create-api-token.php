<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-cli.php';

// 用法:
// php scripts/create-api-token.php <user_id> [token_name] [expires_days]

$userId = (int) ($argv[1] ?? 0);
$tokenName = trim((string) ($argv[2] ?? 'default'));
$expiresDays = (int) ($argv[3] ?? 30);

if ($userId <= 0) {
    fwrite(STDERR, "错误: user_id 必须为正整数\n");
    fwrite(STDERR, "用法: php scripts/create-api-token.php <user_id> [token_name] [expires_days]\n");
    exit(1);
}

if ($tokenName === '') {
    $tokenName = 'default';
}

if ($expiresDays < 0) {
    fwrite(STDERR, "错误: expires_days 不能小于 0\n");
    exit(1);
}

$user = db()->fetch('SELECT id, username, role, status FROM users WHERE id = ? LIMIT 1', [$userId]);
if (!$user) {
    fwrite(STDERR, "错误: 用户不存在\n");
    exit(1);
}

if ((string) ($user['status'] ?? '') !== 'active') {
    fwrite(STDERR, "错误: 用户状态不是 active，拒绝创建令牌\n");
    exit(1);
}

$rawToken = 'ert_' . bin2hex(random_bytes(24));
$tokenHash = hash('sha256', $rawToken);
$prefix = substr($rawToken, 0, 16);
$now = date('Y-m-d H:i:s');

$expiresAt = null;
if ($expiresDays > 0) {
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $expiresDays . ' days'));
}

$tokenId = db()->insert('api_tokens', [
    'user_id' => $userId,
    'token_name' => $tokenName,
    'token_prefix' => $prefix,
    'token_hash' => $tokenHash,
    'is_active' => 1,
    'expires_at' => $expiresAt,
    'last_used_at' => null,
    'created_at' => $now,
    'updated_at' => $now,
]);

echo "API Token 创建成功\n";
echo "- token_id: {$tokenId}\n";
echo "- user_id: {$userId}\n";
echo "- username: " . (string) ($user['username'] ?? '') . "\n";
echo "- role: " . (string) ($user['role'] ?? '') . "\n";
echo "- token_name: {$tokenName}\n";
echo "- expires_at: " . ($expiresAt ?? 'never') . "\n";
echo "- token (仅展示一次): {$rawToken}\n";
