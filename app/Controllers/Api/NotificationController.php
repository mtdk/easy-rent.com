<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\Response;

class NotificationController
{
    public function index(): Response
    {
        $this->ensureAuthenticated();

        $filter = trim((string) ($_GET['filter'] ?? 'unread'));
        $onlyUnread = $filter !== 'all';
        $type = trim((string) ($_GET['type'] ?? ''));
        $priority = trim((string) ($_GET['priority'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 20)));

        $whereSql = ' WHERE n.user_id = ?';
        $params = [(int) auth()->id()];

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

        $total = (int) db()->fetchColumn('SELECT COUNT(1) FROM notifications n' . $whereSql, $params);
        $offset = ($page - 1) * $perPage;

        $rows = db()->fetchAll(
            'SELECT
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
                n.related_id
            FROM notifications n'
            . $whereSql
            . ' ORDER BY n.created_at DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return Response::json([
            'success' => true,
            'filters' => [
                'filter' => $onlyUnread ? 'unread' : 'all',
                'type' => $type,
                'priority' => $priority,
                'page' => $page,
                'per_page' => $perPage,
            ],
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
            'items' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'type' => (string) ($row['type'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                    'content' => (string) ($row['content'] ?? ''),
                    'priority' => (string) ($row['priority'] ?? 'normal'),
                    'is_read' => ((int) ($row['is_read'] ?? 0)) === 1,
                    'read_at' => (string) ($row['read_at'] ?? ''),
                    'action_url' => (string) ($row['action_url'] ?? ''),
                    'action_text' => (string) ($row['action_text'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'related_type' => (string) ($row['related_type'] ?? ''),
                    'related_id' => (int) ($row['related_id'] ?? 0),
                ];
            }, $rows),
        ]);
    }

    public function unreadCount(): Response
    {
        $this->ensureAuthenticated();

        $count = (int) db()->fetchColumn(
            'SELECT COUNT(1) FROM notifications WHERE user_id = ? AND is_read = 0',
            [(int) auth()->id()]
        );

        return Response::json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }
}
