<?php
/**
 * 收租管理系统 - 房产管理控制器
 * 
 * 处理房产管理相关请求
 */

namespace App\Controllers;

use App\Core\Response;
use App\Core\HttpException;

class PropertyController
{
    /**
     * 显示房产列表
     * 
     * @return Response 响应对象
     */
    public function index(): Response
    {
        $this->ensureAuthenticated();

        $user = auth()->user();
        $isAdmin = auth()->isAdmin();
        $keyword = trim($_GET['keyword'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $sort = $this->normalizePropertySort((string) ($_GET['sort'] ?? 'created_desc'));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(20, max(5, (int) ($_GET['per_page'] ?? 10)));

        $result = $this->getProperties($user, $isAdmin, $keyword, $status, $sort, $page, $perPage);
        $properties = $result['items'];

        $html = $this->propertyListTemplate(
            $properties,
            $user,
            $isAdmin,
            $keyword,
            $status,
            $result['sort'],
            $result['page'],
            $result['per_page'],
            $result['total']
        );
        return Response::html($html);
    }

    /**
     * 月租金调整页
     */
    public function rentAdjustments(): Response
    {
        $this->ensureAuthenticated();

        if (!auth()->isAdmin() && !auth()->isLandlord()) {
            throw HttpException::forbidden('您没有权限调整月租金');
        }

        $user = auth()->user();
        $isAdmin = auth()->isAdmin();
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $sort = $this->normalizePropertySort((string) ($_GET['sort'] ?? 'name_asc'));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(20, max(5, (int) ($_GET['per_page'] ?? 10)));

        $result = $this->getProperties($user, $isAdmin, $keyword, $status, $sort, $page, $perPage);

        return Response::html($this->rentAdjustmentTemplate(
            $result['items'],
            $isAdmin,
            $keyword,
            $status,
            $result['sort'],
            $result['page'],
            $result['per_page'],
            $result['total']
        ));
    }

    /**
     * 更新单个房产月租金
     */
    public function updateMonthlyRent(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!auth()->isAdmin() && !auth()->isLandlord()) {
            throw HttpException::forbidden('您没有权限调整月租金');
        }

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $property = $this->getPropertyById($id, auth()->user(), auth()->isAdmin());
        if (!$property) {
            throw HttpException::notFound('房产不存在或无权访问');
        }
        $this->assertOwnerOrAdmin($property);

        $monthlyRentRaw = trim((string) ($_POST['monthly_rent'] ?? ''));
        $redirectUrl = '/properties/rent-adjustments';
        $returnQuery = trim((string) ($_POST['return_query'] ?? ''));
        if ($returnQuery !== '') {
            $redirectUrl .= '?' . ltrim($returnQuery, '?');
        }

        if ($monthlyRentRaw === '' || !is_numeric($monthlyRentRaw) || (float) $monthlyRentRaw < 0) {
            flash('error', '月租金格式无效，需为不小于 0 的数字');
            return Response::redirect($redirectUrl);
        }

        db()->update('properties', [
            'monthly_rent' => number_format((float) $monthlyRentRaw, 2, '.', ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        flash('success', '月租金更新成功：' . (string) ($property['name'] ?? '房产') . ' -> ¥' . number_format((float) $monthlyRentRaw, 2));
        return Response::redirect($redirectUrl);
    }
    
    /**
     * 显示创建房产表单
     * 
     * @return Response 响应对象
     */
    public function create(): Response
    {
        $this->ensureAuthenticated();

        if (!auth()->isAdmin() && !auth()->isLandlord()) {
            throw HttpException::forbidden('您没有权限创建房产');
        }

        $html = $this->propertyFormTemplate('创建房产', '/properties', 'POST', [
            'property_name' => '',
            'address' => '',
            'city' => '',
            'district' => '',
            'property_type' => 'apartment',
            'total_area' => '0.00',
            'total_rooms' => 1,
            'available_rooms' => 1,
            'monthly_rent' => '0.00',
            'property_status' => 'vacant',
            'description' => ''
        ]);
        return Response::html($html);
    }

    /**
     * 创建房产
     *
     * @return Response
     */
    public function store(): Response
    {
        $this->ensureAuthenticated();

        if (!auth()->isAdmin() && !auth()->isLandlord()) {
            throw HttpException::forbidden('您没有权限创建房产');
        }

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        try {
            $result = $this->validatePropertyInput($_POST);
            if (!empty($result['errors'])) {
                session()->flashInput($_POST);
                flash('form_errors', $result['errors']);
                flash('error', '请检查输入项后重试');
                return Response::redirect('/properties/create');
            }

            $data = $result['data'];
            $data['owner_id'] = auth()->id();
            $data['property_code'] = $this->generatePropertyCode();
            $propertyId = (int) db()->insert('properties', $data);

            $submitAction = (string) ($_POST['submit_action'] ?? 'continue');
            if ($submitAction === 'view') {
                flash('success', '房产创建成功');
                return Response::redirect('/properties/' . $propertyId);
            }

            flash('success', '房产创建成功，可继续录入下一套房产');
            return Response::redirect('/properties/create');
        } catch (\Throwable $e) {
            session()->flashInput($_POST);
            flash('error', '创建失败: ' . $e->getMessage());
            return Response::redirect('/properties/create');
        }
    }
    
    /**
     * 显示房产详情
     * 
     * @param int $id 房产ID
     * @return Response 响应对象
     */
    public function show(int $id): Response
    {
        $this->ensureAuthenticated();

        $user = auth()->user();
        $isAdmin = auth()->isAdmin();

        $property = $this->getPropertyById($id, $user, $isAdmin);

        if (!$property) {
            throw HttpException::notFound('房产不存在');
        }

        $sort = $this->normalizePropertySort((string) ($_GET['sort'] ?? 'created_desc'));
        $adjacent = $this->getAdjacentPropertyIds($id, $user, $isAdmin, $sort);
        $property['prev_id'] = $adjacent['prev_id'];
        $property['next_id'] = $adjacent['next_id'];
        $property['sort'] = $sort;

        $html = $this->propertyDetailTemplate($property, $isAdmin);
        return Response::html($html);
    }

    /**
     * 显示编辑房产表单
     *
     * @param int $id
     * @return Response
     */
    public function edit(int $id): Response
    {
        $this->ensureAuthenticated();

        $property = $this->getPropertyById($id, auth()->user(), auth()->isAdmin());
        if (!$property) {
            throw HttpException::notFound('房产不存在或无权访问');
        }

        $this->assertOwnerOrAdmin($property);

        $html = $this->propertyFormTemplate('编辑房产', '/properties/' . $id, 'PUT', $property);
        return Response::html($html);
    }

    /**
     * 更新房产
     *
     * @param int $id
     * @return Response
     */
    public function update(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $property = $this->getPropertyById($id, auth()->user(), auth()->isAdmin());
        if (!$property) {
            throw HttpException::notFound('房产不存在或无权访问');
        }
        $this->assertOwnerOrAdmin($property);

        try {
            $result = $this->validatePropertyInput($_POST, false);
            if (!empty($result['errors'])) {
                session()->flashInput($_POST);
                flash('form_errors', $result['errors']);
                flash('error', '请检查输入项后重试');
                return Response::redirect('/properties/' . $id . '/edit');
            }

            $data = $result['data'];
            db()->update('properties', $data, ['id' => $id]);
            flash('success', '房产更新成功');
            return Response::redirect('/properties/' . $id);
        } catch (\Throwable $e) {
            session()->flashInput($_POST);
            flash('error', '更新失败: ' . $e->getMessage());
            return Response::redirect('/properties/' . $id . '/edit');
        }
    }

    /**
     * 删除房产
     *
     * @param int $id
     * @return Response
     */
    public function destroy(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $property = $this->getPropertyById($id, auth()->user(), auth()->isAdmin());
        if (!$property) {
            throw HttpException::notFound('房产不存在或无权访问');
        }
        $this->assertOwnerOrAdmin($property);

        try {
            db()->delete('properties', ['id' => $id]);
            flash('success', '房产删除成功');
            return Response::redirect('/properties');
        } catch (\Throwable $e) {
            flash('error', '删除失败: ' . $e->getMessage());
            return Response::redirect('/properties/' . $id);
        }
    }
    
    /**
     * 获取房产列表
     * 
     * @param array $user 用户数据
     * @param bool $isAdmin 是否是管理员
     * @return array 房产列表
     */
    private function getProperties(
        array $user,
        bool $isAdmin,
        string $keyword = '',
        string $status = '',
        string $sort = 'created_desc',
        int $page = 1,
        int $perPage = 10
    ): array
    {
        $baseSql = "
            SELECT
                p.id,
                p.owner_id,
                p.property_name,
                p.address,
                p.city,
                p.district,
                p.property_type,
                p.total_area,
                p.total_rooms,
                p.available_rooms,
                p.monthly_rent,
                p.property_status,
                p.description,
                u.real_name AS owner_name
            FROM properties p
            LEFT JOIN users u ON u.id = p.owner_id
            WHERE 1 = 1
        ";

        $countSql = "SELECT COUNT(*) AS total FROM properties p WHERE 1 = 1";

        $params = [];
        $countParams = [];

        $conditions = '';

        if (!$isAdmin) {
            $conditions .= " AND p.owner_id = ?";
            $params[] = (int) ($user['id'] ?? 0);
            $countParams[] = (int) ($user['id'] ?? 0);
        }

        if ($keyword !== '') {
            $conditions .= " AND (p.property_name LIKE ? OR p.address LIKE ? OR p.city LIKE ? OR p.district LIKE ?)";
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $countParams[] = $like;
            $countParams[] = $like;
            $countParams[] = $like;
            $countParams[] = $like;
        }

        if ($status !== '') {
            $conditions .= " AND p.property_status = ?";
            $params[] = $status;
            $countParams[] = $status;
        }

        $totalRow = db()->fetch($countSql . $conditions, $countParams);
        $total = (int) ($totalRow['total'] ?? 0);

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $sql = $baseSql . $conditions . ' ORDER BY ' . $this->propertyOrderBySql($sort) . ' LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;

        $rows = db()->fetchAll($sql, $params);

        $items = array_map(function (array $row): array {
            $totalRooms = (int) $row['total_rooms'];
            $vacantRooms = max(0, (int) $row['available_rooms']);

            return [
                'id' => (int) $row['id'],
                'owner_id' => (int) $row['owner_id'],
                'name' => $row['property_name'],
                'address' => trim(($row['city'] ?? '') . ' ' . ($row['district'] ?? '') . ' ' . ($row['address'] ?? '')),
                'type' => $this->propertyTypeLabel((string) $row['property_type']),
                'total_rooms' => $totalRooms,
                'occupied_rooms' => max(0, $totalRooms - $vacantRooms),
                'vacant_rooms' => $vacantRooms,
                'monthly_rent' => (float) $row['monthly_rent'],
                'owner_name' => $row['owner_name'] ?? '未知',
                'status' => $this->propertyStatusLabel((string) $row['property_status']),
                'property_type' => (string) $row['property_type'],
                'property_status' => (string) $row['property_status'],
                'city' => (string) ($row['city'] ?? ''),
                'district' => (string) ($row['district'] ?? ''),
                'description' => (string) ($row['description'] ?? '')
            ];
        }, $rows);

        return [
            'items' => $items,
            'total' => $total,
            'sort' => $sort,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }
    
    /**
     * 根据ID获取房产信息
     * 
     * @param int $id 房产ID
     * @param array $user 用户数据
     * @param bool $isAdmin 是否是管理员
     * @return array|null 房产信息
     */
    private function getPropertyById(int $id, array $user, bool $isAdmin): ?array
    {
        $sql = "
            SELECT
                p.id,
                p.owner_id,
                p.property_name,
                p.address,
                p.city,
                p.district,
                p.property_type,
                p.total_area,
                p.total_rooms,
                p.available_rooms,
                p.monthly_rent,
                p.property_status,
                p.description,
                u.real_name AS owner_name
            FROM properties p
            LEFT JOIN users u ON u.id = p.owner_id
            WHERE p.id = ?
        ";

        $params = [$id];

        if (!$isAdmin) {
            $sql .= " AND p.owner_id = ?";
            $params[] = (int) ($user['id'] ?? 0);
        }

        $row = db()->fetch($sql, $params);
        if (!$row) {
            return null;
        }

        $totalRooms = (int) $row['total_rooms'];
        $vacantRooms = max(0, (int) $row['available_rooms']);

        return [
            'id' => (int) $row['id'],
            'owner_id' => (int) $row['owner_id'],
            'name' => $row['property_name'],
            'property_name' => $row['property_name'],
            'address' => (string) $row['address'],
            'city' => (string) ($row['city'] ?? ''),
            'district' => (string) ($row['district'] ?? ''),
            'type' => $this->propertyTypeLabel((string) $row['property_type']),
            'property_type' => (string) $row['property_type'],
            'total_area' => (string) ($row['total_area'] ?? '0.00'),
            'total_rooms' => $totalRooms,
            'occupied_rooms' => max(0, $totalRooms - $vacantRooms),
            'vacant_rooms' => $vacantRooms,
            'available_rooms' => $vacantRooms,
            'monthly_rent' => (float) $row['monthly_rent'],
            'owner_name' => $row['owner_name'] ?? '未知',
            'status' => $this->propertyStatusLabel((string) $row['property_status']),
            'property_status' => (string) $row['property_status'],
            'description' => (string) ($row['description'] ?? '')
        ];
    }

    /**
     * 获取当前可见范围内的上一条和下一条房产ID（按当前排序规则）
     *
     * @param int $id 当前房产ID
     * @param array $user 用户数据
     * @param bool $isAdmin 是否管理员
     * @return array{prev_id:int|null,next_id:int|null}
     */
    private function getAdjacentPropertyIds(int $id, array $user, bool $isAdmin, string $sort): array
    {
        $sql = 'SELECT p.id FROM properties p WHERE 1 = 1';
        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        $sql .= ' ORDER BY ' . $this->propertyOrderBySql($sort);
        $rows = db()->fetchAll($sql, $params);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $currentIndex = array_search($id, $ids, true);

        if ($currentIndex === false) {
            return [
                'prev_id' => null,
                'next_id' => null,
            ];
        }

        $prevId = $currentIndex > 0 ? $ids[$currentIndex - 1] : null;
        $nextId = $currentIndex < count($ids) - 1 ? $ids[$currentIndex + 1] : null;

        return [
            'prev_id' => $prevId,
            'next_id' => $nextId,
        ];
    }
    
    /**
     * 房产列表模板
     * 
     * @param array $properties 房产列表
     * @param array $user 用户数据
     * @param bool $isAdmin 是否是管理员
     * @return string HTML内容
     */
    private function propertyListTemplate(
        array $properties,
        array $user,
        bool $isAdmin,
        string $keyword = '',
        string $status = '',
        string $sort = 'created_desc',
        int $page = 1,
        int $perPage = 10,
        int $total = 0
    ): string
    {
        $canCreate = $isAdmin || auth()->isLandlord();
        $lastPage = max(1, (int) ceil(($total > 0 ? $total : 1) / $perPage));

        $pagination = '';
        if ($lastPage > 1) {
            $baseQuery = [
                'keyword' => $keyword,
                'status' => $status,
                'sort' => $sort,
                'per_page' => $perPage,
            ];

            $pagination .= '<nav aria-label="房产分页"><ul class="pagination justify-content-end">';
            for ($i = 1; $i <= $lastPage; $i++) {
                $query = http_build_query(array_merge($baseQuery, ['page' => $i]));
                $active = $i === $page ? ' active' : '';
                $pagination .= '<li class="page-item' . $active . '"><a class="page-link" href="/properties?' . $query . '">' . $i . '</a></li>';
            }
            $pagination .= '</ul></nav>';
        }
        
        // 生成房产表格
        $propertiesTable = '';
        if (empty($properties)) {
            $propertiesTable = '
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                暂无房产信息。' . ($canCreate ? ' <a href="/properties/create" class="alert-link">点击这里创建第一个房产</a>' : '') . '
            </div>';
        } else {
            foreach ($properties as $property) {
                $detailUrl = '/properties/' . $property['id'] . '?' . http_build_query(['sort' => $sort]);
                $propertiesTable .= '
                <tr>
                    <td>' . $property['id'] . '</td>
                    <td>
                        <a href="' . $detailUrl . '" class="text-decoration-none">
                            <strong>' . htmlspecialchars($property['name']) . '</strong>
                        </a>
                        <div class="text-muted small">' . htmlspecialchars($property['address']) . '</div>
                    </td>
                    <td class="align-middle">' . htmlspecialchars($property['type']) . '</td>
                    <td class="align-middle">' . $property['occupied_rooms'] . '/' . $property['total_rooms'] . '</td>
                    <td class="align-middle">¥' . number_format($property['monthly_rent'], 2) . '</td>
                    <td class="align-middle">' . htmlspecialchars($property['owner_name']) . '</td>
                    <td class="align-middle">
                        <a href="' . $detailUrl . '" class="btn btn-sm btn-outline-primary">
                            查看
                        </a>
                        <a href="/properties/' . $property['id'] . '/edit" class="btn btn-sm btn-outline-secondary">
                            编辑
                        </a>
                        <form action="/properties/' . $property['id'] . '" method="POST" style="display:inline-block" onsubmit="return confirm(\'确认删除该房产吗？\')">
                            <input type="hidden" name="_method" value="DELETE">
                            <input type="hidden" name="_token" value="' . csrf_token() . '">
                            <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                        </form>
                    </td>
                </tr>';
            }
        }
        
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'properties',
            'is_admin' => $isAdmin,
            'show_user_menu' => true,
            'collapse_id' => 'propertyListNavbar',
        ]);
        $alerts = $this->renderFlashAlerts();

        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>房产管理 - 收租管理系统</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.css">
    ' . $navbarStyles . '
</head>
<body>
    ' . $navigation . '

    <div class="container mt-4">
        ' . $alerts . '
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>房产管理</h1>
            <div class="d-flex gap-2">
                <a href="/properties/rent-adjustments" class="btn btn-outline-primary">月租金调整</a>
                ' . ($canCreate ? '<a href="/properties/create" class="btn btn-primary">添加房产</a>' : '') . '
            </div>
        </div>

        <form class="row g-2 mb-3" method="GET" action="/properties">
            <div class="col-md-5">
                <input type="text" name="keyword" class="form-control" placeholder="按名称/地址搜索" value="' . htmlspecialchars($keyword) . '">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">全部状态</option>
                    <option value="vacant"' . ($status === 'vacant' ? ' selected' : '') . '>空置</option>
                    <option value="occupied"' . ($status === 'occupied' ? ' selected' : '') . '>已入住</option>
                    <option value="under_maintenance"' . ($status === 'under_maintenance' ? ' selected' : '') . '>维修中</option>
                    <option value="unavailable"' . ($status === 'unavailable' ? ' selected' : '') . '>不可用</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="sort" class="form-select">
                    <option value="created_desc"' . ($sort === 'created_desc' ? ' selected' : '') . '>最新创建</option>
                    <option value="created_asc"' . ($sort === 'created_asc' ? ' selected' : '') . '>最早创建</option>
                    <option value="name_asc"' . ($sort === 'name_asc' ? ' selected' : '') . '>名称 A-Z</option>
                    <option value="name_desc"' . ($sort === 'name_desc' ? ' selected' : '') . '>名称 Z-A</option>
                    <option value="rent_desc"' . ($sort === 'rent_desc' ? ' selected' : '') . '>租金从高到低</option>
                    <option value="rent_asc"' . ($sort === 'rent_asc' ? ' selected' : '') . '>租金从低到高</option>
                    <option value="area_desc"' . ($sort === 'area_desc' ? ' selected' : '') . '>面积从大到小</option>
                    <option value="area_asc"' . ($sort === 'area_asc' ? ' selected' : '') . '>面积从小到大</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-outline-primary">筛选</button>
            </div>
            <input type="hidden" name="per_page" value="' . $perPage . '">
        </form>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <small class="text-muted">共 ' . $total . ' 条，当前第 ' . $page . '/' . $lastPage . ' 页</small>
            ' . $pagination . '
        </div>

        <div class="card">
            <div class="card-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>房产信息</th>
                            <th class="align-middle">类型</th>
                            <th class="align-middle">房间数</th>
                            <th class="align-middle">月租金</th>
                            <th class="align-middle">所有者</th>
                            <th class="align-middle">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ' . $propertiesTable . '
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
        
        return $html;
    }

    /**
     * 月租金调整页面模板
     */
    private function rentAdjustmentTemplate(
        array $properties,
        bool $isAdmin,
        string $keyword,
        string $status,
        string $sort,
        int $page,
        int $perPage,
        int $total
    ): string
    {
        $lastPage = max(1, (int) ceil(($total > 0 ? $total : 1) / $perPage));
        $baseQuery = [
            'keyword' => $keyword,
            'status' => $status,
            'sort' => $sort,
            'per_page' => $perPage,
            'page' => $page,
        ];
        $returnQuery = http_build_query($baseQuery);

        $pagination = '';
        if ($lastPage > 1) {
            $pagination .= '<nav aria-label="月租金调整分页"><ul class="pagination justify-content-end mb-0">';
            for ($i = 1; $i <= $lastPage; $i++) {
                $query = http_build_query(array_merge($baseQuery, ['page' => $i]));
                $active = $i === $page ? ' active' : '';
                $pagination .= '<li class="page-item' . $active . '"><a class="page-link" href="/properties/rent-adjustments?' . $query . '">' . $i . '</a></li>';
            }
            $pagination .= '</ul></nav>';
        }

        $rows = '';
        foreach ($properties as $property) {
            $rows .= '<tr>'
                . '<td data-label="ID">' . (int) $property['id'] . '</td>'
                . '<td data-label="房产">'
                . '<div class="fw-semibold">' . htmlspecialchars((string) $property['name'], ENT_QUOTES) . '</div>'
                . '<div class="text-muted small">' . htmlspecialchars((string) $property['address'], ENT_QUOTES) . '</div>'
                . '</td>'
                . '<td data-label="当前月租" class="text-end">¥' . number_format((float) $property['monthly_rent'], 2) . '</td>'
                . '<td data-label="新月租">'
                . '<form method="POST" action="/properties/' . (int) $property['id'] . '/rent-adjustment" class="d-flex gap-2 align-items-center justify-content-end flex-wrap">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<input type="hidden" name="return_query" value="' . htmlspecialchars($returnQuery, ENT_QUOTES) . '">'
                . '<input type="number" min="0" step="0.01" class="form-control form-control-sm rent-input" name="monthly_rent" value="' . htmlspecialchars(number_format((float) $property['monthly_rent'], 2, '.', ''), ENT_QUOTES) . '" required>'
                . '<button type="submit" class="btn btn-sm btn-primary">保存</button>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="text-center text-muted py-4">暂无可调整的房产数据</td></tr>';
        }

        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'properties',
            'is_admin' => $isAdmin,
            'show_user_menu' => true,
            'collapse_id' => 'propertyRentAdjustNavbar',
        ]);
        $alerts = $this->renderFlashAlerts();

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>月租金调整</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . '<style>.rent-adjust-note{background:#f8fbff;border:1px solid #dbe7f6;border-radius:.75rem;padding:.75rem .9rem;color:#334155}.rent-input{max-width:11rem}@media (max-width: 767.98px){.rent-adjust-table thead{display:none}.rent-adjust-table tbody,.rent-adjust-table tr,.rent-adjust-table td{display:block;width:100%}.rent-adjust-table tr{border:1px solid #dee2e6;border-radius:.5rem;padding:.55rem .75rem;margin-bottom:.7rem;background:#fff}.rent-adjust-table td{border:0 !important;padding:.2rem 0 .2rem 7.5rem;position:relative}.rent-adjust-table td::before{content:attr(data-label);position:absolute;left:0;top:.2rem;width:7rem;color:#6c757d;font-weight:600;font-size:.85rem}.rent-adjust-table td[colspan]{padding-left:0;text-align:center}.rent-adjust-table td[colspan]::before{display:none}.rent-input{max-width:none}}</style>'
            . '</head><body>'
            . $navigation
            . '<div class="container mt-4">'
            . $alerts
            . '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h3 class="mb-0">月租金调整</h3><a href="/properties" class="btn btn-outline-secondary">返回房产列表</a></div>'
            . '<div class="rent-adjust-note mb-3"><strong>说明：</strong>这里修改的是房产基础月租金，用于后续录入默认值；不会自动改写已生成账单与既有合同月租。</div>'
            . '<form class="row g-2 mb-3" method="GET" action="/properties/rent-adjustments">'
            . '<div class="col-md-5"><input type="text" name="keyword" class="form-control" placeholder="按名称/地址搜索" value="' . htmlspecialchars($keyword, ENT_QUOTES) . '"></div>'
            . '<div class="col-md-3"><select name="status" class="form-select"><option value="">全部状态</option><option value="vacant"' . ($status === 'vacant' ? ' selected' : '') . '>空置</option><option value="occupied"' . ($status === 'occupied' ? ' selected' : '') . '>已入住</option><option value="under_maintenance"' . ($status === 'under_maintenance' ? ' selected' : '') . '>维修中</option><option value="unavailable"' . ($status === 'unavailable' ? ' selected' : '') . '>不可用</option></select></div>'
            . '<div class="col-md-2"><select name="sort" class="form-select"><option value="name_asc"' . ($sort === 'name_asc' ? ' selected' : '') . '>名称 A-Z</option><option value="name_desc"' . ($sort === 'name_desc' ? ' selected' : '') . '>名称 Z-A</option><option value="rent_desc"' . ($sort === 'rent_desc' ? ' selected' : '') . '>租金从高到低</option><option value="rent_asc"' . ($sort === 'rent_asc' ? ' selected' : '') . '>租金从低到高</option><option value="created_desc"' . ($sort === 'created_desc' ? ' selected' : '') . '>最新创建</option><option value="created_asc"' . ($sort === 'created_asc' ? ' selected' : '') . '>最早创建</option></select></div>'
            . '<div class="col-md-2 d-grid"><button type="submit" class="btn btn-outline-primary">筛选</button></div>'
            . '<input type="hidden" name="per_page" value="' . $perPage . '">' 
            . '</form>'
            . '<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"><small class="text-muted">共 ' . $total . ' 条，当前第 ' . $page . '/' . $lastPage . ' 页</small>' . $pagination . '</div>'
            . '<div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-sm table-bordered align-middle rent-adjust-table"><thead><tr><th>ID</th><th>房产</th><th class="text-end">当前月租</th><th class="text-end">新月租</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div>'
            . '</div></body></html>';
    }
    
    /**
     * 简单表单模板
     * 
     * @param string $title 标题
     * @return string HTML内容
     */
    private function propertyFormTemplate(string $title, string $action, string $method, array $property): string
    {
        $isEdit = $method === 'PUT';
        $methodField = $isEdit ? '<input type="hidden" name="_method" value="PUT">' : '';
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'properties',
            'is_admin' => auth()->isAdmin(),
            'show_user_menu' => true,
            'collapse_id' => 'propertyFormNavbar',
        ]);
        $alerts = $this->renderFlashAlerts();

        $propertyName = (string) old('property_name', (string) ($property['property_name'] ?? $property['name'] ?? ''));
        $address = (string) old('address', (string) ($property['address'] ?? ''));
        $city = (string) old('city', (string) ($property['city'] ?? ''));
        $district = (string) old('district', (string) ($property['district'] ?? ''));
        $propertyType = (string) old('property_type', (string) ($property['property_type'] ?? 'apartment'));
        $totalArea = (string) old('total_area', (string) ($property['total_area'] ?? '0.00'));
        $totalRooms = (int) old('total_rooms', (int) ($property['total_rooms'] ?? 1));
        $availableRooms = (int) old('available_rooms', (int) ($property['available_rooms'] ?? $property['vacant_rooms'] ?? 1));
        $monthlyRent = (string) old('monthly_rent', (string) ($property['monthly_rent'] ?? '0.00'));
        $propertyStatus = (string) old('property_status', (string) ($property['property_status'] ?? 'vacant'));
        $description = (string) old('description', (string) ($property['description'] ?? ''));
        $formErrors = $this->consumeFormErrors();
        $propertyNameError = $this->fieldError($formErrors, 'property_name');
        $addressError = $this->fieldError($formErrors, 'address');
        $cityError = $this->fieldError($formErrors, 'city');
        $propertyTypeError = $this->fieldError($formErrors, 'property_type');
        $totalAreaError = $this->fieldError($formErrors, 'total_area');
        $totalRoomsError = $this->fieldError($formErrors, 'total_rooms');
        $availableRoomsError = $this->fieldError($formErrors, 'available_rooms');
        $monthlyRentError = $this->fieldError($formErrors, 'monthly_rent');
        $propertyStatusError = $this->fieldError($formErrors, 'property_status');

        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.css">
    ' . $navbarStyles . '
</head>
<body>
    ' . $navigation . '

    <div class="container mt-4">
        ' . $alerts . '
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4>' . $title . '</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="' . htmlspecialchars($action) . '">
                            ' . $methodField . '
                            <input type="hidden" name="_token" value="' . csrf_token() . '">
                            <div class="mb-3">
                                <label class="form-label">房产名称</label>
                                <input type="text" name="property_name" class="form-control' . ($propertyNameError !== '' ? ' is-invalid' : '') . '" value="' . htmlspecialchars($propertyName) . '" placeholder="例如：绿景花园 2 栋" title="请填写便于识别的房产名称" required>
                                ' . $this->errorFeedbackHtml($propertyNameError) . '
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">详细地址</label>
                                <textarea name="address" class="form-control' . ($addressError !== '' ? ' is-invalid' : '') . '" rows="2" placeholder="例如：世纪大道 100 号 2 单元 301" title="请填写可定位的详细地址" required>' . htmlspecialchars($address) . '</textarea>
                                ' . $this->errorFeedbackHtml($addressError) . '
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">城市</label>
                                    <input type="text" name="city" class="form-control' . ($cityError !== '' ? ' is-invalid' : '') . '" value="' . htmlspecialchars($city) . '" placeholder="例如：上海" title="城市不能为空" required>
                                    ' . $this->errorFeedbackHtml($cityError) . '
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">区域</label>
                                    <input type="text" name="district" class="form-control" value="' . htmlspecialchars($district) . '" placeholder="例如：浦东新区（可选）">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">房产类型</label>
                                <select class="form-select' . ($propertyTypeError !== '' ? ' is-invalid' : '') . '" name="property_type" required>
                                    <option value="apartment"' . ($propertyType === 'apartment' ? ' selected' : '') . '>住宅公寓</option>
                                    <option value="house"' . ($propertyType === 'house' ? ' selected' : '') . '>独栋住宅</option>
                                    <option value="commercial"' . ($propertyType === 'commercial' ? ' selected' : '') . '>商业</option>
                                    <option value="office"' . ($propertyType === 'office' ? ' selected' : '') . '>办公</option>
                                    <option value="other"' . ($propertyType === 'other' ? ' selected' : '') . '>其他</option>
                                </select>
                                ' . $this->errorFeedbackHtml($propertyTypeError) . '
                            </div>

                            <div class="mb-3">
                                <label class="form-label">总面积(平方米)</label>
                                <input type="number" step="0.01" min="0.01" name="total_area" class="form-control' . ($totalAreaError !== '' ? ' is-invalid' : '') . '" value="' . htmlspecialchars($totalArea) . '" placeholder="例如：89.50" title="总面积需大于 0" required>
                                ' . $this->errorFeedbackHtml($totalAreaError) . '
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">总房间数</label>
                                    <input type="number" min="1" name="total_rooms" class="form-control' . ($totalRoomsError !== '' ? ' is-invalid' : '') . '" value="' . $totalRooms . '" placeholder="例如：3" title="总房间数需大于 0" required>
                                    ' . $this->errorFeedbackHtml($totalRoomsError) . '
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">可出租房间数</label>
                                    <input type="number" min="0" name="available_rooms" class="form-control' . ($availableRoomsError !== '' ? ' is-invalid' : '') . '" value="' . $availableRooms . '" placeholder="例如：2" title="可出租房间数需在 0 到总房间数之间" required>
                                    ' . $this->errorFeedbackHtml($availableRoomsError) . '
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">月租金</label>
                                    <input type="number" step="0.01" min="0" name="monthly_rent" class="form-control' . ($monthlyRentError !== '' ? ' is-invalid' : '') . '" value="' . htmlspecialchars($monthlyRent) . '" placeholder="例如：4500.00" title="月租金不能小于 0" required>
                                    ' . $this->errorFeedbackHtml($monthlyRentError) . '
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">状态</label>
                                <select class="form-select' . ($propertyStatusError !== '' ? ' is-invalid' : '') . '" name="property_status" required>
                                    <option value="vacant"' . ($propertyStatus === 'vacant' ? ' selected' : '') . '>空置</option>
                                    <option value="occupied"' . ($propertyStatus === 'occupied' ? ' selected' : '') . '>已入住</option>
                                    <option value="under_maintenance"' . ($propertyStatus === 'under_maintenance' ? ' selected' : '') . '>维修中</option>
                                    <option value="unavailable"' . ($propertyStatus === 'unavailable' ? ' selected' : '') . '>不可用</option>
                                </select>
                                ' . $this->errorFeedbackHtml($propertyStatusError) . '
                            </div>

                            <div class="mb-3">
                                <label class="form-label">描述</label>
                                <textarea name="description" class="form-control" rows="3">' . htmlspecialchars($description) . '</textarea>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="/properties" class="btn btn-secondary">取消</a>
                                <div class="d-flex gap-2">
                                    ' . ($isEdit
                                        ? '<button type="submit" class="btn btn-primary">保存</button>'
                                        : '<button type="submit" name="submit_action" value="continue" class="btn btn-primary">保存并继续</button><button type="submit" name="submit_action" value="view" class="btn btn-outline-primary">保存并查看</button>') . '
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * 简单详情模板
     * 
     * @param array $property 房产数据
     * @return string HTML内容
     */
    private function propertyDetailTemplate(array $property, bool $isAdmin): string
    {
        $canManage = $isAdmin || ((int) ($property['owner_id'] ?? 0) === (int) auth()->id());
        $sort = $this->normalizePropertySort((string) ($property['sort'] ?? 'created_desc'));
        $sortQuery = '?' . http_build_query(['sort' => $sort]);
        $prevId = isset($property['prev_id']) ? (int) $property['prev_id'] : 0;
        $nextId = isset($property['next_id']) ? (int) $property['next_id'] : 0;
        $prevButton = $prevId > 0
            ? '<a href="/properties/' . $prevId . $sortQuery . '" class="btn btn-outline-secondary">上一条</a>'
            : '<button type="button" class="btn btn-outline-secondary" disabled>上一条</button>';
        $nextButton = $nextId > 0
            ? '<a href="/properties/' . $nextId . $sortQuery . '" class="btn btn-outline-secondary">下一条</a>'
            : '<button type="button" class="btn btn-outline-secondary" disabled>下一条</button>';
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'properties',
            'is_admin' => $isAdmin,
            'show_user_menu' => true,
            'collapse_id' => 'propertyDetailNavbar',
        ]);
        $alerts = $this->renderFlashAlerts();

        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($property['name']) . '</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.css">
    ' . $navbarStyles . '
</head>
<body>
    ' . $navigation . '

    <div class="container mt-4">
        ' . $alerts . '
        <div class="card">
            <div class="card-header">
                <h4>' . htmlspecialchars($property['name']) . '</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>地址：</strong>' . htmlspecialchars($property['address']) . '</p>
                        <p><strong>类型：</strong>' . htmlspecialchars($property['type']) . '</p>
                        <p><strong>总面积：</strong>' . number_format((float) ($property['total_area'] ?? 0), 2) . ' 平方米</p>
                        <p><strong>房间数：</strong>' . $property['occupied_rooms'] . '/' . $property['total_rooms'] . '</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>月租金：</strong>¥' . number_format((float) ($property['monthly_rent'] ?? 0), 2) . '</p>
                        <p><strong>所有者：</strong>' . htmlspecialchars($property['owner_name']) . '</p>
                        <p><strong>状态：</strong>' . htmlspecialchars($property['status']) . '</p>
                    </div>
                </div>

                <div class="mt-3">
                    <p class="mb-1"><strong>房屋描述：</strong></p>
                    <p class="text-muted mb-0">' . nl2br(htmlspecialchars(trim((string) ($property['description'] ?? '')) !== '' ? (string) $property['description'] : '暂无描述信息', ENT_QUOTES)) . '</p>
                </div>
                
                <div class="mt-4">
                    <div class="d-flex flex-wrap gap-2">
                        ' . $prevButton . '
                        ' . $nextButton . '
                        <a href="/properties' . $sortQuery . '" class="btn btn-secondary">返回列表</a>
                        ' . ($canManage ? '<a href="/properties/' . $property['id'] . '/edit" class="btn btn-primary">编辑</a>' : '') . '
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }

    private function renderFlashAlerts(): string
    {
        $html = '';

        if (has_flash('success')) {
            $message = (string) get_flash('success');
            $html .= '<div class="alert alert-success" role="alert">' . htmlspecialchars($message, ENT_QUOTES) . '</div>';
        }

        if (has_flash('error')) {
            $message = (string) get_flash('error');
            $html .= '<div class="alert alert-danger" role="alert">' . htmlspecialchars($message, ENT_QUOTES) . '</div>';
        }

        return $html;
    }

    /**
     * 校验房产输入
     *
     * @param array $input
     * @param bool $creating
     * @return array
     */
    private function validatePropertyInput(array $input, bool $creating = true): array
    {
        $propertyName = trim((string) ($input['property_name'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $district = trim((string) ($input['district'] ?? ''));
        $propertyType = trim((string) ($input['property_type'] ?? 'apartment'));
        $totalArea = (float) ($input['total_area'] ?? 0);
        $totalRooms = (int) ($input['total_rooms'] ?? 0);
        $availableRooms = (int) ($input['available_rooms'] ?? -1);
        $monthlyRent = (float) ($input['monthly_rent'] ?? -1);
        $propertyStatus = trim((string) ($input['property_status'] ?? 'vacant'));
        $description = trim((string) ($input['description'] ?? ''));
        $errors = [];

        if ($propertyName === '') {
            $errors['property_name'] = '房产名称不能为空';
        }

        if ($address === '') {
            $errors['address'] = '详细地址不能为空';
        }

        if ($city === '') {
            $errors['city'] = '城市不能为空';
        }

        $allowedTypes = ['apartment', 'house', 'commercial', 'office', 'other'];
        if (!in_array($propertyType, $allowedTypes, true)) {
            $errors['property_type'] = '无效的房产类型';
        }

        $allowedStatus = ['vacant', 'occupied', 'under_maintenance', 'unavailable'];
        if (!in_array($propertyStatus, $allowedStatus, true)) {
            $errors['property_status'] = '无效的房产状态';
        }

        if ($totalRooms <= 0) {
            $errors['total_rooms'] = '总房间数必须大于 0';
        }

        if ($totalArea <= 0) {
            $errors['total_area'] = '总面积必须大于 0';
        }

        if ($availableRooms < 0 || $availableRooms > $totalRooms) {
            $errors['available_rooms'] = '可出租房间数需在 0 到总房间数之间';
        }

        if ($monthlyRent < 0) {
            $errors['monthly_rent'] = '租金不能小于 0';
        }

        $data = [
            'property_name' => $propertyName,
            'address' => $address,
            'city' => $city,
            'district' => $district,
            'property_type' => $propertyType,
            'total_area' => number_format($totalArea, 2, '.', ''),
            'total_rooms' => $totalRooms,
            'available_rooms' => $availableRooms,
            'monthly_rent' => number_format($monthlyRent, 2, '.', ''),
            'property_status' => $propertyStatus,
            'description' => $description,
            'updated_at' => date('Y-m-d H:i:s')
        ] + ($creating ? ['created_at' => date('Y-m-d H:i:s')] : []);

        return [
            'data' => $data,
            'errors' => $errors,
        ];
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

    /**
     * 房产类型中文标签
     */
    private function propertyTypeLabel(string $type): string
    {
        $map = [
            'apartment' => '住宅公寓',
            'house' => '独栋住宅',
            'commercial' => '商业',
            'office' => '办公',
            'other' => '其他'
        ];

        return $map[$type] ?? $type;
    }

    /**
     * 房产状态中文标签
     */
    private function propertyStatusLabel(string $status): string
    {
        $map = [
            'vacant' => '空置',
            'occupied' => '已入住',
            'under_maintenance' => '维修中',
            'unavailable' => '不可用'
        ];

        return $map[$status] ?? $status;
    }

    /**
     * 校验登录
     */
    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }

    /**
     * 校验所有者或管理员权限
     */
    private function assertOwnerOrAdmin(array $property): void
    {
        if (!auth()->isAdmin() && (int) ($property['owner_id'] ?? 0) !== (int) auth()->id()) {
            throw HttpException::forbidden('您没有权限操作该房产');
        }
    }

    /**
     * 生成房产编号
     */
    private function generatePropertyCode(): string
    {
        return 'PROP' . date('YmdHis') . random_int(10, 99);
    }

    /**
     * 标准化房产排序参数
     */
    private function normalizePropertySort(string $sort): string
    {
        $allowed = ['created_desc', 'created_asc', 'name_asc', 'name_desc', 'rent_desc', 'rent_asc', 'area_desc', 'area_asc'];
        return in_array($sort, $allowed, true) ? $sort : 'created_desc';
    }

    /**
     * 生成房产列表排序SQL片段
     */
    private function propertyOrderBySql(string $sort): string
    {
        $normalizedSort = $this->normalizePropertySort($sort);
        $map = [
            'created_desc' => 'p.created_at DESC, p.id DESC',
            'created_asc' => 'p.created_at ASC, p.id ASC',
            'name_asc' => 'p.property_name ASC, p.id ASC',
            'name_desc' => 'p.property_name DESC, p.id DESC',
            'rent_desc' => 'p.monthly_rent DESC, p.id DESC',
            'rent_asc' => 'p.monthly_rent ASC, p.id ASC',
            'area_desc' => 'p.total_area DESC, p.id DESC',
            'area_asc' => 'p.total_area ASC, p.id ASC',
        ];

        return $map[$normalizedSort] ?? $map['created_desc'];
    }
}