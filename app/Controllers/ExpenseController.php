<?php
/**
 * 收租管理系统 - 支出管理控制器
 */

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;

class ExpenseController
{
    public function index(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureExpensePermission();

        $filters = $this->collectFilters();
        $rows = $this->getExpenseRows(auth()->user(), auth()->isAdmin(), $filters);
        $summary = $this->buildSummary($rows);
        $properties = $this->getPropertyOptions(auth()->user(), auth()->isAdmin());
        $categories = $this->getExpenseCategories(true);

        return Response::html($this->indexTemplate($rows, $summary, $filters, $properties, $categories));
    }

    public function create(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureExpensePermission();

        $properties = $this->getPropertyOptions(auth()->user(), auth()->isAdmin());
        $categories = $this->getExpenseCategories(true);

        return Response::html($this->formTemplate($properties, $categories));
    }

    public function store(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureExpensePermission();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $propertyId = (int) ($_POST['property_id'] ?? 0);
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $amountRaw = trim((string) ($_POST['amount'] ?? ''));
        $transactionDate = trim((string) ($_POST['transaction_date'] ?? ''));
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($propertyId > 0) {
            $this->assertPropertyAccessible($propertyId);
        }

        if ($category === '') {
            flash('error', '支出分类不能为空');
            session()->flashInput($_POST);
            return Response::redirect('/expenses/create');
        }

        if (!$this->isExpenseCategoryActive($category)) {
            flash('error', '支出分类无效或已停用，请选择有效分类');
            session()->flashInput($_POST);
            return Response::redirect('/expenses/create');
        }

        if ($description === '') {
            flash('error', '支出描述不能为空');
            session()->flashInput($_POST);
            return Response::redirect('/expenses/create');
        }

        if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
            flash('error', '支出金额必须大于 0');
            session()->flashInput($_POST);
            return Response::redirect('/expenses/create');
        }

        if ($transactionDate === '' || strtotime($transactionDate) === false) {
            flash('error', '交易日期格式无效');
            session()->flashInput($_POST);
            return Response::redirect('/expenses/create');
        }

        $allowedMethods = ['', 'cash', 'bank_transfer', 'alipay', 'wechat_pay', 'other'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            flash('error', '支付方式无效');
            session()->flashInput($_POST);
            return Response::redirect('/expenses/create');
        }

        db()->insert('financial_records', [
            'record_type' => 'expense',
            'category' => mb_substr($category, 0, 50),
            'amount' => number_format((float) $amountRaw, 2, '.', ''),
            'currency' => 'CNY',
            'description' => $description,
            'reference_type' => $propertyId > 0 ? 'property' : 'other',
            'reference_id' => $propertyId > 0 ? $propertyId : null,
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
            'transaction_date' => date('Y-m-d', strtotime($transactionDate)),
            'recorded_by' => (int) auth()->id(),
            'notes' => $notes !== '' ? $notes : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        flash('success', '支出记录已保存');
        return Response::redirect('/expenses');
    }

    public function categories(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureExpensePermission();

        $rows = $this->getExpenseCategoryRows(true);
        return Response::html($this->categoriesTemplate($rows));
    }

    public function categoryStore(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureExpensePermission();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = (string) ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
        $ownerId = auth()->isAdmin() ? null : (int) auth()->id();

        if ($name === '') {
            flash('error', '分类名称不能为空');
            return Response::redirect('/expenses/categories');
        }

        if (mb_strlen($name) > 50) {
            $name = mb_substr($name, 0, 50);
        }

        if ($this->existsCategoryInScope($name, $ownerId)) {
            flash('error', '分类名称在当前作用域已存在');
            return Response::redirect('/expenses/categories');
        }

        try {
            db()->insert('expense_categories', [
                'name' => $name,
                'owner_id' => $ownerId,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            flash('success', '分类添加成功');
        } catch (\Throwable $e) {
            flash('error', '分类添加失败，可能已存在同名分类');
        }

        return Response::redirect('/expenses/categories');
    }

    public function categoryUpdate(int $id): Response
    {
        $this->ensureAuthenticated();
        $this->ensureExpensePermission();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $row = db()->fetch('SELECT id, owner_id FROM expense_categories WHERE id = ? LIMIT 1', [$id]);
        if (!$row) {
            throw HttpException::notFound('分类不存在');
        }

        $rowOwnerId = isset($row['owner_id']) && $row['owner_id'] !== null ? (int) $row['owner_id'] : null;
        if (!auth()->isAdmin()) {
            if ($rowOwnerId === null) {
                flash('error', '默认分类仅管理员可修改');
                return Response::redirect('/expenses/categories');
            }

            if ($rowOwnerId !== (int) auth()->id()) {
                throw HttpException::forbidden('您无权修改该分类');
            }
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = (string) ($_POST['is_active'] ?? '') === '1' ? 1 : 0;

        if ($name === '') {
            flash('error', '分类名称不能为空');
            return Response::redirect('/expenses/categories');
        }

        if (mb_strlen($name) > 50) {
            $name = mb_substr($name, 0, 50);
        }

        if ($this->existsCategoryInScope($name, $rowOwnerId, $id)) {
            flash('error', '分类名称在当前作用域已存在');
            return Response::redirect('/expenses/categories');
        }

        try {
            db()->update('expense_categories', [
                'name' => $name,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
            flash('success', '分类更新成功');
        } catch (\Throwable $e) {
            flash('error', '分类更新失败，可能存在同名分类');
        }

        return Response::redirect('/expenses/categories');
    }

    private function collectFilters(): array
    {
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $propertyId = (int) ($_GET['property_id'] ?? 0);
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));

        if ($dateFrom !== '' && strtotime($dateFrom) === false) {
            $dateFrom = '';
        }
        if ($dateTo !== '' && strtotime($dateTo) === false) {
            $dateTo = '';
        }
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'keyword' => $keyword,
            'category' => $category,
            'property_id' => $propertyId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    private function getExpenseRows(array $user, bool $isAdmin, array $filters): array
    {
        $sql = '
            SELECT
                fr.id,
                fr.category,
                fr.amount,
                fr.description,
                fr.payment_method,
                fr.transaction_date,
                fr.notes,
                fr.reference_type,
                fr.reference_id,
                p.property_name,
                p.owner_id,
                u.real_name AS recorder_name
            FROM financial_records fr
            LEFT JOIN properties p ON fr.reference_type = "property" AND fr.reference_id = p.id
            LEFT JOIN users u ON u.id = fr.recorded_by
            WHERE fr.record_type = "expense"
        ';

        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND (fr.recorded_by = ? OR (fr.reference_type = "property" AND p.owner_id = ?))';
            $uid = (int) ($user['id'] ?? 0);
            $params[] = $uid;
            $params[] = $uid;
        }

        $keyword = (string) ($filters['keyword'] ?? '');
        $category = (string) ($filters['category'] ?? '');
        $propertyId = (int) ($filters['property_id'] ?? 0);
        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');

        if ($keyword !== '') {
            $sql .= ' AND (fr.description LIKE ? OR fr.category LIKE ? OR COALESCE(p.property_name, "") LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($category !== '') {
            $sql .= ' AND fr.category = ?';
            $params[] = $category;
        }

        if ($propertyId > 0) {
            $sql .= ' AND fr.reference_type = "property" AND fr.reference_id = ?';
            $params[] = $propertyId;
        }

        if ($dateFrom !== '') {
            $sql .= ' AND fr.transaction_date >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo !== '') {
            $sql .= ' AND fr.transaction_date <= ?';
            $params[] = $dateTo;
        }

        $sql .= ' ORDER BY fr.transaction_date DESC, fr.id DESC';

        return db()->fetchAll($sql, $params);
    }

    private function buildSummary(array $rows): array
    {
        $total = 0.0;
        $categoryMap = [];

        foreach ($rows as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $total += $amount;
            $cat = (string) ($row['category'] ?? '其他支出');
            if (!isset($categoryMap[$cat])) {
                $categoryMap[$cat] = 0.0;
            }
            $categoryMap[$cat] += $amount;
        }

        arsort($categoryMap);

        return [
            'count' => count($rows),
            'total' => $total,
            'by_category' => $categoryMap,
        ];
    }

    private function getPropertyOptions(array $user, bool $isAdmin): array
    {
        $sql = 'SELECT id, property_name, owner_id FROM properties WHERE 1 = 1';
        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        $sql .= ' ORDER BY property_name ASC';
        return db()->fetchAll($sql, $params);
    }

    private function getExpenseCategories(bool $onlyActive = true): array
    {
        $rows = $this->getExpenseCategoryRows(!$onlyActive);
        if ($rows === []) {
            return $this->defaultExpenseCategories();
        }

        $names = [];
        foreach ($rows as $row) {
            $isActive = (int) ($row['is_active'] ?? 1) === 1;
            if ($onlyActive && !$isActive) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? $this->defaultExpenseCategories() : $names;
    }

    private function getExpenseCategoryRows(bool $includeInactive = true): array
    {
        $sql = '
            SELECT
                ec.id,
                ec.name,
                ec.owner_id,
                ec.is_active,
                ec.sort_order,
                ec.created_at,
                ec.updated_at,
                u.real_name AS owner_name
            FROM expense_categories ec
            LEFT JOIN users u ON u.id = ec.owner_id
            WHERE 1 = 1
        ';
        $params = [];

        if (!auth()->isAdmin()) {
            $sql .= ' AND (ec.owner_id IS NULL OR ec.owner_id = ?)';
            $params[] = (int) auth()->id();
        }

        if (auth()->isAdmin()) {
            $sql .= ' AND ec.owner_id IS NULL';
        }

        if (!$includeInactive) {
            $sql .= ' AND ec.is_active = 1';
        }
        $sql .= ' ORDER BY (ec.owner_id IS NULL) DESC, ec.sort_order ASC, ec.id ASC';

        try {
            return db()->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function existsCategoryInScope(string $name, ?int $ownerId, int $excludeId = 0): bool
    {
        $sql = 'SELECT id FROM expense_categories WHERE name = ?';
        $params = [$name];

        if ($ownerId === null) {
            $sql .= ' AND owner_id IS NULL';
        } else {
            $sql .= ' AND owner_id = ?';
            $params[] = $ownerId;
        }

        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        return (bool) db()->fetch($sql, $params);
    }

    private function isExpenseCategoryActive(string $category): bool
    {
        $category = trim($category);
        if ($category === '') {
            return false;
        }

        return in_array($category, $this->getExpenseCategories(true), true);
    }

    private function defaultExpenseCategories(): array
    {
        return ['房屋修缮', '安全维护', '水路维修', '电路维修', '保洁保养', '管理费用', '其他支出'];
    }

    private function assertPropertyAccessible(int $propertyId): void
    {
        $row = db()->fetch('SELECT id, owner_id FROM properties WHERE id = ? LIMIT 1', [$propertyId]);
        if (!$row) {
            throw HttpException::notFound('关联房产不存在');
        }

        if (!auth()->isAdmin() && (int) ($row['owner_id'] ?? 0) !== (int) auth()->id()) {
            throw HttpException::forbidden('您无权关联该房产');
        }
    }

    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }

    private function ensureExpensePermission(): void
    {
        if (!auth()->isAdmin() && !auth()->isLandlord()) {
            throw HttpException::forbidden('您没有支出管理权限');
        }
    }

    private function indexTemplate(array $rows, array $summary, array $filters, array $properties, array $categories): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'expenses',
            'is_admin' => auth()->isAdmin(),
            'show_user_menu' => true,
            'collapse_id' => 'expensesNavbar',
        ]);

        $keyword = (string) ($filters['keyword'] ?? '');
        $category = (string) ($filters['category'] ?? '');
        $propertyId = (int) ($filters['property_id'] ?? 0);
        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');

        $propertyOptions = '<option value="0">全部房产</option>';
        foreach ($properties as $p) {
            $selected = $propertyId === (int) ($p['id'] ?? 0) ? ' selected' : '';
            $propertyOptions .= '<option value="' . (int) ($p['id'] ?? 0) . '"' . $selected . '>' . htmlspecialchars((string) ($p['property_name'] ?? '')) . '</option>';
        }

        $categoryOptions = '<option value="">全部分类</option>';
        foreach ($categories as $cat) {
            $selected = $category === (string) $cat ? ' selected' : '';
            $categoryOptions .= '<option value="' . htmlspecialchars((string) $cat, ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars((string) $cat) . '</option>';
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            $rowsHtml .= '<tr>'
                . '<td data-label="日期">' . htmlspecialchars((string) ($row['transaction_date'] ?? '')) . '</td>'
                . '<td data-label="分类">' . htmlspecialchars((string) ($row['category'] ?? '')) . '</td>'
                . '<td data-label="房产">' . htmlspecialchars((string) (($row['property_name'] ?? '') !== '' ? $row['property_name'] : '-')) . '</td>'
                . '<td data-label="描述">' . htmlspecialchars((string) ($row['description'] ?? '')) . '</td>'
                . '<td data-label="金额" class="text-danger fw-semibold">¥' . number_format((float) ($row['amount'] ?? 0), 2) . '</td>'
                . '<td data-label="记录人">' . htmlspecialchars((string) (($row['recorder_name'] ?? '') !== '' ? $row['recorder_name'] : '-')) . '</td>'
                . '</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6" class="text-center text-muted">暂无支出记录</td></tr>';
        }

        $categoryBadges = '';
        foreach ((array) ($summary['by_category'] ?? []) as $cat => $amount) {
            $categoryBadges .= '<span class="badge text-bg-light border me-1 mb-1">' . htmlspecialchars((string) $cat) . ' ¥' . number_format((float) $amount, 2) . '</span>';
        }
        if ($categoryBadges === '') {
            $categoryBadges = '<span class="text-muted">暂无分类汇总</span>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>支出记录</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">' . $navbarStyles . '</head><body>'
            . $navigation
            . '<div class="container mt-4">'
            . '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h3 class="mb-0">支出记录</h3><div class="d-flex gap-2"><a href="/expenses/create" class="btn btn-primary">新增支出</a><a href="/expenses/categories" class="btn btn-outline-warning">分类管理</a><a href="/reports/financial" class="btn btn-outline-dark">查看盈利报表</a><a href="/dashboard" class="btn btn-outline-secondary">返回仪表板</a></div></div>'
            . '<form class="row g-2 mb-3" method="GET" action="/expenses">'
            . '<div class="col-md-3"><input class="form-control" type="search" name="keyword" placeholder="描述/分类/房产关键字" value="' . htmlspecialchars($keyword) . '"></div>'
            . '<div class="col-md-2"><select class="form-select" name="category">' . $categoryOptions . '</select></div>'
            . '<div class="col-md-2"><select class="form-select" name="property_id">' . $propertyOptions . '</select></div>'
            . '<div class="col-md-2"><input class="form-control" type="date" name="date_from" value="' . htmlspecialchars($dateFrom) . '"></div>'
            . '<div class="col-md-2"><input class="form-control" type="date" name="date_to" value="' . htmlspecialchars($dateTo) . '"></div>'
            . '<div class="col-md-1 d-grid"><button class="btn btn-outline-primary" type="submit">筛选</button></div>'
            . '</form>'
            . '<div class="row g-2 mb-3">'
            . '<div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">记录条数</div><div class="h5 mb-0">' . number_format((int) ($summary['count'] ?? 0)) . '</div></div></div></div>'
            . '<div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">支出总额</div><div class="h5 mb-0 text-danger">¥' . number_format((float) ($summary['total'] ?? 0), 2) . '</div></div></div></div>'
            . '<div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small mb-1">分类汇总</div>' . $categoryBadges . '</div></div></div>'
            . '</div>'
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-striped align-middle"><thead><tr><th>日期</th><th>分类</th><th>房产</th><th>描述</th><th>金额</th><th>记录人</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div></div>'
            . '</div></body></html>';
    }

    private function formTemplate(array $properties, array $categories): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'expenses',
            'is_admin' => auth()->isAdmin(),
            'show_user_menu' => true,
            'collapse_id' => 'expensesCreateNavbar',
        ]);

        $propertyId = (int) old('property_id', 0);
        $defaultCategory = $categories[0] ?? '房屋修缮';
        $category = (string) old('category', $defaultCategory);
        $description = (string) old('description', '');
        $amount = (string) old('amount', '');
        $transactionDate = (string) old('transaction_date', date('Y-m-d'));
        $paymentMethod = (string) old('payment_method', 'bank_transfer');
        $notes = (string) old('notes', '');

        $propertyOptions = '<option value="0">不关联房产（其他支出）</option>';
        foreach ($properties as $p) {
            $selected = $propertyId === (int) ($p['id'] ?? 0) ? ' selected' : '';
            $propertyOptions .= '<option value="' . (int) ($p['id'] ?? 0) . '"' . $selected . '>' . htmlspecialchars((string) ($p['property_name'] ?? '')) . '</option>';
        }

        if (!in_array($category, $categories, true) && $category !== '') {
            $categories[] = $category;
        }

        $categoryOptions = '';
        foreach ($categories as $cat) {
            $selected = $category === (string) $cat ? ' selected' : '';
            $categoryOptions .= '<option value="' . htmlspecialchars((string) $cat, ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars((string) $cat) . '</option>';
        }

        $alerts = '';
        if (has_flash('error')) {
            $alerts .= '<div class="alert alert-danger">' . htmlspecialchars((string) get_flash('error')) . '</div>';
        }
        if (has_flash('success')) {
            $alerts .= '<div class="alert alert-success">' . htmlspecialchars((string) get_flash('success')) . '</div>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>新增支出</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">' . $navbarStyles . '</head><body>'
            . $navigation
            . '<div class="container mt-4">'
            . $alerts
            . '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h3 class="mb-0">新增支出记录</h3><a href="/expenses/categories" class="btn btn-outline-warning btn-sm">分类管理</a></div>'
            . '<form method="POST" action="/expenses">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<div class="row g-3">'
            . '<div class="col-md-4"><label class="form-label">关联房产</label><select class="form-select" name="property_id">' . $propertyOptions . '</select></div>'
            . '<div class="col-md-4"><label class="form-label">支出分类</label><select class="form-select" name="category" required>' . $categoryOptions . '</select></div>'
            . '<div class="col-md-4"><label class="form-label">支出金额</label><input class="form-control" type="number" name="amount" step="0.01" min="0.01" value="' . htmlspecialchars($amount) . '" required></div>'
            . '<div class="col-md-8"><label class="form-label">支出描述</label><input class="form-control" type="text" name="description" placeholder="例如：A101 卫生间水管更换" value="' . htmlspecialchars($description) . '" required></div>'
            . '<div class="col-md-4"><label class="form-label">交易日期</label><input class="form-control" type="date" name="transaction_date" value="' . htmlspecialchars($transactionDate) . '" required></div>'
            . '<div class="col-md-4"><label class="form-label">支付方式</label><select class="form-select" name="payment_method">'
            . '<option value=""' . ($paymentMethod === '' ? ' selected' : '') . '>未记录</option>'
            . '<option value="bank_transfer"' . ($paymentMethod === 'bank_transfer' ? ' selected' : '') . '>银行转账</option>'
            . '<option value="cash"' . ($paymentMethod === 'cash' ? ' selected' : '') . '>现金</option>'
            . '<option value="alipay"' . ($paymentMethod === 'alipay' ? ' selected' : '') . '>支付宝</option>'
            . '<option value="wechat_pay"' . ($paymentMethod === 'wechat_pay' ? ' selected' : '') . '>微信支付</option>'
            . '<option value="other"' . ($paymentMethod === 'other' ? ' selected' : '') . '>其他</option>'
            . '</select></div>'
            . '<div class="col-md-8"><label class="form-label">备注（可选）</label><input class="form-control" type="text" name="notes" value="' . htmlspecialchars($notes) . '"></div>'
            . '</div>'
            . '<div class="d-flex gap-2 mt-4"><a href="/expenses" class="btn btn-secondary">返回</a><button class="btn btn-primary" type="submit">保存支出</button></div>'
            . '</form></div></body></html>';
    }

    private function categoriesTemplate(array $rows): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'expenses',
            'is_admin' => auth()->isAdmin(),
            'show_user_menu' => true,
            'collapse_id' => 'expensesCategoryNavbar',
        ]);

        $alerts = '';
        if (has_flash('error')) {
            $alerts .= '<div class="alert alert-danger">' . htmlspecialchars((string) get_flash('error')) . '</div>';
        }
        if (has_flash('success')) {
            $alerts .= '<div class="alert alert-success">' . htmlspecialchars((string) get_flash('success')) . '</div>';
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            $isActive = (int) ($row['is_active'] ?? 0) === 1;
            $sortOrder = (int) ($row['sort_order'] ?? 0);
            $ownerId = isset($row['owner_id']) && $row['owner_id'] !== null ? (int) $row['owner_id'] : null;
            $ownerLabel = $ownerId === null
                ? '系统默认'
                : ((string) ($row['owner_name'] ?? '') !== '' ? (string) $row['owner_name'] : ('用户#' . $ownerId));
            $editable = auth()->isAdmin() || $ownerId === (int) auth()->id();
            $editable = $editable && ($ownerId !== null || auth()->isAdmin());

            if ($editable) {
                $rowsHtml .= '<tr>'
                    . '<td>' . $id . '</td>'
                    . '<td>' . htmlspecialchars($ownerLabel) . '</td>'
                    . '<td>'
                    . '<form class="d-flex gap-2 align-items-center" method="POST" action="/expenses/categories/' . $id . '">'
                    . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                    . '<input class="form-control form-control-sm" type="text" name="name" value="' . htmlspecialchars($name) . '" required>'
                    . '<input class="form-control form-control-sm" style="max-width:120px" type="number" name="sort_order" value="' . $sortOrder . '">'
                    . '<div class="form-check ms-2"><input class="form-check-input" type="checkbox" name="is_active" value="1"' . ($isActive ? ' checked' : '') . '></div>'
                    . '<button class="btn btn-sm btn-outline-primary" type="submit">保存</button>'
                    . '</form>'
                    . '</td>'
                    . '<td>' . ($isActive ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-secondary">停用</span>') . '</td>'
                    . '</tr>';
            } else {
                $rowsHtml .= '<tr>'
                    . '<td>' . $id . '</td>'
                    . '<td>' . htmlspecialchars($ownerLabel) . '</td>'
                    . '<td>' . htmlspecialchars($name) . ' <span class="text-muted small">(仅管理员可修改默认分类)</span></td>'
                    . '<td>' . ($isActive ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-secondary">停用</span>') . '</td>'
                    . '</tr>';
            }
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="4" class="text-center text-muted">暂无分类数据</td></tr>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>支出分类管理</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">' . $navbarStyles . '</head><body>'
            . $navigation
            . '<div class="container mt-4">'
            . $alerts
            . '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h3 class="mb-0">支出分类管理</h3><div class="d-flex gap-2"><a href="/expenses/create" class="btn btn-primary">新增支出</a><a href="/expenses" class="btn btn-outline-secondary">返回支出列表</a></div></div>'
            . '<div class="card mb-3"><div class="card-body">'
            . '<h6 class="mb-3">新增分类</h6>'
            . '<form class="row g-2" method="POST" action="/expenses/categories">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<div class="col-md-5"><input class="form-control" type="text" name="name" placeholder="分类名称，例如：消防巡检" required></div>'
            . '<div class="col-md-2"><input class="form-control" type="number" name="sort_order" value="100" placeholder="排序"></div>'
            . '<div class="col-md-2 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" checked><label class="form-check-label">启用</label></div></div>'
            . '<div class="col-md-3 d-grid"><button class="btn btn-outline-primary" type="submit">添加分类</button></div>'
            . '</form>'
            . '</div></div>'
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-striped align-middle"><thead><tr><th style="width:80px">ID</th><th style="width:180px">归属</th><th>分类信息（名称 / 排序 / 启用）</th><th style="width:120px">状态</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div></div>'
            . '</div></body></html>';
    }
}
