<?php
/**
 * 收租管理系统 - 通知控制器
 *
 * 提供通知中心列表与已读管理。
 */

namespace App\Controllers;

use App\Core\Response;
use App\Core\HttpException;

class NotificationController
{
    public function index(): Response
    {
        $this->ensureAuthenticated();

        $filter = (string) ($_GET['filter'] ?? 'unread');
        $onlyUnread = $filter === 'unread';
        $type = trim((string) ($_GET['type'] ?? ''));
        $priority = trim((string) ($_GET['priority'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 20)));

        $result = $this->getNotifications((int) auth()->id(), $onlyUnread, $type, $priority, $page, $perPage);

        return Response::html($this->notificationListTemplate($result['items'], [
            'only_unread' => $onlyUnread,
            'filter' => $filter,
            'type' => $type,
            'priority' => $priority,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $result['total'],
        ]));
    }

    public function markRead(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $notification = db()->fetch('SELECT id, user_id, is_read FROM notifications WHERE id = ? LIMIT 1', [$id]);
        if (!$notification || (int) $notification['user_id'] !== (int) auth()->id()) {
            throw HttpException::notFound('通知不存在或无权访问');
        }

        if ((int) $notification['is_read'] === 0) {
            db()->update('notifications', [
                'is_read' => 1,
                'read_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $notification['id']]);
        }

        return Response::redirect('/notifications?status=read&' . $this->buildQueryStringFromContext($_GET));
    }

    public function markAllRead(): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        db()->execute(
            'UPDATE notifications SET is_read = 1, read_at = ? WHERE user_id = ? AND is_read = 0',
            [date('Y-m-d H:i:s'), (int) auth()->id()]
        );

        return Response::redirect('/notifications?status=all-read&' . $this->buildQueryStringFromContext($_GET));
    }

    private function getNotifications(int $userId, bool $onlyUnread, string $type, string $priority, int $page, int $perPage): array
    {
        $whereSql = ' WHERE n.user_id = ?';
        $params = [$userId];

        if ($onlyUnread) {
            $whereSql .= ' AND n.is_read = 0';
        }

        if ($type !== '') {
            $whereSql .= ' AND n.type = ?';
            $params[] = $type;
        }

        if ($priority !== '') {
            $whereSql .= ' AND n.priority = ?';
            $params[] = $priority;
        }

        $countSql = 'SELECT COUNT(1) FROM notifications n' . $whereSql;
        $total = (int) db()->fetchColumn($countSql, $params);

        $offset = ($page - 1) * $perPage;

        $sql = '
            SELECT
                n.id,
                n.type,
                n.title,
                n.content,
                n.priority,
                n.is_read,
                n.read_at,
                n.action_url,
                n.action_text,
                n.created_at,
                n.related_type,
                n.related_id,
                c.contract_number,
                p.property_name
            FROM notifications n
            LEFT JOIN contracts c ON n.related_type = "contract" AND c.id = n.related_id
            LEFT JOIN properties p ON c.property_id = p.id
        ' . $whereSql . '
            ORDER BY n.created_at DESC
            LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

        return [
            'items' => db()->fetchAll($sql, $params),
            'total' => $total,
        ];
    }

    private function notificationListTemplate(array $notifications, array $context): string
    {
        $onlyUnread = (bool) ($context['only_unread'] ?? true);
        $filter = (string) ($context['filter'] ?? 'unread');
        $type = (string) ($context['type'] ?? '');
        $priorityFilter = (string) ($context['priority'] ?? '');
        $page = (int) ($context['page'] ?? 1);
        $perPage = (int) ($context['per_page'] ?? 20);
        $total = (int) ($context['total'] ?? 0);

        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $lastPage));

        $baseQuery = $this->buildQueryString([
            'filter' => $filter,
            'type' => $type,
            'priority' => $priorityFilter,
            'per_page' => $perPage,
        ]);

        $rangeStart = $total > 0 ? (($page - 1) * $perPage + 1) : 0;
        $rangeEnd = min($page * $perPage, $total);

        $typeOptions = [
            '' => '全部类型',
            'system' => '系统',
            'payment' => '支付',
            'contract' => '合同',
            'property' => '房产',
            'reminder' => '提醒',
        ];

        $priorityOptions = [
            '' => '全部优先级',
            'low' => '低',
            'normal' => '普通',
            'high' => '高',
            'urgent' => '紧急',
        ];

        $typeSelectHtml = '';
        foreach ($typeOptions as $value => $label) {
            $typeSelectHtml .= '<option value="' . htmlspecialchars($value) . '"' . ($type === $value ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
        }

        $prioritySelectHtml = '';
        foreach ($priorityOptions as $value => $label) {
            $prioritySelectHtml .= '<option value="' . htmlspecialchars($value) . '"' . ($priorityFilter === $value ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
        }

        $paginationHtml = '';
        if ($lastPage > 1) {
            $prevPage = max(1, $page - 1);
            $nextPage = min($lastPage, $page + 1);
            $paginationHtml = '<nav aria-label="通知分页"><ul class="pagination pagination-sm mb-0">'
                . '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="/notifications?' . $baseQuery . '&page=' . $prevPage . '">上一页</a></li>'
                . '<li class="page-item disabled"><span class="page-link">' . $page . ' / ' . $lastPage . '</span></li>'
                . '<li class="page-item' . ($page >= $lastPage ? ' disabled' : '') . '"><a class="page-link" href="/notifications?' . $baseQuery . '&page=' . $nextPage . '">下一页</a></li>'
                . '</ul></nav>';
        }

        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'notifications',
            'is_admin' => auth()->isAdmin(),
            'show_user_menu' => true,
            'collapse_id' => 'notificationsMainNavbar',
        ]);

        $status = (string) ($_GET['status'] ?? '');
        $alertHtml = '';
        if ($status === 'read') {
            $alertHtml = '<div class="alert alert-success">通知已标记为已读。</div>';
        } elseif ($status === 'all-read') {
            $alertHtml = '<div class="alert alert-success">未读通知已全部标记为已读。</div>';
        }

        $rows = '';
        foreach ($notifications as $notification) {
            $isRead = (int) ($notification['is_read'] ?? 0) === 1;
            $notificationPriority = (string) ($notification['priority'] ?? 'normal');
            $priorityClass = match ($notificationPriority) {
                'urgent' => 'danger',
                'high' => 'warning',
                'low' => 'secondary',
                default => 'primary',
            };

            $relatedText = '';
            if ((string) ($notification['related_type'] ?? '') === 'contract') {
                $relatedText = '<div class="small text-muted">关联合同：' . htmlspecialchars((string) ($notification['contract_number'] ?? '-'))
                    . ' / ' . htmlspecialchars((string) ($notification['property_name'] ?? '-')) . '</div>';
            }

            $actionBtn = '';
            if (!$isRead) {
                $actionBtn = '<form method="POST" action="/notifications/' . (int) $notification['id'] . '/read?' . $this->buildQueryString([
                    'filter' => $filter,
                    'type' => $type,
                    'priority' => $priorityFilter,
                    'page' => $page,
                    'per_page' => $perPage,
                ]) . '">'
                    . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                    . '<button class="btn btn-sm btn-outline-success" type="submit">标记已读</button>'
                    . '</form>';
            }

            $jumpBtn = '';
            if (!empty($notification['action_url'])) {
                $jumpBtn = '<a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars((string) $notification['action_url']) . '">' . htmlspecialchars((string) ($notification['action_text'] ?? '前往查看')) . '</a>';
            }

            $rows .= '<tr' . ($isRead ? '' : ' class="table-warning"') . '>'
                . '<td>' . (int) $notification['id'] . '</td>'
                . '<td><span class="badge bg-' . $priorityClass . '">' . htmlspecialchars($notificationPriority) . '</span></td>'
                . '<td>' . htmlspecialchars((string) $notification['title']) . '</td>'
                . '<td>'
                . htmlspecialchars((string) $notification['content'])
                . $relatedText
                . '</td>'
                . '<td>' . htmlspecialchars((string) $notification['created_at']) . '</td>'
                . '<td>' . ($isRead ? '<span class="badge bg-success">已读</span>' : '<span class="badge bg-danger">未读</span>') . '</td>'
                . '<td class="d-flex gap-2">' . $jumpBtn . $actionBtn . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="text-center text-muted py-4">当前筛选下暂无通知</td></tr>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>通知中心</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . '</head><body class="bg-light">'
            . $navigation
            . '<div class="container mt-4">'
            . '<div class="d-flex justify-content-between align-items-center mb-3">'
            . '<h3 class="mb-0"><i class="bi bi-bell-fill me-2"></i>通知中心</h3>'
            . '<form method="POST" action="/notifications/mark-all-read?' . $this->buildQueryString([
                'filter' => $filter,
                'type' => $type,
                'priority' => $priorityFilter,
                'page' => $page,
                'per_page' => $perPage,
            ]) . '">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<button class="btn btn-outline-success" type="submit">全部标记已读</button>'
            . '</form>'
            . '</div>'
            . '<form class="row g-2 mb-3" method="GET" action="/notifications">'
            . '<div class="col-md-3"><select class="form-select" name="filter">'
            . '<option value="unread"' . ($onlyUnread ? ' selected' : '') . '>仅看未读</option>'
            . '<option value="all"' . (!$onlyUnread ? ' selected' : '') . '>查看全部</option>'
            . '</select></div>'
            . '<div class="col-md-3"><select class="form-select" name="type">' . $typeSelectHtml . '</select></div>'
            . '<div class="col-md-3"><select class="form-select" name="priority">' . $prioritySelectHtml . '</select></div>'
            . '<div class="col-md-1"><select class="form-select" name="per_page">'
            . '<option value="10"' . ($perPage === 10 ? ' selected' : '') . '>10</option>'
            . '<option value="20"' . ($perPage === 20 ? ' selected' : '') . '>20</option>'
            . '<option value="50"' . ($perPage === 50 ? ' selected' : '') . '>50</option>'
            . '</select></div>'
            . '<div class="col-md-2 d-grid"><button class="btn btn-outline-primary" type="submit">应用筛选</button></div>'
            . '</form>'
            . $alertHtml
            . '<div class="d-flex justify-content-between align-items-center mb-2">'
            . '<small class="text-muted">显示 ' . $rangeStart . '-' . $rangeEnd . ' / 共 ' . $total . ' 条</small>'
            . $paginationHtml
            . '</div>'
            . '<div class="card"><div class="card-body table-responsive">'
            . '<table class="table table-hover align-middle"><thead><tr>'
            . '<th>ID</th><th>优先级</th><th>标题</th><th>内容</th><th>时间</th><th>状态</th><th>操作</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '</div></div></div></body></html>';
    }

    private function buildQueryStringFromContext(array $input): string
    {
        return $this->buildQueryString([
            'filter' => (string) ($input['filter'] ?? 'unread'),
            'type' => (string) ($input['type'] ?? ''),
            'priority' => (string) ($input['priority'] ?? ''),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => max(10, min(100, (int) ($input['per_page'] ?? 20))),
        ]);
    }

    private function buildQueryString(array $params): string
    {
        return http_build_query($params);
    }

    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }
}
