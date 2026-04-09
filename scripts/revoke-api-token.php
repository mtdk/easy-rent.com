<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-cli.php';

// 用法:
// php scripts/revoke-api-token.php <token_id>

$tokenId = (int) ($argv[1] ?? 0);
if ($tokenId <= 0) {
    fwrite(STDERR, "错误: token_id 必须为正整数\n");
    fwrite(STDERR, "用法: php scripts/revoke-api-token.php <token_id>\n");
    exit(1);
}

$token = db()->fetch(
    'SELECT t.id, t.user_id, t.token_name, t.is_active, u.username
     FROM api_tokens t
     INNER JOIN users u ON u.id = t.user_id
     WHERE t.id = ? LIMIT 1',
    [$tokenId]
);

if (!$token) {
    fwrite(STDERR, "错误: token 不存在\n");
    exit(1);
}

if ((int) ($token['is_active'] ?? 0) !== 1) {
    echo "token 已是失效状态，无需重复操作\n";
    echo "- token_id: {$tokenId}\n";
    exit(0);
}

db()->update('api_tokens', [
    'is_active' => 0,
    'updated_at' => date('Y-m-d H:i:s'),
], ['id' => $tokenId]);

echo "API Token 已禁用\n";
echo "- token_id: {$tokenId}\n";
echo "- user_id: " . (int) ($token['user_id'] ?? 0) . "\n";
echo "- username: " . (string) ($token['username'] ?? '') . "\n";
echo "- token_name: " . (string) ($token['token_name'] ?? '') . "\n";
