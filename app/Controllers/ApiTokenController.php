<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;

class ApiTokenController
{
    public function index(): Response
    {
        $this->ensureAdmin();

        $userId = (int) ($_GET['user_id'] ?? 0);
        $status = trim((string) ($_GET['status'] ?? 'active'));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = (int) ($_GET['per_page'] ?? 20);
        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 20;
        }
        if (!in_array($status, ['active', 'all'], true)) {
            $status = 'active';
        }

        $where = [];
        $params = [];

        if ($userId > 0) {
            $where[] = 't.user_id = ?';
            $params[] = $userId;
        }

        if ($status === 'active') {
            $where[] = 't.is_active = 1';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $tokens = db()->fetchAll(
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

        $totalMatching = count($tokens);
        $activeMatching = 0;
        foreach ($tokens as $token) {
            if ((int) ($token['is_active'] ?? 0) === 1) {
                $activeMatching++;
            }
        }
        $inactiveMatching = $totalMatching - $activeMatching;
        $totalPages = max(1, (int) ceil($totalMatching / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $tokens = array_slice($tokens, $offset, $perPage);

        $users = db()->fetchAll(
            'SELECT id, username, role, status
             FROM users
             WHERE status = ?
             ORDER BY id ASC',
            ['active']
        );

        return Response::html($this->indexTemplate($tokens, $users, $userId, $status, $page, $perPage, $totalMatching, $totalPages, $activeMatching, $inactiveMatching));
    }

    public function store(): Response
    {
        $this->ensureAdmin();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $tokenName = trim((string) ($_POST['token_name'] ?? 'default'));
        $expiresDays = (int) ($_POST['expires_days'] ?? 30);

        if ($userId <= 0) {
            throw HttpException::badRequest('user_id 必须为正整数');
        }
        if ($expiresDays < 0) {
            throw HttpException::badRequest('expires_days 不能小于 0');
        }
        if ($tokenName === '') {
            $tokenName = 'default';
        }

        $user = db()->fetch('SELECT id, username, role, status FROM users WHERE id = ? LIMIT 1', [$userId]);
        if (!$user) {
            throw HttpException::notFound('用户不存在');
        }
        if ((string) ($user['status'] ?? '') !== 'active') {
            throw HttpException::badRequest('用户状态不是 active，拒绝创建令牌');
        }

        $created = $this->createToken((int) $user['id'], $tokenName, $expiresDays);

        return Response::html($this->resultTemplate('API Token 创建成功', [
            'token_id' => (string) $created['token_id'],
            'user_id' => (string) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'token_name' => $tokenName,
            'expires_at' => $created['expires_at'] ?? 'never',
            'token (仅展示一次)' => (string) $created['raw_token'],
        ]));
    }

    public function revoke(int $id): Response
    {
        $this->ensureAdmin();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $token = $this->findToken($id);
        if (!$token) {
            throw HttpException::notFound('token 不存在');
        }

        if ((int) ($token['is_active'] ?? 0) !== 1) {
            return Response::redirect('/api-tokens?status=all');
        }

        db()->update('api_tokens', [
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        return Response::redirect('/api-tokens?status=all');
    }

    public function rotate(int $id): Response
    {
        $this->ensureAdmin();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $expiresDays = (int) ($_POST['expires_days'] ?? 30);
        if ($expiresDays < 0) {
            throw HttpException::badRequest('expires_days 不能小于 0');
        }

        $token = $this->findToken($id);
        if (!$token) {
            throw HttpException::notFound('token 不存在');
        }

        $user = db()->fetch('SELECT id, username, role, status FROM users WHERE id = ? LIMIT 1', [(int) $token['user_id']]);
        if (!$user) {
            throw HttpException::notFound('token 对应用户不存在');
        }
        if ((string) ($user['status'] ?? '') !== 'active') {
            throw HttpException::badRequest('用户状态不是 active，拒绝轮换');
        }

        db()->update('api_tokens', [
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        $newTokenName = (string) ($token['token_name'] ?? 'token') . '_rotated';
        $created = $this->createToken((int) $user['id'], $newTokenName, $expiresDays);

        return Response::html($this->resultTemplate('API Token 已轮换', [
            'old_token_id' => (string) $id,
            'new_token_id' => (string) $created['token_id'],
            'user_id' => (string) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'token_name' => $newTokenName,
            'expires_at' => $created['expires_at'] ?? 'never',
            'token (仅展示一次)' => (string) $created['raw_token'],
        ]));
    }

    private function createToken(int $userId, string $tokenName, int $expiresDays): array
    {
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

        return [
            'token_id' => $tokenId,
            'raw_token' => $rawToken,
            'expires_at' => $expiresAt,
        ];
    }

    private function findToken(int $tokenId): ?array
    {
        return db()->fetch(
            'SELECT t.id, t.user_id, t.token_name, t.is_active, u.username
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.id = ? LIMIT 1',
            [$tokenId]
        );
    }

    private function indexTemplate(array $tokens, array $users, int $selectedUserId, string $status, int $page, int $perPage, int $totalMatching, int $totalPages, int $activeMatching, int $inactiveMatching): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'api_tokens',
            'is_admin' => true,
            'show_user_menu' => true,
            'collapse_id' => 'apiTokenNavbar',
        ]);

        $pageStyles = '<style>'
            . '.api-token-page { --panel-shadow: 0 0.25rem 1rem rgba(15, 23, 42, 0.08); --muted-text: #64748b; --surface-soft: #f8fafc; }'
            . '.api-token-page .card { border: 0; box-shadow: 0 0.25rem 1rem rgba(15, 23, 42, 0.08); }'
            . '.api-token-page .page-head { background: linear-gradient(120deg, #0f172a, #1e3a8a); color: #f8fafc; border-radius: 1rem; padding: 1.15rem 1.25rem; margin-bottom: 1rem; }'
            . '.api-token-page .page-head .subtitle { color: rgba(248, 250, 252, 0.84); margin-top: 0.25rem; }'
            . '.api-token-page .summary-card { border-radius: 0.9rem; }'
            . '.api-token-page .summary-card .value { font-size: 1.25rem; font-weight: 700; line-height: 1.2; color: #0f172a; }'
            . '.api-token-page .summary-card .label { font-size: 0.78rem; color: #64748b; }'
            . '.api-token-page .card-title { font-weight: 600; }'
            . '.api-token-page .token-list-card .card-body { padding-top: 1rem; }'
            . '.api-token-page .token-create-card { position: sticky; top: 1rem; }'
            . '.api-token-page .token-filter-form .form-label { font-size: 0.82rem; color: #475569; margin-bottom: 0.35rem; }'
            . '.api-token-page .filter-summary-card { border: 1px solid #dbeafe; background: linear-gradient(120deg, #f8fbff, #f0f9ff); border-radius: 0.8rem; padding: 0.6rem 0.75rem; margin-bottom: 0.8rem; }'
            . '.api-token-page .filter-summary-card .summary-top { display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: center; }'
            . '.api-token-page .filter-summary-card .summary-count { margin-left: auto; color: #64748b; font-size: 0.8rem; }'
            . '.api-token-page .token-actions { min-width: 0; }'
            . '.api-token-page .token-actions-wrap { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; justify-content: flex-start; }'
            . '.api-token-page .rotate-form { display: flex; gap: 0.4rem; align-items: center; }'
            . '.api-token-page .rotate-form .expires-input { width: 96px; }'
            . '.api-token-page .token-prefix { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.76rem; background: #f1f5f9; border-radius: 0.45rem; padding: 0.2rem 0.4rem; color: #334155; display: inline-block; }'
            . '.api-token-page .token-prefix:hover { background: #e2e8f0; }'
            . '.api-token-page .token-list-grid { display: grid; grid-template-columns: 1fr; gap: 0.75rem; }'
            . '.api-token-page .token-item-card { border: 1px solid #e2e8f0; border-radius: 0.85rem; background: #fff; box-shadow: var(--panel-shadow); }'
            . '.api-token-page .token-item-card .token-item-head { display: flex; justify-content: space-between; align-items: center; gap: 0.6rem; padding: 0.75rem 0.85rem; border-bottom: 1px solid #eef2f7; background: #f8fafc; border-top-left-radius: 0.85rem; border-top-right-radius: 0.85rem; }'
            . '.api-token-page .token-item-card .token-item-title { font-size: 0.96rem; font-weight: 700; color: #0f172a; }'
            . '.api-token-page .token-item-card .token-item-subtitle { margin-top: 0.2rem; font-size: 0.76rem; color: #64748b; }'
            . '.api-token-page .token-item-card .token-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.55rem 0.85rem; padding: 0.75rem 0.85rem; }'
            . '.api-token-page .token-item-card .meta-label { display: block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; margin-bottom: 0.18rem; }'
            . '.api-token-page .token-item-card .meta-value { color: #0f172a; font-size: 0.86rem; line-height: 1.35; }'
            . '.api-token-page .token-item-card .token-item-actions { border-top: 1px solid #eef2f7; padding: 0.7rem 0.85rem 0.8rem; }'
            . '.api-token-page .list-head { display: flex; justify-content: space-between; align-items: center; gap: 0.6rem; margin-bottom: 0.75rem; }'
            . '.api-token-page .list-head .title { font-size: 1rem; font-weight: 600; color: #0f172a; }'
            . '.api-token-page .quick-filters { display: inline-flex; flex-wrap: wrap; gap: 0.45rem; }'
            . '.api-token-page .quick-filters .btn { border-radius: 999px; }'
            . '.api-token-page .list-focus-target { scroll-margin-top: 88px; }'
            . '.api-token-page .token-last-used-empty { color: var(--muted-text); font-style: italic; }'
            . '.api-token-page .create-head-card { border: 1px solid #dbeafe; background: linear-gradient(120deg, #f8fbff, #eef7ff); border-radius: 0.85rem; padding: 0.75rem 0.8rem; margin-bottom: 0.75rem; }'
            . '.api-token-page .create-head-card .title { font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0; }'
            . '.api-token-page .create-head-card .desc { margin: 0.3rem 0 0; font-size: 0.78rem; color: #475569; }'
            . '.api-token-page .create-form-card { border: 1px solid #e2e8f0; border-radius: 0.85rem; background: #fff; padding: 0.8rem; }'
            . '.api-token-page .create-safety-card { border: 1px dashed #fdba74; background: #fff7ed; border-radius: 0.8rem; padding: 0.72rem 0.78rem; margin-top: 0.75rem; }'
            . '.api-token-page .create-safety-card .safety-title { font-size: 0.76rem; color: #9a3412; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }'
            . '.api-token-page .create-safety-card ul { margin: 0.45rem 0 0; padding-left: 1rem; color: #7c2d12; font-size: 0.78rem; }'
            . '.api-token-page .pagination-wrap { margin-top: 0.85rem; border-top: 1px solid #e2e8f0; padding-top: 0.75rem; }'
            . '.api-token-page .pagination-meta { font-size: 0.8rem; color: #64748b; }'
            . '@media (max-width: 991.98px) {'
            . '  .api-token-page .token-create-card { position: static; }'
            . '  .api-token-page .token-actions-wrap { flex-direction: column; align-items: stretch; }'
            . '  .api-token-page .rotate-form { width: 100%; }'
            . '  .api-token-page .rotate-form .expires-input { width: 100%; }'
            . '}'
            . '@media (max-width: 767.98px) {'
            . '  .api-token-page .list-head { flex-direction: column; align-items: flex-start; }'
            . '  .api-token-page .filter-summary-card .summary-count { margin-left: 0; }'
            . '  .api-token-page .token-item-card .token-item-head { flex-direction: column; align-items: flex-start; }'
            . '  .api-token-page .token-item-card .token-meta { grid-template-columns: 1fr; }'
            . '}'
            . '</style>';

        $pageScript = '<script>'
            . 'document.addEventListener("DOMContentLoaded", function () {'
            . '  var params = new URLSearchParams(window.location.search);'
            . '  if (!params.has("status") && !params.has("user_id")) { return; }'
            . '  var target = document.getElementById("tokenListSection");'
            . '  if (!target) { return; }'
            . '  target.scrollIntoView({ behavior: "smooth", block: "start" });'
            . '  window.setTimeout(function () { target.focus({ preventScroll: true }); }, 180);'
            . '});'
            . '</script>';

        $pageItemCount = count($tokens);
        $totalTokens = $totalMatching;
        $activeTokens = $activeMatching;
        $inactiveTokens = $inactiveMatching;
        $offsetStart = $totalTokens === 0 ? 0 : (($page - 1) * $perPage + 1);
        $offsetEnd = $totalTokens === 0 ? 0 : min($totalTokens, $offsetStart + $pageItemCount - 1);
        $currentStatusLabel = $status === 'all' ? '全部状态' : '仅 active';
        $currentUserLabel = '全部用户';
        $quickFilterUserQuery = $selectedUserId > 0 ? '&user_id=' . $selectedUserId : '';
        $perPageQuery = '&per_page=' . $perPage;
        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            if ($selectedUserId === $id) {
                $name = (string) ($user['username'] ?? '');
                $role = (string) ($user['role'] ?? '');
                $currentUserLabel = $name . ($role !== '' ? ' (' . $role . ')' : '');
                break;
            }
        }

        $userOptions = '<option value="0">全部用户</option>';
        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            $selected = $selectedUserId === $id ? ' selected' : '';
            $label = (string) ($user['username'] ?? '');
            $role = (string) ($user['role'] ?? '');
            $userOptions .= '<option value="' . $id . '"' . $selected . '>' . htmlspecialchars($label . ' (' . $role . ')', ENT_QUOTES) . '</option>';
        }

        $createUserOptions = '';
        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            $label = (string) ($user['username'] ?? '');
            $role = (string) ($user['role'] ?? '');
            $createUserOptions .= '<option value="' . $id . '">' . htmlspecialchars($label . ' (' . $role . ')', ENT_QUOTES) . '</option>';
        }

        $tokenCards = '';
        foreach ($tokens as $token) {
            $tokenId = (int) ($token['id'] ?? 0);
            $active = (int) ($token['is_active'] ?? 0) === 1;
            $activeBadge = $active ? '<span class="badge rounded-pill text-bg-success">active</span>' : '<span class="badge rounded-pill text-bg-secondary">inactive</span>';
            $expiresAt = (string) (($token['expires_at'] ?? null) ?: 'never');
            $lastUsed = (string) (($token['last_used_at'] ?? null) ?: '-');
            $lastUsedDisplay = $lastUsed === '-' ? '<span class="token-last-used-empty">未使用</span>' : htmlspecialchars($lastUsed, ENT_QUOTES);
            $username = htmlspecialchars((string) ($token['username'] ?? ''), ENT_QUOTES);
            $tokenName = htmlspecialchars((string) ($token['token_name'] ?? ''), ENT_QUOTES);
            $tokenPrefix = htmlspecialchars((string) ($token['token_prefix'] ?? ''), ENT_QUOTES);

            $tokenCards .= '<article class="token-item-card">'
                . '<div class="token-item-head">'
                . '<div><div class="token-item-title">#' . $tokenId . ' · ' . $tokenName . '</div><div class="token-item-subtitle">用户：' . $username . '（ID: ' . (int) ($token['user_id'] ?? 0) . '）</div></div>'
                . $activeBadge
                . '</div>'
                . '<div class="token-meta">'
                . '<div><span class="meta-label">Token 前缀</span><div class="meta-value"><span class="token-prefix">' . $tokenPrefix . '</span></div></div>'
                . '<div><span class="meta-label">过期时间</span><div class="meta-value">' . htmlspecialchars($expiresAt, ENT_QUOTES) . '</div></div>'
                . '<div><span class="meta-label">最近使用</span><div class="meta-value">' . $lastUsedDisplay . '</div></div>'
                . '<div><span class="meta-label">操作说明</span><div class="meta-value text-secondary">支持禁用与在线轮换，轮换后旧 Token 将立即失效。</div></div>'
                . '</div>'
                . '<div class="token-item-actions token-actions">'
                . '<div class="token-actions-wrap">'
                . '<form method="POST" action="/api-tokens/' . $tokenId . '/revoke" onsubmit="return confirm(\'确认禁用该 token？\')">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<button class="btn btn-sm btn-outline-danger" type="submit"' . ($active ? '' : ' disabled') . '>禁用</button>'
                . '</form>'
                . '<form method="POST" action="/api-tokens/' . $tokenId . '/rotate" class="rotate-form">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<input type="number" class="form-control form-control-sm expires-input" name="expires_days" min="0" value="30" required aria-label="轮换有效天数">'
                . '<button class="btn btn-sm btn-outline-primary" type="submit">轮换</button>'
                . '</form>'
                . '</div>'
                . '</div>'
                . '</article>';
        }

        if ($tokenCards === '') {
            $tokenCards = '<div class="card border-0"><div class="card-body text-center py-4">'
                . '<div class="text-muted mb-1">当前筛选条件下暂无 Token</div>'
                . '<div class="small text-secondary">可尝试切换用户或状态条件后重新查询。</div>'
                . '</div></div>';
        }

        $baseQuery = [
            'status' => $status,
            'per_page' => $perPage,
        ];
        if ($selectedUserId > 0) {
            $baseQuery['user_id'] = $selectedUserId;
        }

        $paginationButtons = '';
        if ($totalPages > 1) {
            $firstQuery = $baseQuery;
            $firstQuery['page'] = 1;
            $prevQuery = $baseQuery;
            $prevQuery['page'] = max(1, $page - 1);
            $nextQuery = $baseQuery;
            $nextQuery['page'] = min($totalPages, $page + 1);
            $lastQuery = $baseQuery;
            $lastQuery['page'] = $totalPages;

            $paginationButtons = '<div class="btn-group" role="group" aria-label="分页导航">'
                . '<a class="btn btn-sm btn-outline-secondary' . ($page <= 1 ? ' disabled' : '') . '" href="/api-tokens?' . htmlspecialchars(http_build_query($firstQuery), ENT_QUOTES) . '">首页</a>'
                . '<a class="btn btn-sm btn-outline-secondary' . ($page <= 1 ? ' disabled' : '') . '" href="/api-tokens?' . htmlspecialchars(http_build_query($prevQuery), ENT_QUOTES) . '">上一页</a>'
                . '<a class="btn btn-sm btn-outline-secondary' . ($page >= $totalPages ? ' disabled' : '') . '" href="/api-tokens?' . htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES) . '">下一页</a>'
                . '<a class="btn btn-sm btn-outline-secondary' . ($page >= $totalPages ? ' disabled' : '') . '" href="/api-tokens?' . htmlspecialchars(http_build_query($lastQuery), ENT_QUOTES) . '">末页</a>'
                . '</div>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>API Token 管理</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . $pageStyles
            . '</head><body class="bg-light">'
            . $navigation
            . '<div class="container mt-4 api-token-page">'
            . '<div class="page-head">'
            . '<h3 class="mb-0"><i class="bi bi-key-fill me-2"></i>API Token 管理</h3>'
            . '<p class="subtitle mb-0">集中管理服务访问凭据，支持创建、禁用与轮换，建议按最小权限和周期轮换策略维护。</p>'
            . '</div>'
            . '<div class="row g-3 mb-1">'
            . '<div class="col-md-4"><div class="card summary-card"><div class="card-body py-3"><div class="label">Token 总数</div><div class="value">' . $totalTokens . '</div></div></div></div>'
            . '<div class="col-md-4"><div class="card summary-card"><div class="card-body py-3"><div class="label">Active</div><div class="value text-success">' . $activeTokens . '</div></div></div></div>'
            . '<div class="col-md-4"><div class="card summary-card"><div class="card-body py-3"><div class="label">Inactive</div><div class="value text-secondary">' . $inactiveTokens . '</div></div></div></div>'
            . '</div>'
            . '<div class="row g-3">'
            . '<div class="col-lg-7">'
            . '<div class="card token-list-card"><div class="card-body">'
            . '<form method="GET" action="/api-tokens" class="row g-2 mb-3 token-filter-form">'
            . '<div class="col-md-6"><label class="form-label" for="tokenFilterUser">用户</label><select id="tokenFilterUser" class="form-select" name="user_id">' . $userOptions . '</select></div>'
            . '<div class="col-md-2"><label class="form-label" for="tokenFilterStatus">状态</label><select id="tokenFilterStatus" class="form-select" name="status">'
            . '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>仅 active</option>'
            . '<option value="all"' . ($status === 'all' ? ' selected' : '') . '>全部</option>'
            . '</select></div>'
            . '<div class="col-md-2"><label class="form-label" for="tokenFilterPerPage">每页</label><select id="tokenFilterPerPage" class="form-select" name="per_page">'
            . '<option value="10"' . ($perPage === 10 ? ' selected' : '') . '>10</option>'
            . '<option value="20"' . ($perPage === 20 ? ' selected' : '') . '>20</option>'
            . '<option value="50"' . ($perPage === 50 ? ' selected' : '') . '>50</option>'
            . '</select></div>'
            . '<div class="col-md-2 d-grid align-self-end"><button class="btn btn-outline-primary" type="submit">筛选</button></div>'
            . '</form>'
            . '<div class="filter-summary-card">'
            . '<div class="summary-top">'
            . '<span class="small text-muted">当前筛选：</span>'
            . '<span class="badge text-bg-light border">' . htmlspecialchars($currentUserLabel, ENT_QUOTES) . '</span>'
            . '<span class="badge text-bg-light border">' . htmlspecialchars($currentStatusLabel, ENT_QUOTES) . '</span>'
            . '<span class="summary-count">匹配 ' . $totalTokens . ' 条，当前第 ' . $page . '/' . $totalPages . ' 页</span>'
            . '</div>'
            . '</div>'
            . '<div class="list-head list-focus-target" id="tokenListSection" tabindex="-1">'
            . '<div class="title">Token 列表</div>'
            . '<div class="quick-filters">'
            . '<a class="btn btn-sm ' . ($status === 'active' ? 'btn-primary' : 'btn-outline-primary') . '" href="/api-tokens?status=active' . $quickFilterUserQuery . $perPageQuery . '">仅 active</a>'
            . '<a class="btn btn-sm ' . ($status === 'all' ? 'btn-primary' : 'btn-outline-primary') . '" href="/api-tokens?status=all' . $quickFilterUserQuery . $perPageQuery . '">全部</a>'
            . '</div>'
            . '</div>'
            . '<div class="token-list-grid">' . $tokenCards . '</div>'
            . '<div class="pagination-wrap d-flex flex-wrap align-items-center justify-content-between gap-2">'
            . '<div class="pagination-meta">显示 ' . $offsetStart . '-' . $offsetEnd . ' / 共 ' . $totalTokens . ' 条</div>'
            . $paginationButtons
            . '</div>'
            . '</div></div>'
            . '</div>'
            . '<div class="col-lg-5">'
            . '<div class="card token-create-card"><div class="card-body">'
            . '<div class="create-head-card">'
            . '<h5 class="title">创建新 Token</h5>'
            . '<p class="desc">建议为不同系统或集成方创建独立 token 名称，便于后续审计与轮换。</p>'
            . '</div>'
            . '<div class="create-form-card">'
            . '<form method="POST" action="/api-tokens" class="token-create-form">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<div class="mb-3"><label class="form-label">用户</label><select class="form-select" name="user_id" required>' . $createUserOptions . '</select></div>'
            . '<div class="mb-3"><label class="form-label">Token 名称</label><input class="form-control" name="token_name" value="web-token" required></div>'
            . '<div class="mb-3"><label class="form-label">有效天数（0=永不过期）</label><input type="number" class="form-control" name="expires_days" min="0" value="30" required></div>'
            . '<button class="btn btn-primary w-100" type="submit">创建 Token</button>'
            . '</form>'
            . '</div>'
            . '<div class="create-safety-card">'
            . '<div class="safety-title">安全建议</div>'
            . '<ul>'
            . '<li>创建后仅在结果页展示一次明文 token，请立即保存。</li>'
            . '<li>建议每个业务系统使用独立 token，避免共享凭据。</li>'
            . '<li>推荐设置有效期并定期轮换，异常时及时禁用。</li>'
            . '</ul>'
            . '</div>'
            . '</div></div>'
            . '</div>'
            . '</div>'
                . $pageScript
            . '</div></body></html>';
    }

    private function resultTemplate(string $title, array $data): string
    {
        $resultStyles = '<style>'
            . '.api-token-result .card { border: 0; box-shadow: 0 0.3rem 1rem rgba(15, 23, 42, 0.1); border-radius: 1rem; }'
            . '.api-token-result .result-head { background: linear-gradient(120deg, #14532d, #166534); color: #f0fdf4; border-radius: 0.8rem; padding: 1rem 1.1rem; }'
            . '.api-token-result .list-group-item code { font-size: 0.82rem; max-width: 58%; overflow-wrap: anywhere; }'
            . '@media (max-width: 767.98px) {'
            . '  .api-token-result .list-group-item { flex-direction: column; align-items: flex-start !important; gap: 0.35rem; }'
            . '  .api-token-result .list-group-item code { max-width: 100%; }'
            . '}'
            . '</style>';

        $items = '';
        foreach ($data as $key => $value) {
            $items .= '<li class="list-group-item d-flex justify-content-between">'
                . '<span>' . htmlspecialchars((string) $key, ENT_QUOTES) . '</span>'
                . '<code>' . htmlspecialchars((string) $value, ENT_QUOTES) . '</code>'
                . '</li>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $resultStyles
            . '</head>'
            . '<body class="bg-light"><div class="container mt-5 api-token-result">'
            . '<div class="card"><div class="card-body">'
            . '<div class="result-head mb-3"><h4 class="mb-0"><i class="bi bi-shield-check me-2"></i>' . htmlspecialchars($title, ENT_QUOTES) . '</h4></div>'
            . '<div class="alert alert-warning">请立即复制并安全保存 token，离开此页面后将无法再次查看明文。</div>'
            . '<ul class="list-group mb-3">' . $items . '</ul>'
            . '<a class="btn btn-primary" href="/api-tokens?status=all">返回 Token 管理</a>'
            . '</div></div></div></body></html>';
    }

    private function ensureAdmin(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }

        if (!auth()->isAdmin()) {
            throw HttpException::forbidden('仅管理员可访问 API Token 管理');
        }
    }
}
