<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-cli.php';

// 用法:
// php scripts/rotate-api-token.php <token_id> [expires_days]

$tokenId = (int) ($argv[1] ?? 0);
$expiresDays = (int) ($argv[2] ?? 30);

if ($tokenId <= 0) {
    fwrite(STDERR, "错误: token_id 必须为正整数\n");
    fwrite(STDERR, "用法: php scripts/rotate-api-token.php <token_id> [expires_days]\n");
    exit(1);
}

if ($expiresDays < 0) {
    fwrite(STDERR, "错误: expires_days 不能小于 0\n");
    exit(1);
}

$token = db()->fetch('SELECT id, user_id, token_name, is_active FROM api_tokens WHERE id = ? LIMIT 1', [$tokenId]);
if (!$token) {
    fwrite(STDERR, "错误: token 不存在\n");
    exit(1);
}

$user = db()->fetch('SELECT id, username, role, status FROM users WHERE id = ? LIMIT 1', [(int) $token['user_id']]);
if (!$user) {
    fwrite(STDERR, "错误: token 对应用户不存在\n");
    exit(1);
}

if ((string) ($user['status'] ?? '') !== 'active') {
    fwrite(STDERR, "错误: 用户状态不是 active，拒绝轮换\n");
    exit(1);
}

// 先失效旧 token
db()->update('api_tokens', [
    'is_active' => 0,
    'updated_at' => date('Y-m-d H:i:s'),
], ['id' => $tokenId]);

$rawToken = 'ert_' . bin2hex(random_bytes(24));
$tokenHash = hash('sha256', $rawToken);
$prefix = substr($rawToken, 0, 16);
$now = date('Y-m-d H:i:s');

$expiresAt = null;
if ($expiresDays > 0) {
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $expiresDays . ' days'));
}

$newTokenName = (string) ($token['token_name'] ?? 'token') . '_rotated';

$newTokenId = db()->insert('api_tokens', [
    'user_id' => (int) $user['id'],
    'token_name' => $newTokenName,
    'token_prefix' => $prefix,
    'token_hash' => $tokenHash,
    'is_active' => 1,
    'expires_at' => $expiresAt,
    'last_used_at' => null,
    'created_at' => $now,
    'updated_at' => $now,
]);

echo "API Token 已轮换\n";
echo "- old_token_id: {$tokenId}\n";
echo "- new_token_id: {$newTokenId}\n";
echo "- user_id: " . (int) ($user['id'] ?? 0) . "\n";
echo "- username: " . (string) ($user['username'] ?? '') . "\n";
echo "- role: " . (string) ($user['role'] ?? '') . "\n";
echo "- token_name: {$newTokenName}\n";
echo "- expires_at: " . ($expiresAt ?? 'never') . "\n";
echo "- token (仅展示一次): {$rawToken}\n";
