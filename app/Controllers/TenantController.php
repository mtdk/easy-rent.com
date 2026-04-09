<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;

class TenantController
{
    public function bills(): Response
    {
        $user = $this->ensureTenant();

        $tenantName = trim((string) ($user['real_name'] ?? ''));
        $tenantPhone = trim((string) ($user['phone'] ?? ''));
        $tenantEmail = trim((string) ($user['email'] ?? ''));

        if ($tenantName === '' && $tenantPhone === '' && $tenantEmail === '') {
            return Response::html($this->emptyTemplate('账单列表', '缺少租客识别信息，请联系管理员维护手机号/邮箱/姓名。'));
        }

        $where = [];
        $params = [];

        if ($tenantPhone !== '') {
            $where[] = 'c.tenant_phone = ?';
            $params[] = $tenantPhone;
        }
        if ($tenantEmail !== '') {
            $where[] = 'c.tenant_email = ?';
            $params[] = $tenantEmail;
        }
        if ($tenantName !== '') {
            $where[] = 'c.tenant_name = ?';
            $params[] = $tenantName;
        }

        if ($where === []) {
            return Response::html($this->emptyTemplate('账单列表', '未配置可用的租客匹配条件。'));
        }

        $rows = db()->fetchAll(
            'SELECT
                rp.id,
                rp.payment_period,
                rp.due_date,
                rp.amount_due,
                rp.amount_paid,
                rp.payment_status,
                rp.payment_method,
                rp.late_fee,
                c.contract_number,
                c.tenant_name,
                p.property_name
             FROM rent_payments rp
             INNER JOIN contracts c ON c.id = rp.contract_id
             INNER JOIN properties p ON p.id = c.property_id
             WHERE (' . implode(' OR ', $where) . ')
             ORDER BY rp.due_date DESC, rp.id DESC
             LIMIT 200',
            $params
        );

        return Response::html($this->billsTemplate($rows, $user));
    }

    public function notifications(): Response
    {
        $user = $this->ensureTenant();

        $items = db()->fetchAll(
            'SELECT id, type, title, content, priority, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 200',
            [(int) ($user['id'] ?? 0)]
        );

        return Response::html($this->notificationsTemplate($items, $user));
    }

    private function ensureTenant(): array
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }

        if (!auth()->isTenant()) {
            throw HttpException::forbidden('仅租客可访问该页面');
        }

        return auth()->user() ?? [];
    }

    private function billsTemplate(array $rows, array $user): string
    {
        $name = htmlspecialchars((string) ($user['real_name'] ?? $user['username'] ?? '租客'), ENT_QUOTES);

        $tbody = '';
        foreach ($rows as $row) {
            $status = (string) ($row['payment_status'] ?? 'pending');
            $statusClass = match ($status) {
                'paid' => 'success',
                'overdue' => 'danger',
                'partial' => 'warning',
                default => 'secondary',
            };

            $tbody .= '<tr>'
                . '<td>' . (int) ($row['id'] ?? 0) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['payment_period'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['property_name'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['contract_number'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>¥' . number_format((float) ($row['amount_due'] ?? 0), 2) . '</td>'
                . '<td>' . (($row['amount_paid'] ?? null) === null ? '-' : '¥' . number_format((float) $row['amount_paid'], 2)) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['due_date'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td><span class="badge bg-' . $statusClass . '">' . htmlspecialchars($status, ENT_QUOTES) . '</span></td>'
                . '</tr>';
        }

        if ($tbody === '') {
            $tbody = '<tr><td colspan="8" class="text-center text-muted py-4">暂无账单记录</td></tr>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>租客账单</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css"></head>'
            . '<body class="bg-light"><div class="container mt-4">'
            . '<div class="d-flex justify-content-between align-items-center mb-3"><h3 class="mb-0"><i class="bi bi-receipt me-2"></i>我的账单</h3>'
            . '<div class="d-flex gap-2"><a class="btn btn-outline-primary" href="/tenant/notifications">我的通知</a><a class="btn btn-outline-secondary" href="/auth/logout">退出登录</a></div></div>'
            . '<div class="alert alert-info">当前登录：' . $name . '</div>'
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-hover align-middle"><thead><tr>'
            . '<th>ID</th><th>账期</th><th>房产</th><th>合同号</th><th>应付</th><th>实付</th><th>应付日</th><th>状态</th>'
            . '</tr></thead><tbody>' . $tbody . '</tbody></table></div></div>'
            . '</div></body></html>';
    }

    private function notificationsTemplate(array $items, array $user): string
    {
        $name = htmlspecialchars((string) ($user['real_name'] ?? $user['username'] ?? '租客'), ENT_QUOTES);

        $rows = '';
        foreach ($items as $item) {
            $priority = (string) ($item['priority'] ?? 'normal');
            $priorityClass = match ($priority) {
                'urgent' => 'danger',
                'high' => 'warning',
                'low' => 'secondary',
                default => 'primary',
            };

            $rows .= '<tr>'
                . '<td>' . (int) ($item['id'] ?? 0) . '</td>'
                . '<td><span class="badge bg-' . $priorityClass . '">' . htmlspecialchars($priority, ENT_QUOTES) . '</span></td>'
                . '<td>' . htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($item['content'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . (((int) ($item['is_read'] ?? 0)) === 1 ? '<span class="badge bg-success">已读</span>' : '<span class="badge bg-danger">未读</span>') . '</td>'
                . '<td>' . htmlspecialchars((string) ($item['created_at'] ?? ''), ENT_QUOTES) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="text-center text-muted py-4">暂无通知</td></tr>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>租客通知</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css"></head>'
            . '<body class="bg-light"><div class="container mt-4">'
            . '<div class="d-flex justify-content-between align-items-center mb-3"><h3 class="mb-0"><i class="bi bi-bell-fill me-2"></i>我的通知</h3>'
            . '<div class="d-flex gap-2"><a class="btn btn-outline-primary" href="/tenant/bills">我的账单</a><a class="btn btn-outline-secondary" href="/auth/logout">退出登录</a></div></div>'
            . '<div class="alert alert-info">当前登录：' . $name . '</div>'
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-hover align-middle"><thead><tr>'
            . '<th>ID</th><th>优先级</th><th>标题</th><th>内容</th><th>状态</th><th>时间</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>'
            . '</div></body></html>';
    }

    private function emptyTemplate(string $title, string $message): string
    {
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"></head><body class="bg-light">'
            . '<div class="container mt-5"><div class="alert alert-warning">' . htmlspecialchars($message, ENT_QUOTES) . '</div>'
            . '<a class="btn btn-outline-secondary" href="/auth/logout">退出登录</a></div></body></html>';
    }
}
