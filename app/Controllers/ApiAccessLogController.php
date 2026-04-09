<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;

class ApiAccessLogController
{
    public function index(): Response
    {
        $this->ensureAdmin();

        $filters = $this->collectFilters();
        $query = $this->buildWhereClause($filters);

        $countSql = 'SELECT COUNT(1) AS total FROM api_access_logs l' . $query['where_sql'];
        $countRow = db()->fetch($countSql, $query['params']);
        $total = (int) ($countRow['total'] ?? 0);

        $rows = $this->fetchLogRows($query['where_sql'], $query['params'], (int) $filters['per_page'], (int) $filters['page']);
        $allRows = $this->fetchLogRows($query['where_sql'], $query['params'], null, null);
        $summary = $this->buildSummary($allRows);

        $users = db()->fetchAll(
            'SELECT id, username, role
             FROM users
             WHERE status = ?
             ORDER BY id ASC',
            ['active']
        );

        $tokens = db()->fetchAll(
            'SELECT id, token_name
             FROM api_tokens
             WHERE is_active = 1
             ORDER BY id DESC
             LIMIT 200'
        );

        $filters['total'] = $total;

        return Response::html($this->indexTemplate($rows, $users, $tokens, $filters, $summary));
    }

    public function exportCsv(): Response
    {
        $this->ensureAdmin();

        $filters = $this->collectFilters();
        $query = $this->buildWhereClause($filters);
        $rows = $this->fetchLogRows($query['where_sql'], $query['params'], null, null);

        $csv = $this->toCsv($rows);
        $filename = 'api_access_logs_' . date('Ymd_His') . '.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function indexTemplate(array $rows, array $users, array $tokens, array $filters, array $summary): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'api_access_logs',
            'is_admin' => true,
            'show_user_menu' => true,
            'collapse_id' => 'apiAccessLogNavbar',
        ]);

        $pageStyles = '<style>'
            . '.api-audit-page .card { border: 0; box-shadow: 0 0.25rem 1rem rgba(15, 23, 42, 0.08); }'
            . '.api-audit-page .page-head { background: linear-gradient(120deg, #0f172a, #0f766e); color: #f8fafc; border-radius: 1rem; padding: 1.1rem 1.25rem; margin-bottom: 1rem; }'
            . '.api-audit-page .page-head .subtitle { color: rgba(248, 250, 252, 0.85); margin-top: 0.25rem; }'
            . '.api-audit-page .summary-box { border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 0.9rem; background: #fff; height: 100%; }'
            . '.api-audit-page .summary-box .label { color: #64748b; font-size: 0.8rem; }'
            . '.api-audit-page .summary-box .value { font-size: 1.45rem; font-weight: 700; line-height: 1.2; margin-top: 0.2rem; }'
            . '.api-audit-page .filter-form .form-label { font-size: 0.78rem; color: #64748b; margin-bottom: 0.22rem; }'
            . '.api-audit-page .filter-panel { border-radius: 0.8rem; }'
            . '.api-audit-page .filter-panel .card-body { padding: 0.65rem 0.7rem; }'
            . '.api-audit-page .filter-panel .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 0.55rem; flex-wrap: wrap; margin-bottom: 0.45rem; }'
            . '.api-audit-page .filter-panel .panel-title { font-size: 0.88rem; font-weight: 600; color: #0f172a; margin: 0; }'
            . '.api-audit-page .filter-panel .panel-title i { font-size: 0.78rem; }'
            . '.api-audit-page .filter-panel .filter-count { font-size: 0.72rem; color: #64748b; background: transparent; border: 0; border-radius: 0; padding: 0; }'
            . '.api-audit-page .filter-form .control-shell { border: 1px solid #e5e7eb; border-radius: 0.6rem; padding: 0.35rem 0.4rem; background: #fff; }'
            . '.api-audit-page .filter-form .basic-grid { row-gap: 0.35rem; }'
            . '.api-audit-page .filter-form .basic-grid > [class*="col-"] { display: flex; }'
            . '.api-audit-page .filter-form .advanced-filters { margin-top: 0.45rem; border-top: 1px dashed #dbe3ef; padding-top: 0.45rem; }'
            . '.api-audit-page .filter-form .advanced-filters > summary { list-style: none; cursor: pointer; color: #475569; font-size: 0.78rem; user-select: none; }'
            . '.api-audit-page .filter-form .advanced-filters > summary::-webkit-details-marker { display: none; }'
            . '.api-audit-page .filter-form .advanced-filters > summary i { margin-right: 0.25rem; font-size: 0.72rem; }'
            . '.api-audit-page .filter-form .advanced-filters[open] > summary i { transform: rotate(90deg); }'
            . '.api-audit-page .filter-form .advanced-content { margin-top: 0.45rem; row-gap: 0.35rem; }'
            . '.api-audit-page .filter-form .advanced-content > [class*="col-"] { display: flex; }'
            . '.api-audit-page .filter-form .advanced-content .control-shell { width: 100%; }'
            . '.api-audit-page .filter-form .range-grid { width: 100%; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.3rem; }'
            . '.api-audit-page .filter-form .range-grid .btn { display: inline-flex; justify-content: center; align-items: center; }'
            . '.api-audit-page .filter-form .form-select, .api-audit-page .filter-form .form-control { border-color: #cbd5e1; }'
            . '.api-audit-page .filter-form .form-select:focus, .api-audit-page .filter-form .form-control:focus { border-color: #0f766e; box-shadow: 0 0 0 0.2rem rgba(15, 118, 110, 0.15); }'
            . '.api-audit-page .filter-form .control-shell { width: 100%; display: flex; flex-direction: column; justify-content: flex-start; min-height: 70px; }'
            . '.api-audit-page .filter-form .control-shell .form-select, .api-audit-page .filter-form .control-shell .form-control { min-height: 31px; }'
            . '.api-audit-page .filter-form .action-btn { font-weight: 600; }'
            . '.api-audit-page .filter-form .control-shell.action-shell { justify-content: space-between; }'
            . '.api-audit-page .filter-form .filter-actions { display: flex; gap: 0.28rem; width: 100%; align-items: stretch; }'
            . '.api-audit-page .filter-form .filter-actions .btn { flex: 1 1 0; min-width: 0; padding: 0.38rem 0.2rem; line-height: 1.15; border-radius: 0.5rem; white-space: nowrap; }'
            . '.api-audit-page .filter-form .filter-actions .btn i { font-size: 0.86rem; margin-right: 0.3rem; }'
            . '.api-audit-page .filter-form .btn-text { display: inline; font-size: 0.76rem; }'
            . '.api-audit-page .audit-path { max-width: 280px; word-break: break-all; }'
            . '.api-audit-page .audit-message { max-width: 220px; color: #475569; }'
            . '.api-audit-page .range-btn-group .btn { font-size: 0.82rem; padding: 0.35rem 0.45rem; }'
            . '.api-audit-page .range-btn-group .range-label { display: inline; }'
            . '.api-audit-page .range-btn-group .range-icon { margin-right: 0.22rem; }'
            . '.api-audit-page .table thead th { white-space: nowrap; font-size: 0.82rem; }'
            . '.api-audit-page .table tbody tr:hover { background: #f8fafc; }'
            . '@media (max-width: 767.98px) {'
            . '  .api-audit-page .filter-panel .card-body { padding: 0.58rem 0.58rem; }'
            . '  .api-audit-page .filter-form .control-shell { padding: 0.32rem 0.36rem; }'
            . '  .api-audit-page .filter-form .basic-grid { row-gap: 0.3rem; }'
            . '  .api-audit-page .filter-form .basic-grid > [class*="col-"] { display: block; }'
            . '  .api-audit-page .filter-form .control-shell { min-height: 0; }'
            . '  .api-audit-page .filter-form .advanced-filters { margin-top: 0.35rem; padding-top: 0.35rem; }'
            . '  .api-audit-page .filter-form .advanced-content > [class*="col-"] { display: block; }'
            . '  .api-audit-page .filter-form .range-grid { grid-template-columns: 1fr; }'
            . '  .api-audit-page .filter-form .filter-actions { justify-content: stretch; flex-wrap: wrap; gap: 0.35rem; }'
            . '  .api-audit-page .filter-form .filter-actions .btn { width: 100%; min-width: 0; padding: 0.48rem 0.62rem; }'
            . '  .api-audit-page .filter-form .btn-text { font-size: 0.82rem; }'
            . '  .api-audit-page .range-btn-group .btn { padding: 0.4rem 0.5rem; }'
            . '  .api-audit-page .range-btn-group .range-icon { margin-right: 0.25rem; }'
            . '  .api-audit-page .table.mobile-table thead { display: none; }'
            . '  .api-audit-page .table.mobile-table tbody tr { display: block; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 0.6rem 0.75rem; margin-bottom: 0.75rem; background: #fff; }'
            . '  .api-audit-page .table.mobile-table tbody td { display: flex; justify-content: space-between; gap: 0.75rem; border: 0; padding: 0.35rem 0; }'
            . '  .api-audit-page .table.mobile-table tbody td::before { content: attr(data-label); font-size: 0.78rem; color: #64748b; flex: 0 0 36%; }'
            . '  .api-audit-page .audit-path, .api-audit-page .audit-message { max-width: 58%; text-align: right; }'
            . '}'
            . '</style>';

        $userId = (int) ($filters['user_id'] ?? 0);
        $tokenId = (int) ($filters['token_id'] ?? 0);
        $statusCode = (string) ($filters['status_code'] ?? '');
        $authType = (string) ($filters['auth_type'] ?? '');
        $pathKeyword = (string) ($filters['path_keyword'] ?? '');
        $startAt = (string) ($filters['start_at'] ?? '');
        $endAt = (string) ($filters['end_at'] ?? '');
        $range = (string) ($filters['range'] ?? '');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $total = max(0, (int) ($filters['total'] ?? 0));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $activeFilterCount = 0;
        $activeFilterCount += $userId > 0 ? 1 : 0;
        $activeFilterCount += $tokenId > 0 ? 1 : 0;
        $activeFilterCount += $statusCode !== '' ? 1 : 0;
        $activeFilterCount += $authType !== '' ? 1 : 0;
        $activeFilterCount += $pathKeyword !== '' ? 1 : 0;
        $activeFilterCount += $startAt !== '' ? 1 : 0;
        $activeFilterCount += $endAt !== '' ? 1 : 0;
        $activeFilterCount += $range !== '' ? 1 : 0;
        $advancedOpen = $pathKeyword !== '' || $startAt !== '' || $endAt !== '' || $range !== '';

        $userOptions = '<option value="0">全部用户</option>';
        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            $selected = $userId === $id ? ' selected' : '';
            $label = (string) ($user['username'] ?? '');
            $role = (string) ($user['role'] ?? '');
            $userOptions .= '<option value="' . $id . '"' . $selected . '>' . htmlspecialchars($label . ' (' . $role . ')', ENT_QUOTES) . '</option>';
        }

        $tokenOptions = '<option value="0">全部 Token</option>';
        foreach ($tokens as $token) {
            $id = (int) ($token['id'] ?? 0);
            $selected = $tokenId === $id ? ' selected' : '';
            $label = (string) ($token['token_name'] ?? 'token');
            $tokenOptions .= '<option value="' . $id . '"' . $selected . '>#' . $id . ' ' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            $code = (int) ($row['status_code'] ?? 0);
            $statusClass = $code >= 500 ? 'danger' : ($code >= 400 ? 'warning' : 'success');
            $auth = (string) ($row['auth_type'] ?? 'none');
            $authClass = match ($auth) {
                'token' => 'primary',
                'session' => 'info',
                default => 'secondary',
            };

            $rowsHtml .= '<tr>'
                . '<td data-label="ID">' . (int) ($row['id'] ?? 0) . '</td>'
                . '<td data-label="用户">' . htmlspecialchars((string) (($row['username'] ?? '-') ?: '-'), ENT_QUOTES) . '</td>'
                . '<td data-label="Token">' . (int) (($row['token_id'] ?? 0) ?: 0) . '</td>'
                . '<td data-label="方法"><code>' . htmlspecialchars((string) ($row['request_method'] ?? ''), ENT_QUOTES) . '</code></td>'
                . '<td data-label="路径"><span class="audit-path">' . htmlspecialchars((string) ($row['request_path'] ?? ''), ENT_QUOTES) . '</span></td>'
                . '<td data-label="状态"><span class="badge bg-' . $statusClass . '">' . $code . '</span></td>'
                . '<td data-label="认证"><span class="badge bg-' . $authClass . '">' . htmlspecialchars($auth, ENT_QUOTES) . '</span></td>'
                . '<td data-label="IP">' . htmlspecialchars((string) ($row['ip_address'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td data-label="消息"><span class="audit-message">' . htmlspecialchars((string) (($row['message'] ?? '-') ?: '-'), ENT_QUOTES) . '</span></td>'
                . '<td data-label="时间">' . htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES) . '</td>'
                . '</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="10" class="text-center text-muted py-4">暂无访问日志</td></tr>';
        }

        $baseQuery = http_build_query([
            'user_id' => $userId,
            'token_id' => $tokenId,
            'status_code' => $statusCode,
            'auth_type' => $authType,
            'path_keyword' => $pathKeyword,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'range' => $range,
            'per_page' => $perPage,
        ]);

        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>API 访问审计</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . $pageStyles
            . '</head><body class="bg-light">'
            . $navigation
            . '<div class="container mt-4 api-audit-page">'
            . '<div class="page-head">'
            . '<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">'
            . '<h3 class="mb-0"><i class="bi bi-clipboard-data-fill me-2"></i>API 访问审计</h3>'
            . '<a class="btn btn-light btn-sm" href="/api-tokens?status=all">查看 Token 管理</a>'
            . '</div>'
            . '<p class="subtitle mb-0">按用户、Token、状态码与时间窗口快速审计 API 调用行为，并支持导出证据。</p>'
            . '</div>'
            . '<div class="card mb-3"><div class="card-body">'
            . '<div class="row g-3">'
            . '<div class="col-md-3"><div class="summary-box"><div class="label">总请求</div><div class="value">' . (int) ($summary['total'] ?? 0) . '</div></div></div>'
            . '<div class="col-md-3"><div class="summary-box"><div class="label">2xx 成功</div><div class="value text-success">' . (int) ($summary['success_2xx'] ?? 0) . '</div></div></div>'
            . '<div class="col-md-3"><div class="summary-box"><div class="label">4xx 客户端错误</div><div class="value text-warning">' . (int) ($summary['client_4xx'] ?? 0) . '</div></div></div>'
            . '<div class="col-md-3"><div class="summary-box"><div class="label">5xx 服务端错误</div><div class="value text-danger">' . (int) ($summary['server_5xx'] ?? 0) . '</div></div></div>'
            . '</div>'
            . '<div class="row g-3 mt-1">'
            . '<div class="col-md-6"><div class="summary-box"><div class="text-muted small mb-1">认证方式分布</div><div>'
            . '<span class="badge bg-primary me-2">token: ' . (int) (($summary['auth_counts']['token'] ?? 0)) . '</span>'
            . '<span class="badge bg-info me-2">session: ' . (int) (($summary['auth_counts']['session'] ?? 0)) . '</span>'
            . '<span class="badge bg-secondary">none: ' . (int) (($summary['auth_counts']['none'] ?? 0)) . '</span>'
            . '</div></div></div>'
            . '<div class="col-md-6"><div class="summary-box"><div class="text-muted small mb-1">Top 路径</div><div class="small">' . $this->renderTopPaths((array) ($summary['top_paths'] ?? [])) . '</div></div></div>'
            . '</div>'
            . '</div></div>'
            . '<div class="card filter-panel mb-3"><div class="card-body">'
            . '<div class="panel-head">'
            . '<h5 class="panel-title"><i class="bi bi-funnel-fill me-2"></i>筛选条件</h5>'
            . '<span class="filter-count">已启用 ' . $activeFilterCount . ' 项</span>'
            . '</div>'
            . '<form method="GET" action="/api-access-logs" class="filter-form">'
            . '<div class="row g-2 basic-grid align-items-end">'
            . '<div class="col-lg-2 col-md-2"><div class="control-shell"><label class="form-label" for="auditUser">用户</label><select id="auditUser" class="form-select form-select-sm" name="user_id">' . $userOptions . '</select></div></div>'
            . '<div class="col-lg-2 col-md-2"><div class="control-shell"><label class="form-label" for="auditToken">Token</label><select id="auditToken" class="form-select form-select-sm" name="token_id">' . $tokenOptions . '</select></div></div>'
            . '<div class="col-lg-2 col-md-2"><div class="control-shell"><label class="form-label" for="auditStatusCode">状态码</label><input id="auditStatusCode" class="form-control form-control-sm" name="status_code" placeholder="状态码" value="' . htmlspecialchars($statusCode, ENT_QUOTES) . '"></div></div>'
            . '<div class="col-lg-2 col-md-2"><div class="control-shell"><label class="form-label" for="auditPerPage">每页</label><select id="auditPerPage" class="form-select form-select-sm" name="per_page">'
            . '<option value="1"' . ($perPage === 1 ? ' selected' : '') . '>1</option>'
            . '<option value="10"' . ($perPage === 10 ? ' selected' : '') . '>10</option>'
            . '<option value="20"' . ($perPage === 20 ? ' selected' : '') . '>20</option>'
            . '<option value="50"' . ($perPage === 50 ? ' selected' : '') . '>50</option>'
            . '<option value="100"' . ($perPage === 100 ? ' selected' : '') . '>100</option>'
            . '</select></div></div>'
            . '<div class="col-lg-2 col-md-2"><div class="control-shell"><label class="form-label" for="auditAuthType">认证方式</label><select id="auditAuthType" class="form-select form-select-sm" name="auth_type">'
            . '<option value="">全部认证</option>'
            . '<option value="token"' . ($authType === 'token' ? ' selected' : '') . '>token</option>'
            . '<option value="session"' . ($authType === 'session' ? ' selected' : '') . '>session</option>'
            . '<option value="none"' . ($authType === 'none' ? ' selected' : '') . '>none</option>'
            . '</select></div></div>'
            . '<div class="col-lg-2 col-md-2"><div class="control-shell action-shell"><label class="form-label">操作</label><div class="filter-actions">'
            . '<button class="btn btn-sm btn-primary action-btn" type="submit" title="应用筛选" aria-label="应用筛选"><i class="bi bi-funnel"></i><span class="btn-text">应用</span></button>'
            . '<a class="btn btn-sm btn-outline-success action-btn" href="/api-access-logs/export?' . $baseQuery . '" title="导出 CSV" aria-label="导出 CSV"><i class="bi bi-download"></i><span class="btn-text">导出</span></a>'
            . '<a class="btn btn-sm btn-outline-secondary action-btn" href="/api-access-logs" title="重置筛选" aria-label="重置筛选"><i class="bi bi-arrow-counterclockwise"></i><span class="btn-text">重置</span></a>'
            . '</div></div></div>'
            . '</div>'
            . '<input type="hidden" name="range" value="' . htmlspecialchars($range, ENT_QUOTES) . '">'
            . '<div class="advanced-wrapper"><details class="advanced-filters"' . ($advancedOpen ? ' open' : '') . '>'
            . '<summary><i class="bi bi-caret-right-fill"></i>高级筛选（路径与时间窗口）</summary>'
            . '<div class="row g-2 advanced-content align-items-end">'
            . '<div class="col-lg-4 col-md-12"><div class="control-shell"><label class="form-label" for="auditPathKeyword">路径关键字</label><input id="auditPathKeyword" class="form-control form-control-sm" name="path_keyword" placeholder="例如 /api/notifications" value="' . htmlspecialchars($pathKeyword, ENT_QUOTES) . '"></div></div>'
            . '<div class="col-lg-4 col-md-6"><div class="control-shell"><label class="form-label" for="auditStartAt">开始时间</label><input id="auditStartAt" class="form-control form-control-sm" name="start_at" placeholder="YYYY-MM-DD HH:MM:SS" value="' . htmlspecialchars($startAt, ENT_QUOTES) . '"></div></div>'
            . '<div class="col-lg-4 col-md-6"><div class="control-shell"><label class="form-label" for="auditEndAt">结束时间</label><input id="auditEndAt" class="form-control form-control-sm" name="end_at" placeholder="YYYY-MM-DD HH:MM:SS" value="' . htmlspecialchars($endAt, ENT_QUOTES) . '"></div></div>'
            . '<div class="col-12"><div class="range-grid range-btn-group" role="group" aria-label="quick ranges">'
            . '<a class="btn btn-sm ' . ($range === 'today' ? 'btn-dark' : 'btn-outline-dark') . '" href="/api-access-logs?' . $this->buildRangeQuery($filters, 'today') . '" title="今日" aria-label="今日"><i class="bi bi-calendar-day range-icon"></i><span class="range-label">今日</span></a>'
            . '<a class="btn btn-sm ' . ($range === 'last_24h' ? 'btn-dark' : 'btn-outline-dark') . '" href="/api-access-logs?' . $this->buildRangeQuery($filters, 'last_24h') . '" title="近24小时" aria-label="近24小时"><i class="bi bi-clock-history range-icon"></i><span class="range-label">近24小时</span></a>'
            . '<a class="btn btn-sm ' . ($range === 'last_7d' ? 'btn-dark' : 'btn-outline-dark') . '" href="/api-access-logs?' . $this->buildRangeQuery($filters, 'last_7d') . '" title="近7天" aria-label="近7天"><i class="bi bi-calendar-week range-icon"></i><span class="range-label">近7天</span></a>'
            . '<a class="btn btn-sm ' . ($range === '' ? 'btn-dark' : 'btn-outline-dark') . '" href="/api-access-logs?' . $this->buildRangeQuery($filters, '') . '" title="全部时间" aria-label="全部时间"><i class="bi bi-infinity range-icon"></i><span class="range-label">全部时间</span></a>'
            . '</div></div>'
            . '</div>'
            . '</details></div>'
            . '</form>'
            . '</div></div>'
            . '<div class="d-flex justify-content-between align-items-center mb-2">'
            . '<small class="text-muted">共 ' . $total . ' 条记录</small>'
            . '<nav><ul class="pagination pagination-sm mb-0">'
            . '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="/api-access-logs?' . $baseQuery . '&page=' . $prevPage . '">上一页</a></li>'
            . '<li class="page-item disabled"><span class="page-link">' . $page . ' / ' . $totalPages . '</span></li>'
            . '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="/api-access-logs?' . $baseQuery . '&page=' . $nextPage . '">下一页</a></li>'
            . '</ul></nav></div>'
            . '<div class="card"><div class="card-body table-responsive">'
            . '<table class="table table-hover align-middle mobile-table"><thead><tr>'
            . '<th>ID</th><th>用户</th><th>Token</th><th>方法</th><th>路径</th><th>状态</th><th>认证</th><th>IP</th><th>消息</th><th>时间</th>'
            . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
            . '</div></div>'
            . '</div></body></html>';
    }

    private function collectFilters(): array
    {
        $range = trim((string) ($_GET['range'] ?? ''));
        $startAt = trim((string) ($_GET['start_at'] ?? ''));
        $endAt = trim((string) ($_GET['end_at'] ?? ''));

        if ($range !== '' && $startAt === '' && $endAt === '') {
            $now = time();
            if ($range === 'today') {
                $startAt = date('Y-m-d 00:00:00', $now);
                $endAt = date('Y-m-d 23:59:59', $now);
            } elseif ($range === 'last_24h') {
                $startAt = date('Y-m-d H:i:s', strtotime('-24 hours', $now));
                $endAt = date('Y-m-d H:i:s', $now);
            } elseif ($range === 'last_7d') {
                $startAt = date('Y-m-d H:i:s', strtotime('-7 days', $now));
                $endAt = date('Y-m-d H:i:s', $now);
            }
        }

        return [
            'user_id' => max(0, (int) ($_GET['user_id'] ?? 0)),
            'token_id' => max(0, (int) ($_GET['token_id'] ?? 0)),
            'status_code' => trim((string) ($_GET['status_code'] ?? '')),
            'auth_type' => trim((string) ($_GET['auth_type'] ?? '')),
            'path_keyword' => trim((string) ($_GET['path_keyword'] ?? '')),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'range' => in_array($range, ['today', 'last_24h', 'last_7d'], true) ? $range : '',
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => max(1, min(100, (int) ($_GET['per_page'] ?? 20))),
        ];
    }

    private function buildWhereClause(array $filters): array
    {
        $where = [];
        $params = [];

        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $where[] = 'l.user_id = ?';
            $params[] = $userId;
        }

        $tokenId = (int) ($filters['token_id'] ?? 0);
        if ($tokenId > 0) {
            $where[] = 'l.token_id = ?';
            $params[] = $tokenId;
        }

        $statusCode = (string) ($filters['status_code'] ?? '');
        if ($statusCode !== '' && ctype_digit($statusCode)) {
            $where[] = 'l.status_code = ?';
            $params[] = (int) $statusCode;
        }

        $authType = (string) ($filters['auth_type'] ?? '');
        if ($authType !== '' && in_array($authType, ['token', 'session', 'none'], true)) {
            $where[] = 'l.auth_type = ?';
            $params[] = $authType;
        }

        $pathKeyword = (string) ($filters['path_keyword'] ?? '');
        if ($pathKeyword !== '') {
            $where[] = 'l.request_path LIKE ?';
            $params[] = '%' . $pathKeyword . '%';
        }

        $startAt = $this->normalizeDateTime((string) ($filters['start_at'] ?? ''), true);
        if ($startAt !== null) {
            $where[] = 'l.created_at >= ?';
            $params[] = $startAt;
        }

        $endAt = $this->normalizeDateTime((string) ($filters['end_at'] ?? ''), false);
        if ($endAt !== null) {
            $where[] = 'l.created_at <= ?';
            $params[] = $endAt;
        }

        return [
            'where_sql' => $where === [] ? '' : (' WHERE ' . implode(' AND ', $where)),
            'params' => $params,
        ];
    }

    private function fetchLogRows(string $whereSql, array $params, ?int $perPage, ?int $page): array
    {
        $sql = 'SELECT
                l.id,
                l.user_id,
                u.username,
                l.token_id,
                t.token_name,
                l.request_path,
                l.request_method,
                l.status_code,
                l.auth_type,
                l.ip_address,
                l.user_agent,
                l.message,
                l.created_at
             FROM api_access_logs l
             LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN api_tokens t ON t.id = l.token_id'
            . $whereSql
            . ' ORDER BY l.id DESC';

        if ($perPage !== null && $page !== null) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        return db()->fetchAll($sql, $params);
    }

    private function toCsv(array $rows): string
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return '';
        }

        fputcsv($fp, ['id', 'user_id', 'username', 'token_id', 'token_name', 'request_method', 'request_path', 'status_code', 'auth_type', 'ip_address', 'user_agent', 'message', 'created_at'], ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($fp, [
                (int) ($row['id'] ?? 0),
                ($row['user_id'] ?? null) === null ? '' : (int) $row['user_id'],
                (string) (($row['username'] ?? '') ?: ''),
                ($row['token_id'] ?? null) === null ? '' : (int) $row['token_id'],
                (string) (($row['token_name'] ?? '') ?: ''),
                (string) ($row['request_method'] ?? ''),
                (string) ($row['request_path'] ?? ''),
                (int) ($row['status_code'] ?? 0),
                (string) ($row['auth_type'] ?? ''),
                (string) ($row['ip_address'] ?? ''),
                (string) (($row['user_agent'] ?? '') ?: ''),
                (string) (($row['message'] ?? '') ?: ''),
                (string) ($row['created_at'] ?? ''),
            ], ',', '"', '');
        }

        rewind($fp);
        $content = stream_get_contents($fp);
        fclose($fp);

        return (string) $content;
    }

    private function normalizeDateTime(string $value, bool $isStart): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            $trimmed .= $isStart ? ' 00:00:00' : ' 23:59:59';
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function buildSummary(array $rows): array
    {
        $authCounts = [
            'token' => 0,
            'session' => 0,
            'none' => 0,
        ];
        $pathCounts = [];

        $success2xx = 0;
        $client4xx = 0;
        $server5xx = 0;

        foreach ($rows as $row) {
            $code = (int) ($row['status_code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                $success2xx++;
            } elseif ($code >= 400 && $code < 500) {
                $client4xx++;
            } elseif ($code >= 500) {
                $server5xx++;
            }

            $auth = (string) ($row['auth_type'] ?? 'none');
            if (!isset($authCounts[$auth])) {
                $auth = 'none';
            }
            $authCounts[$auth]++;

            $path = (string) ($row['request_path'] ?? '');
            if ($path !== '') {
                if (!isset($pathCounts[$path])) {
                    $pathCounts[$path] = 0;
                }
                $pathCounts[$path]++;
            }
        }

        arsort($pathCounts);
        $topPaths = array_slice($pathCounts, 0, 5, true);

        return [
            'total' => count($rows),
            'success_2xx' => $success2xx,
            'client_4xx' => $client4xx,
            'server_5xx' => $server5xx,
            'auth_counts' => $authCounts,
            'top_paths' => $topPaths,
        ];
    }

    private function renderTopPaths(array $topPaths): string
    {
        if ($topPaths === []) {
            return '暂无数据';
        }

        $parts = [];
        foreach ($topPaths as $path => $count) {
            $parts[] = '<span class="me-2"><code>' . htmlspecialchars((string) $path, ENT_QUOTES) . '</code> (' . (int) $count . ')</span>';
        }

        return implode('<br>', $parts);
    }

    private function buildRangeQuery(array $filters, string $range): string
    {
        $query = [
            'user_id' => (int) ($filters['user_id'] ?? 0),
            'token_id' => (int) ($filters['token_id'] ?? 0),
            'status_code' => (string) ($filters['status_code'] ?? ''),
            'auth_type' => (string) ($filters['auth_type'] ?? ''),
            'path_keyword' => (string) ($filters['path_keyword'] ?? ''),
            'per_page' => (int) ($filters['per_page'] ?? 20),
            'page' => 1,
            'range' => $range,
            // 使用快捷范围时，清空手输时间
            'start_at' => '',
            'end_at' => '',
        ];

        return http_build_query($query);
    }

    private function ensureAdmin(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }

        if (!auth()->isAdmin()) {
            throw HttpException::forbidden('仅管理员可访问 API 访问审计');
        }
    }
}
