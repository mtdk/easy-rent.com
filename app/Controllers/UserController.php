<?php
/**
 * 收租管理系统 - 用户管理控制器
 *
 * 提供管理员用户管理的列表、创建、查看、编辑、删除能力。
 */

namespace App\Controllers;

use App\Core\Response;
use App\Core\HttpException;

class UserController
{
    public function profile(): Response
    {
        $this->ensureAuthenticated();

        $user = auth()->user();
        if (!$user) {
            throw HttpException::unauthorized('请先登录');
        }

        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'dashboard',
            'is_admin' => auth()->isAdmin(),
            'show_user_menu' => true,
            'user_label' => (string) ($user['real_name'] ?? $user['username'] ?? '用户中心'),
            'collapse_id' => 'profileNavbar',
        ]);
        $alerts = $this->renderFlashAlerts();

        $roleMap = [
            'admin' => '系统管理员',
            'landlord' => '房东',
            'tenant' => '租客',
        ];
        $roleLabel = $roleMap[(string) ($user['role'] ?? '')] ?? (string) ($user['role'] ?? '-');
        $selfId = (int) ($user['id'] ?? 0);
        $passwordLink = $selfId > 0
            ? '<a class="btn btn-outline-warning" href="/users/' . $selfId . '/password">修改密码</a>'
            : '';

        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>个人资料</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . '</head><body>'
            . $navigation
            . '<div class="container mt-4">'
            . $alerts
            . '<div class="card"><div class="card-header"><h4 class="mb-0">个人资料</h4></div><div class="card-body">'
            . '<div class="row g-3">'
            . '<div class="col-md-6"><label class="form-label text-muted">用户名</label><div class="form-control bg-light">' . htmlspecialchars((string) ($user['username'] ?? '-'), ENT_QUOTES) . '</div></div>'
            . '<div class="col-md-6"><label class="form-label text-muted">姓名</label><div class="form-control bg-light">' . htmlspecialchars((string) ($user['real_name'] ?? '-'), ENT_QUOTES) . '</div></div>'
            . '<div class="col-md-6"><label class="form-label text-muted">邮箱</label><div class="form-control bg-light">' . htmlspecialchars((string) ($user['email'] ?? '-'), ENT_QUOTES) . '</div></div>'
            . '<div class="col-md-6"><label class="form-label text-muted">电话</label><div class="form-control bg-light">' . htmlspecialchars((string) ($user['phone'] ?? '-'), ENT_QUOTES) . '</div></div>'
            . '<div class="col-md-6"><label class="form-label text-muted">角色</label><div class="form-control bg-light">' . htmlspecialchars($roleLabel, ENT_QUOTES) . '</div></div>'
            . '<div class="col-md-6"><label class="form-label text-muted">状态</label><div class="form-control bg-light">' . htmlspecialchars((string) ($user['status'] ?? '-'), ENT_QUOTES) . '</div></div>'
            . '</div>'
            . '<div class="mt-3 d-flex gap-2">' . $passwordLink . '<a class="btn btn-outline-secondary" href="/dashboard">返回仪表板</a></div>'
            . '</div></div>'
            . '</div></body></html>';

        return Response::html($html);
    }

    public function index(): Response
    {
        $this->ensureAdmin();

        $role = trim((string) ($_GET['role'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $search = trim((string) ($_GET['search'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(10, min(50, (int) ($_GET['per_page'] ?? 20)));

        $filters = [
            'role' => $role,
            'status' => $status,
            'search' => $search,
        ];

        $result = auth()->getUsers($filters, $page, $perPage);

        return Response::html($this->userListTemplate(
            $result['users'],
            $result['pagination'],
            $filters
        ));
    }

    public function create(): Response
    {
        $this->ensureAdmin();

        return Response::html($this->userFormTemplate('创建用户', '/users', 'POST', [
            'username' => '',
            'email' => '',
            'real_name' => '',
            'phone' => '',
            'role' => 'landlord',
            'status' => 'active',
        ], true));
    }

    public function store(): Response
    {
        $this->ensureAdmin();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        try {
            $result = $this->collectUserInput(true, true);
            if (!empty($result['errors'])) {
                session()->flashInput($this->sanitizeInputForFlash($_POST));
                flash('form_errors', $result['errors']);
                flash('error', '请检查输入项后重试');
                return Response::redirect('/users/create');
            }

            $data = $result['data'];
            $userId = auth()->register($data);
            flash('success', '用户创建成功');
            return Response::redirect('/users/' . $userId);
        } catch (\Throwable $e) {
            session()->flashInput($this->sanitizeInputForFlash($_POST));
            flash('error', $e->getMessage());
            return Response::redirect('/users/create');
        }
    }

    public function show(int $id): Response
    {
        $this->ensureAdmin();

        $user = $this->findUserById($id);
        if (!$user) {
            throw HttpException::notFound('用户不存在');
        }

        return Response::html($this->userDetailTemplate($user));
    }

    public function edit(int $id): Response
    {
        $this->ensureAdmin();

        $user = $this->findUserById($id);
        if (!$user) {
            throw HttpException::notFound('用户不存在');
        }

        return Response::html($this->userFormTemplate('编辑用户', '/users/' . $id, 'PUT', $user, false));
    }

    public function update(int $id): Response
    {
        $this->ensureAdmin();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $user = $this->findUserById($id);
        if (!$user) {
            throw HttpException::notFound('用户不存在');
        }

        try {
            $result = $this->collectUserInput(false, false);
            if (!empty($result['errors'])) {
                session()->flashInput($this->sanitizeInputForFlash($_POST));
                flash('form_errors', $result['errors']);
                flash('error', '请检查输入项后重试');
                return Response::redirect('/users/' . $id . '/edit');
            }

            $data = $result['data'];
            auth()->updateUser($id, $data);
            flash('success', '用户信息更新成功');
            return Response::redirect('/users/' . $id);
        } catch (\Throwable $e) {
            session()->flashInput($this->sanitizeInputForFlash($_POST));
            flash('error', $e->getMessage());
            return Response::redirect('/users/' . $id . '/edit');
        }
    }

    public function showPasswordForm(int $id): Response
    {
        $this->ensureCanManagePassword($id);

        $user = $this->findUserById($id);
        if (!$user) {
            throw HttpException::notFound('用户不存在');
        }

        return Response::html($this->passwordFormTemplate($user));
    }

    public function updatePassword(int $id): Response
    {
        $this->ensureCanManagePassword($id);

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $user = $this->findUserWithPasswordHashById($id);
        if (!$user) {
            throw HttpException::notFound('用户不存在');
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $errors = [];

        if ($currentPassword === '') {
            $errors['current_password'] = '请输入旧密码';
        } elseif (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
            $errors['current_password'] = '旧密码不正确';
        }

        if ($newPassword === '') {
            $errors['new_password'] = '请输入新密码';
        }

        if ($confirmPassword === '') {
            $errors['confirm_password'] = '请再次输入新密码';
        } elseif ($newPassword !== '' && $confirmPassword !== $newPassword) {
            $errors['confirm_password'] = '两次输入的新密码不一致';
        }

        if ($currentPassword !== '' && $newPassword !== '' && $currentPassword === $newPassword) {
            $errors['new_password'] = '新密码不能与旧密码相同';
        }

        if ($newPassword !== '') {
            $strength = auth()->validatePasswordStrength($newPassword);
            if (!($strength['valid'] ?? false)) {
                $errors['new_password'] = '密码强度不足：' . implode('；', $strength['errors'] ?? []);
            }
        }

        if (!empty($errors)) {
            flash('password_form_errors', $errors);
            flash('error', '请检查密码输入项后重试');
            return Response::redirect('/users/' . $id . '/password');
        }

        try {
            auth()->updateUser($id, ['password' => $newPassword]);
            flash('success', '密码修改成功');
            if (auth()->isAdmin()) {
                return Response::redirect('/users/' . $id);
            }

            return Response::redirect('/profile');
        } catch (\Throwable $e) {
            flash('error', '密码修改失败：' . $e->getMessage());
            return Response::redirect('/users/' . $id . '/password');
        }
    }

    public function destroy(int $id): Response
    {
        $this->ensureAdmin();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        if ((int) auth()->id() === $id) {
            flash('error', '不能删除当前登录用户');
            return Response::redirect('/users/' . $id);
        }

        if (!$this->findUserById($id)) {
            throw HttpException::notFound('用户不存在');
        }

        $deleted = auth()->deleteUser($id);
        if (!$deleted) {
            flash('error', '删除失败');
            return Response::redirect('/users/' . $id);
        }

        flash('success', '用户删除成功');
        return Response::redirect('/users');
    }

    private function findUserById(int $id): ?array
    {
        return db()->fetch(
            'SELECT id, username, email, real_name, phone, role, status, last_login_at, created_at, updated_at FROM users WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    private function findUserWithPasswordHashById(int $id): ?array
    {
        return db()->fetch(
            'SELECT id, username, password_hash FROM users WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    private function collectUserInput(bool $requirePassword, bool $allowPasswordChange): array
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $realName = trim((string) ($_POST['real_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];

        if (!$allowPasswordChange && $password !== '') {
            $errors['password'] = '请在独立的修改密码页面更新密码';
            $password = '';
        }

        if ($username === '') {
            $errors['username'] = '请输入用户名';
        } elseif (!preg_match('/^[A-Za-z0-9_]{4,20}$/', $username)) {
            $errors['username'] = '用户名需为 4-20 位字母/数字/下划线';
        }

        if ($email === '') {
            $errors['email'] = '请输入邮箱';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '请输入有效邮箱，例如 name@example.com';
        }

        if ($realName === '') {
            $errors['real_name'] = '请输入姓名';
        }

        if ($phone !== '' && !preg_match('/^1[3-9][0-9]{9}$/', $phone)) {
            $errors['phone'] = '手机号需为 11 位中国大陆手机号，例如 13800138000';
        }

        if (!in_array($role, ['admin', 'landlord'], true)) {
            $errors['role'] = '角色不合法';
        }

        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $errors['status'] = '状态不合法';
        }

        if ($requirePassword && $password === '') {
            $errors['password'] = '创建用户时密码不能为空';
        }

        $data = [
            'username' => $username,
            'email' => $email,
            'real_name' => $realName,
            'phone' => $phone,
            'role' => $role,
            'status' => $status,
        ];

        if ($password !== '') {
            $strength = auth()->validatePasswordStrength($password);
            if (!($strength['valid'] ?? false)) {
                $errors['password'] = '密码强度不足：' . implode('；', $strength['errors'] ?? []);
            } else {
                $data['password'] = $password;
            }
        }

        return [
            'data' => $data,
            'errors' => $errors,
        ];
    }

    private function userListTemplate(array $users, array $pagination, array $filters): string
    {
        $page = (int) ($pagination['page'] ?? 1);
        $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
        $total = (int) ($pagination['total'] ?? 0);
        $perPage = (int) ($pagination['per_page'] ?? 20);

        $role = (string) ($filters['role'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $search = (string) ($filters['search'] ?? '');

        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'users',
            'is_admin' => true,
            'show_user_menu' => true,
            'collapse_id' => 'usersMainNavbar',
        ]);
        $alerts = $this->renderFlashAlerts();

        $rows = '';
        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            $roleLabel = (string) ($user['role'] ?? '');
            $statusLabel = (string) ($user['status'] ?? '');
            $statusClass = match ($statusLabel) {
                'active' => 'success',
                'inactive' => 'secondary',
                'suspended' => 'danger',
                default => 'light text-dark',
            };

            $rows .= '<tr>'
                . '<td>' . $userId . '</td>'
                . '<td>' . htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($user['real_name'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td><span class="badge bg-info">' . htmlspecialchars($roleLabel, ENT_QUOTES) . '</span></td>'
                . '<td><span class="badge bg-' . $statusClass . '">' . htmlspecialchars($statusLabel, ENT_QUOTES) . '</span></td>'
                . '<td>' . htmlspecialchars((string) ($user['last_login_at'] ?? '-'), ENT_QUOTES) . '</td>'
                . '<td class="d-flex gap-2">'
                . '<a class="btn btn-sm btn-outline-primary" href="/users/' . $userId . '">查看</a>'
                . '<a class="btn btn-sm btn-outline-secondary" href="/users/' . $userId . '/edit">编辑</a>'
                . '<form method="POST" action="/users/' . $userId . '" onsubmit="return confirm(\'确认删除该用户？\')">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<input type="hidden" name="_method" value="DELETE">'
                . '<button class="btn btn-sm btn-outline-danger" type="submit">删除</button>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8" class="text-center text-muted py-4">暂无用户数据</td></tr>';
        }

        $baseQuery = http_build_query([
            'role' => $role,
            'status' => $status,
            'search' => $search,
            'per_page' => $perPage,
        ]);

        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>用户管理</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . '</head><body class="bg-light">'
            . $navigation
            . '<div class="container mt-4">'
            . $alerts
            . '<div class="d-flex justify-content-between align-items-center mb-3"><h3 class="mb-0"><i class="bi bi-people-fill me-2"></i>用户管理</h3><a class="btn btn-primary" href="/users/create">创建用户</a></div>'
            . '<form class="row g-2 mb-3" method="GET" action="/users">'
            . '<div class="col-md-3"><input class="form-control" name="search" placeholder="用户名/姓名/邮箱" value="' . htmlspecialchars($search, ENT_QUOTES) . '"></div>'
            . '<div class="col-md-2"><select class="form-select" name="role">'
            . '<option value="">全部角色</option>'
            . '<option value="admin"' . ($role === 'admin' ? ' selected' : '') . '>管理员</option>'
            . '<option value="landlord"' . ($role === 'landlord' ? ' selected' : '') . '>房东</option>'
            . '</select></div>'
            . '<div class="col-md-2"><select class="form-select" name="status">'
            . '<option value="">全部状态</option>'
            . '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>active</option>'
            . '<option value="inactive"' . ($status === 'inactive' ? ' selected' : '') . '>inactive</option>'
            . '<option value="suspended"' . ($status === 'suspended' ? ' selected' : '') . '>suspended</option>'
            . '</select></div>'
            . '<div class="col-md-2"><select class="form-select" name="per_page">'
            . '<option value="10"' . ($perPage === 10 ? ' selected' : '') . '>10</option>'
            . '<option value="20"' . ($perPage === 20 ? ' selected' : '') . '>20</option>'
            . '<option value="50"' . ($perPage === 50 ? ' selected' : '') . '>50</option>'
            . '</select></div>'
            . '<div class="col-md-3 d-grid"><button class="btn btn-outline-primary" type="submit">应用筛选</button></div>'
            . '</form>'
            . '<div class="d-flex justify-content-between align-items-center mb-2"><small class="text-muted">共 ' . $total . ' 条记录</small>'
            . '<nav><ul class="pagination pagination-sm mb-0">'
            . '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="/users?' . $baseQuery . '&page=' . $prevPage . '">上一页</a></li>'
            . '<li class="page-item disabled"><span class="page-link">' . $page . ' / ' . $totalPages . '</span></li>'
            . '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="/users?' . $baseQuery . '&page=' . $nextPage . '">下一页</a></li>'
            . '</ul></nav></div>'
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-hover align-middle"><thead><tr>'
            . '<th>ID</th><th>用户名</th><th>姓名</th><th>邮箱</th><th>角色</th><th>状态</th><th>最近登录</th><th>操作</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>'
            . '</div></body></html>';
    }

    private function userDetailTemplate(array $user): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'users',
            'is_admin' => true,
            'show_user_menu' => true,
            'collapse_id' => 'usersDetailNavbar',
            'users_subject_id' => (int) ($user['id'] ?? 0),
        ]);
        $alerts = $this->renderFlashAlerts();

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>用户详情</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . '</head><body class="bg-light">'
            . $navigation
            . '<div class="container mt-4">'
            . $alerts
            . '<div class="d-flex justify-content-between align-items-center mb-3"><h3 class="mb-0">用户详情</h3><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="/users">返回列表</a><a class="btn btn-primary" href="/users/' . (int) $user['id'] . '/edit">编辑</a><a class="btn btn-outline-warning" href="/users/' . (int) $user['id'] . '/password">修改密码</a></div></div>'
            . '<div class="card"><div class="card-body">'
            . '<p><strong>ID：</strong>' . (int) $user['id'] . '</p>'
            . '<p><strong>用户名：</strong>' . htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES) . '</p>'
            . '<p><strong>姓名：</strong>' . htmlspecialchars((string) ($user['real_name'] ?? ''), ENT_QUOTES) . '</p>'
            . '<p><strong>邮箱：</strong>' . htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES) . '</p>'
            . '<p><strong>电话：</strong>' . htmlspecialchars((string) ($user['phone'] ?? ''), ENT_QUOTES) . '</p>'
            . '<p><strong>角色：</strong>' . htmlspecialchars((string) ($user['role'] ?? ''), ENT_QUOTES) . '</p>'
            . '<p><strong>状态：</strong>' . htmlspecialchars((string) ($user['status'] ?? ''), ENT_QUOTES) . '</p>'
            . '<p><strong>最近登录：</strong>' . htmlspecialchars((string) ($user['last_login_at'] ?? '-'), ENT_QUOTES) . '</p>'
            . '<p><strong>创建时间：</strong>' . htmlspecialchars((string) ($user['created_at'] ?? '-'), ENT_QUOTES) . '</p>'
            . '</div></div></div></body></html>';
    }

    private function userFormTemplate(string $title, string $action, string $method, array $user, bool $showPassword): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'users',
            'is_admin' => true,
            'show_user_menu' => true,
            'collapse_id' => 'usersFormNavbar',
            'users_subject_id' => (int) ($user['id'] ?? 0),
        ]);
        $alerts = $this->renderFlashAlerts();

        $username = (string) old('username', (string) ($user['username'] ?? ''));
        $email = (string) old('email', (string) ($user['email'] ?? ''));
        $realName = (string) old('real_name', (string) ($user['real_name'] ?? ''));
        $phone = (string) old('phone', (string) ($user['phone'] ?? ''));
        $role = (string) old('role', (string) ($user['role'] ?? ''));
        $status = (string) old('status', (string) ($user['status'] ?? 'active'));
        $formErrors = $this->consumeFormErrors();

        $usernameError = $this->fieldError($formErrors, 'username');
        $emailError = $this->fieldError($formErrors, 'email');
        $realNameError = $this->fieldError($formErrors, 'real_name');
        $phoneError = $this->fieldError($formErrors, 'phone');
        $passwordError = $this->fieldError($formErrors, 'password');
        $roleError = $this->fieldError($formErrors, 'role');
        $statusError = $this->fieldError($formErrors, 'status');

        $methodField = $method === 'POST' ? '' : '<input type="hidden" name="_method" value="' . htmlspecialchars($method, ENT_QUOTES) . '">';

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . '</head><body class="bg-light">'
            . $navigation
            . '<div class="container mt-4"><div class="row justify-content-center"><div class="col-lg-7">'
            . $alerts
            . '<div class="card"><div class="card-body">'
            . '<h4 class="mb-3">' . htmlspecialchars($title, ENT_QUOTES) . '</h4>'
            . '<form method="POST" action="' . htmlspecialchars($action, ENT_QUOTES) . '">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . $methodField
            . '<div class="mb-3"><label class="form-label">用户名</label><input class="form-control' . ($usernameError !== '' ? ' is-invalid' : '') . '" name="username" required placeholder="4-20位字母/数字/下划线" title="规则：4-20 位，仅允许字母、数字和下划线" value="' . htmlspecialchars($username, ENT_QUOTES) . '">' . $this->errorFeedbackHtml($usernameError) . '</div>'
            . '<div class="mb-3"><label class="form-label">邮箱</label><input type="email" class="form-control' . ($emailError !== '' ? ' is-invalid' : '') . '" name="email" required placeholder="例如：name@example.com" title="请输入有效邮箱，例如 name@example.com" value="' . htmlspecialchars($email, ENT_QUOTES) . '">' . $this->errorFeedbackHtml($emailError) . '</div>'
            . '<div class="mb-3"><label class="form-label">姓名</label><input class="form-control' . ($realNameError !== '' ? ' is-invalid' : '') . '" name="real_name" required placeholder="请输入真实姓名" title="建议填写真实姓名，便于管理与追踪" value="' . htmlspecialchars($realName, ENT_QUOTES) . '">' . $this->errorFeedbackHtml($realNameError) . '</div>'
            . '<div class="mb-3"><label class="form-label">电话</label><input class="form-control' . ($phoneError !== '' ? ' is-invalid' : '') . '" name="phone" placeholder="例如：13800138000（可选）" title="手机号格式：11 位中国大陆手机号" value="' . htmlspecialchars($phone, ENT_QUOTES) . '">' . $this->errorFeedbackHtml($phoneError) . '</div>'
            . ($showPassword
                ? '<div class="mb-3"><label class="form-label">密码</label><input type="password" class="form-control' . ($passwordError !== '' ? ' is-invalid' : '') . '" name="password" required placeholder="至少8位，含大小写字母/数字/特殊字符" title="密码需至少 8 位，且包含大写字母、小写字母、数字、特殊字符">' . $this->errorFeedbackHtml($passwordError) . '</div>'
                : '<div class="mb-3"><label class="form-label">密码</label><div class="form-control bg-light">请在“修改密码”页面单独更新</div><div class="form-text"><a href="/users/' . (int) ($user['id'] ?? 0) . '/password">前往修改密码</a></div></div>')
            . '<div class="mb-3"><label class="form-label">角色</label><select class="form-select' . ($roleError !== '' ? ' is-invalid' : '') . '" name="role" required>'
            . '<option value="admin"' . ($role === 'admin' ? ' selected' : '') . '>管理员</option>'
            . '<option value="landlord"' . ($role === 'landlord' ? ' selected' : '') . '>房东</option>'
            . '</select>' . $this->errorFeedbackHtml($roleError) . '</div>'
            . '<div class="mb-3"><label class="form-label">状态</label><select class="form-select' . ($statusError !== '' ? ' is-invalid' : '') . '" name="status" required>'
            . '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>active</option>'
            . '<option value="inactive"' . ($status === 'inactive' ? ' selected' : '') . '>inactive</option>'
            . '<option value="suspended"' . ($status === 'suspended' ? ' selected' : '') . '>suspended</option>'
            . '</select>' . $this->errorFeedbackHtml($statusError) . '</div>'
            . '<div class="d-flex gap-2"><button class="btn btn-primary" type="submit">保存</button><a class="btn btn-outline-secondary" href="/users">取消</a></div>'
            . '</form>'
            . '</div></div></div></div></div></body></html>';
    }

    private function passwordFormTemplate(array $user): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $isAdmin = auth()->isAdmin();
        $backHref = $isAdmin ? '/users' : '/profile';
        $navigation = app_unified_navbar([
            'active' => 'users_password',
            'is_admin' => $isAdmin,
            'show_user_menu' => true,
            'collapse_id' => 'usersPasswordNavbar',
            'users_subject_id' => (int) ($user['id'] ?? 0),
        ]);
        $alerts = $this->renderFlashAlerts();
        $errors = $this->consumePasswordFormErrors();

        $currentPasswordError = $this->fieldError($errors, 'current_password');
        $newPasswordError = $this->fieldError($errors, 'new_password');
        $confirmPasswordError = $this->fieldError($errors, 'confirm_password');

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>修改密码</title>'
            . '<link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . '</head><body class="bg-light">'
            . $navigation
            . '<div class="container mt-4"><div class="row justify-content-center"><div class="col-lg-7">'
            . $alerts
            . '<div class="card"><div class="card-body">'
            . '<h4 class="mb-3">修改密码：' . htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES) . '</h4>'
            . '<form method="POST" action="/users/' . (int) ($user['id'] ?? 0) . '/password">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<input type="hidden" name="_method" value="PUT">'
            . '<div class="mb-3"><label class="form-label">旧密码</label><input type="password" class="form-control' . ($currentPasswordError !== '' ? ' is-invalid' : '') . '" name="current_password" placeholder="请输入当前密码" required>' . $this->errorFeedbackHtml($currentPasswordError) . '</div>'
            . '<div class="mb-3"><label class="form-label">新密码</label><input type="password" class="form-control' . ($newPasswordError !== '' ? ' is-invalid' : '') . '" name="new_password" placeholder="至少8位，含大小写字母/数字/特殊字符" required>' . $this->errorFeedbackHtml($newPasswordError) . '</div>'
            . '<div class="mb-3"><label class="form-label">确认新密码</label><input type="password" class="form-control' . ($confirmPasswordError !== '' ? ' is-invalid' : '') . '" name="confirm_password" placeholder="请再次输入新密码" required>' . $this->errorFeedbackHtml($confirmPasswordError) . '</div>'
                . '<div class="d-flex gap-2"><button class="btn btn-primary" type="submit">更新密码</button><a class="btn btn-outline-secondary" href="' . $backHref . '">返回</a></div>'
            . '</form>'
            . '</div></div></div></div></div></body></html>';
    }

    private function consumePasswordFormErrors(): array
    {
        if (!has_flash('password_form_errors')) {
            return [];
        }

        $errors = get_flash('password_form_errors', []);
        return is_array($errors) ? $errors : [];
    }

    private function sanitizeInputForFlash(array $input): array
    {
        unset($input['_token'], $input['password'], $input['current_password'], $input['new_password'], $input['confirm_password']);
        return $input;
    }

    private function consumeFormErrors(): array
    {
        if (!has_flash('form_errors')) {
            return [];
        }

        $errors = get_flash('form_errors', []);
        return is_array($errors) ? $errors : [];
    }

    private function fieldError(array $errors, string $field): string
    {
        $value = $errors[$field] ?? '';
        return is_string($value) ? $value : '';
    }

    private function errorFeedbackHtml(string $message): string
    {
        if ($message === '') {
            return '';
        }

        return '<div class="invalid-feedback d-block">' . htmlspecialchars($message, ENT_QUOTES) . '</div>';
    }

    private function renderFlashAlerts(): string
    {
        $html = '';

        if (has_flash('success')) {
            $message = (string) get_flash('success');
            $html .= '<div class="alert alert-success alert-dismissible fade show" role="alert">'
                . htmlspecialchars($message, ENT_QUOTES)
                . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
                . '</div>';
        }

        if (has_flash('error')) {
            $message = (string) get_flash('error');
            $html .= '<div class="alert alert-danger alert-dismissible fade show" role="alert">'
                . htmlspecialchars($message, ENT_QUOTES)
                . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
                . '</div>';
        }

        return $html;
    }

    private function ensureAdmin(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }

        if (!auth()->isAdmin()) {
            throw HttpException::forbidden('仅管理员可访问用户管理');
        }
    }

    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }

    private function ensureCanManagePassword(int $userId): void
    {
        $this->ensureAuthenticated();

        if (auth()->isAdmin()) {
            return;
        }

        if ((int) auth()->id() !== $userId) {
            throw HttpException::forbidden('仅可修改自己的密码');
        }
    }
}
