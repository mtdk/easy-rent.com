<?php
/**
 * 收租管理系统 - 合同管理控制器
 * 
 * 处理合同管理相关请求
 */

namespace App\Controllers;

use App\Core\Response;
use App\Core\HttpException;

class ContractController
{
    public function index(): Response
    {
        $this->ensureAuthenticated();

        $isAdmin = auth()->isAdmin();
        $keyword = trim($_GET['keyword'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $contracts = $this->getContracts(auth()->user(), $isAdmin, $keyword, $status);
        return Response::html($this->contractListTemplate($contracts, $isAdmin, $keyword, $status));
    }

    /**
     * 到期提醒列表（Day 5 雏形）
     *
     * @return Response
     */
    public function expiring(): Response
    {
        $this->ensureAuthenticated();

        $days = max(1, min(90, (int) ($_GET['days'] ?? 30)));
        $isAdmin = auth()->isAdmin();
        $onlyUnreminded = (string) ($_GET['only_unreminded'] ?? '') === '1';

        $contracts = $this->getExpiringContracts(auth()->user(), $isAdmin, $days, $onlyUnreminded);
        $messages = $this->buildRenewalReminderMessages($contracts);

        return Response::html($this->expiringContractsTemplate($contracts, $messages, $days, $onlyUnreminded, $isAdmin));
    }

    /**
     * 标记已提醒
     */
    public function remind(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }
        $this->assertContractManagePermission($contract);

        $daysLeft = max(0, (int) floor((strtotime((string) $contract['end_date']) - strtotime(date('Y-m-d'))) / 86400));
        $priority = $daysLeft <= 7 ? 'high' : 'normal';
        $targetUserId = (int) ($contract['owner_id'] ?? auth()->id());

        try {
            db()->insert('notifications', [
                'user_id' => $targetUserId,
                'type' => 'reminder',
                'title' => '合同续约提醒',
                'content' => '合同 ' . (string) $contract['contract_number'] . '（租客：' . (string) $contract['tenant_name'] . '）将在 ' . $daysLeft . ' 天后到期，请尽快跟进续约。',
                'related_type' => 'contract',
                'related_id' => (int) $contract['id'],
                'priority' => $priority,
                'is_read' => 0,
                'action_url' => '/contracts/' . (int) $contract['id'],
                'action_text' => '查看合同',
                'expires_at' => date('Y-m-d H:i:s', strtotime((string) $contract['end_date'] . ' +1 day')),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return Response::redirect('/contracts/expiring?days=' . max(1, min(90, (int) ($_GET['days'] ?? 30))) . '&only_unreminded=' . (((string) ($_GET['only_unreminded'] ?? '') === '1') ? '1' : '0') . '&remind_status=failed');
        }

        return Response::redirect('/contracts/expiring?days=' . max(1, min(90, (int) ($_GET['days'] ?? 30))) . '&only_unreminded=' . (((string) ($_GET['only_unreminded'] ?? '') === '1') ? '1' : '0') . '&remind_status=success');
    }

    /**
     * 基于当前合同创建续约合同
     */
    public function renew(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }
        $this->assertContractManagePermission($contract);

        if ((string) ($contract['contract_status'] ?? '') === 'terminated') {
            throw HttpException::badRequest('已终止合同不能直接续约');
        }

        $currentStart = strtotime((string) $contract['start_date']);
        $currentEnd = strtotime((string) $contract['end_date']);
        if ($currentStart === false || $currentEnd === false || $currentEnd < $currentStart) {
            throw HttpException::badRequest('原合同日期无效，无法续约');
        }

        $durationDays = max(30, (int) floor(($currentEnd - $currentStart) / 86400) + 1);
        $newStart = date('Y-m-d', strtotime((string) $contract['end_date'] . ' +1 day'));
        $newEnd = date('Y-m-d', strtotime($newStart . ' +' . ($durationDays - 1) . ' days'));
        $now = date('Y-m-d H:i:s');

        try {
            $newContractId = db()->insert('contracts', [
                'property_id' => (int) $contract['property_id'],
                'contract_number' => $this->generateContractNumber(),
                'tenant_name' => (string) $contract['tenant_name'],
                'tenant_phone' => (string) ($contract['tenant_phone'] ?? ''),
                'tenant_id_card' => $contract['tenant_id_card'] ?? null,
                'tenant_email' => $contract['tenant_email'] ?? null,
                'start_date' => $newStart,
                'end_date' => $newEnd,
                'rent_amount' => number_format((float) $contract['rent_amount'], 2, '.', ''),
                'deposit_amount' => number_format((float) $contract['deposit_amount'], 2, '.', ''),
                'payment_day' => (int) $contract['payment_day'],
                'payment_method' => (string) $contract['payment_method'],
                'contract_status' => 'pending',
                'special_terms' => $contract['special_terms'] ?? null,
                'created_by' => (int) auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            return Response::redirect('/contracts/' . (int) $contract['id'] . '?renew_status=failed');
        }

        return Response::redirect('/contracts/' . $newContractId . '?renewed_from=' . (int) $contract['id']);
    }
    
    /**
     * 显示创建合同表单
     *
     * @return Response 响应对象
     */
    public function create(): Response
    {
        $this->ensureAuthenticated();
        $this->assertCreatorPermission();

        $properties = $this->getPropertyOptions(auth()->user(), auth()->isAdmin());
        $tenants = db()->fetchAll('SELECT id, name, phone FROM tenants WHERE status = ? ORDER BY name', ['在住']);

        return Response::html($this->contractFormTemplate('创建合同', '/contracts', 'POST', [
            'property_id' => '',
            'tenant_name' => '',
            'tenant_phone' => '',
            'start_date' => '',
            'end_date' => '',
            'rent_amount' => '0.00',
            'deposit_amount' => '0.00',
            'payment_day' => 1,
            'payment_method' => 'bank_transfer',
            'contract_status' => 'pending',
            'special_terms' => ''
        ], $properties, $tenants));
    }
    
    /**
     * 保存新合同
     * 
     * @return Response 响应对象
     */
    public function store(): Response
    {
        $this->ensureAuthenticated();
        $this->assertCreatorPermission();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        try {
            $result = $this->validateContractInput($_POST);
            if (!empty($result['errors'])) {
                session()->flashInput($_POST);
                flash('form_errors', $result['errors']);
                flash('error', '请检查输入项后重试');
                return Response::redirect('/contracts/create');
            }

            $data = $result['data'];
            $this->assertPropertyAccessible((int) $data['property_id']);

            $data['contract_number'] = $this->generateContractNumber();
            $data['created_by'] = (int) auth()->id();
            $id = db()->insert('contracts', $data);
            flash('success', '合同创建成功');
            return Response::redirect('/contracts/' . $id);
        } catch (\Throwable $e) {
            session()->flashInput($_POST);
            flash('error', '创建失败: ' . $e->getMessage());
            return Response::redirect('/contracts/create');
        }
    }
    
    /**
     * 显示合同详情
     * 
     * @param int $id 合同ID
     * @return Response 响应对象
     */
    public function show(int $id): Response
    {
        $this->ensureAuthenticated();

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }

        $meters = $this->getContractMeters((int) $contract['id']);

        return Response::html($this->contractDetailTemplate($contract, $meters));
    }

    /**
     * 添加合同表计
     */
    public function addMeter(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }
        $this->assertContractManagePermission($contract);

        $meterType = trim((string) ($_POST['meter_type'] ?? ''));
        $meterCode = trim((string) ($_POST['meter_code'] ?? ''));
        $meterName = trim((string) ($_POST['meter_name'] ?? ''));
        $defaultUnitPriceRaw = trim((string) ($_POST['default_unit_price'] ?? '0'));
        $initialReadingRaw = trim((string) ($_POST['initial_reading'] ?? '0'));

        if (!$this->isValidMeterTypeKey($meterType)) {
            flash('error', '表计类型无效，请先在计量类型表中配置后再使用');
            return Response::redirect('/contracts/' . $id);
        }

        if ($meterCode === '') {
            flash('error', '表计编号不能为空');
            return Response::redirect('/contracts/' . $id);
        }

        if (!is_numeric($defaultUnitPriceRaw) || (float) $defaultUnitPriceRaw < 0) {
            flash('error', '默认单价格式无效');
            return Response::redirect('/contracts/' . $id);
        }

        if (!is_numeric($initialReadingRaw) || (float) $initialReadingRaw < 0) {
            flash('error', '初始读数格式无效');
            return Response::redirect('/contracts/' . $id);
        }

        $exists = db()->fetch(
            'SELECT id FROM contract_meters WHERE contract_id = ? AND meter_code = ? LIMIT 1',
            [$id, $meterCode]
        );
        if ($exists) {
            flash('error', '该合同下已存在相同表计编号');
            return Response::redirect('/contracts/' . $id);
        }

        $sortOrder = (int) db()->fetchColumn(
            'SELECT COALESCE(MAX(sort_order), 0) FROM contract_meters WHERE contract_id = ?',
            [$id]
        ) + 10;

        db()->insert('contract_meters', [
            'contract_id' => $id,
            'meter_type' => $meterType,
            'meter_code' => $meterCode,
            'meter_name' => $meterName !== '' ? $meterName : null,
            'default_unit_price' => number_format((float) $defaultUnitPriceRaw, 4, '.', ''),
            'initial_reading' => number_format((float) $initialReadingRaw, 2, '.', ''),
            'is_active' => 1,
            'sort_order' => $sortOrder,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        flash('success', '表计添加成功');
        return Response::redirect('/contracts/' . $id);
    }

    /**
     * 更新合同表计基础信息（含初始读数）
     */
    public function updateMeter(int $id, int $meterId): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }
        $this->assertContractManagePermission($contract);

        $meter = db()->fetch(
            'SELECT id, contract_id FROM contract_meters WHERE id = ? LIMIT 1',
            [$meterId]
        );
        if (!$meter || (int) ($meter['contract_id'] ?? 0) !== $id) {
            flash('error', '表计不存在或不属于当前合同');
            return Response::redirect('/contracts/' . $id);
        }

        $meterType = trim((string) ($_POST['meter_type'] ?? ''));
        $meterCode = trim((string) ($_POST['meter_code'] ?? ''));
        $meterName = trim((string) ($_POST['meter_name'] ?? ''));
        $defaultUnitPriceRaw = trim((string) ($_POST['default_unit_price'] ?? '0'));
        $initialReadingRaw = trim((string) ($_POST['initial_reading'] ?? '0'));

        if (!$this->isValidMeterTypeKey($meterType)) {
            flash('error', '表计类型无效，请先在计量类型表中配置后再使用');
            return Response::redirect('/contracts/' . $id);
        }

        if ($meterCode === '') {
            flash('error', '表计编号不能为空');
            return Response::redirect('/contracts/' . $id);
        }

        if (!is_numeric($defaultUnitPriceRaw) || (float) $defaultUnitPriceRaw < 0) {
            flash('error', '默认单价格式无效');
            return Response::redirect('/contracts/' . $id);
        }

        if (!is_numeric($initialReadingRaw) || (float) $initialReadingRaw < 0) {
            flash('error', '初始读数格式无效');
            return Response::redirect('/contracts/' . $id);
        }

        $exists = db()->fetch(
            'SELECT id FROM contract_meters WHERE contract_id = ? AND meter_code = ? AND id <> ? LIMIT 1',
            [$id, $meterCode, $meterId]
        );
        if ($exists) {
            flash('error', '该合同下已存在相同表计编号');
            return Response::redirect('/contracts/' . $id);
        }

        db()->update('contract_meters', [
            'meter_type' => $meterType,
            'meter_code' => $meterCode,
            'meter_name' => $meterName !== '' ? $meterName : null,
            'default_unit_price' => number_format((float) $defaultUnitPriceRaw, 4, '.', ''),
            'initial_reading' => number_format((float) $initialReadingRaw, 2, '.', ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $meterId]);

        flash('success', '表计信息已更新');
        return Response::redirect('/contracts/' . $id);
    }

    /**
     * 停用合同表计
     */
    public function deactivateMeter(int $id, int $meterId): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }
        $this->assertContractManagePermission($contract);

        $meter = db()->fetch('SELECT id, contract_id, is_active FROM contract_meters WHERE id = ? LIMIT 1', [$meterId]);
        if (!$meter || (int) ($meter['contract_id'] ?? 0) !== $id) {
            flash('error', '表计不存在或不属于当前合同');
            return Response::redirect('/contracts/' . $id);
        }

        if ((int) ($meter['is_active'] ?? 0) === 0) {
            flash('success', '该表计已停用');
            return Response::redirect('/contracts/' . $id);
        }

        db()->update('contract_meters', [
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $meterId]);

        flash('success', '表计已停用');
        return Response::redirect('/contracts/' . $id);
    }
    
    /**
     * 显示编辑合同表单
     *
     * @param int $id 合同ID
     * @return Response 响应对象
     */
    public function edit(int $id): Response
    {
        $this->ensureAuthenticated();

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }

        $this->assertContractManagePermission($contract);
        $properties = $this->getPropertyOptions(auth()->user(), auth()->isAdmin());
        $tenants = db()->fetchAll('SELECT id, name, phone FROM tenants WHERE status = ? ORDER BY name', ['在住']);

        return Response::html($this->contractFormTemplate('编辑合同', '/contracts/' . $id, 'PUT', $contract, $properties, $tenants));
    }
    
    /**
     * 更新合同
     * 
     * @param int $id 合同ID
     * @return Response 响应对象
     */
    public function update(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }
        $this->assertContractManagePermission($contract);

        try {
            $result = $this->validateContractInput($_POST, false);
            if (!empty($result['errors'])) {
                session()->flashInput($_POST);
                flash('form_errors', $result['errors']);
                flash('error', '请检查输入项后重试');
                return Response::redirect('/contracts/' . $id . '/edit');
            }

            $data = $result['data'];
            $this->assertPropertyAccessible((int) $data['property_id']);
            db()->update('contracts', $data, ['id' => $id]);
            flash('success', '合同更新成功');
            return Response::redirect('/contracts/' . $id);
        } catch (\Throwable $e) {
            session()->flashInput($_POST);
            flash('error', '更新失败: ' . $e->getMessage());
            return Response::redirect('/contracts/' . $id . '/edit');
        }
    }
    
    /**
     * 删除合同
     * 
     * @param int $id 合同ID
     * @return Response 响应对象
     */
    public function destroy(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $contract = $this->getContractById($id, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }
        $this->assertContractManagePermission($contract);

        try {
            db()->delete('contracts', ['id' => $id]);
            flash('success', '合同删除成功');
            return Response::redirect('/contracts');
        } catch (\Throwable $e) {
            flash('error', '删除失败: ' . $e->getMessage());
            return Response::redirect('/contracts/' . $id);
        }
    }

    private function getContracts(array $user, bool $isAdmin, string $keyword = '', string $status = ''): array
    {
        $sql = "
            SELECT
                c.id,
                c.property_id,
                c.contract_number,
                c.tenant_name,
                c.tenant_phone,
                c.start_date,
                c.end_date,
                c.rent_amount,
                c.deposit_amount,
                c.payment_day,
                c.payment_method,
                c.contract_status,
                c.special_terms,
                c.created_by,
                p.property_name,
                p.owner_id,
                u.real_name AS owner_name
            FROM contracts c
            JOIN properties p ON p.id = c.property_id
            LEFT JOIN users u ON u.id = p.owner_id
            WHERE 1 = 1
        ";

        $params = [];

        if (!$isAdmin) {
            $sql .= " AND p.owner_id = ?";
            $params[] = (int) ($user['id'] ?? 0);
        }

        if ($keyword !== '') {
            $sql .= " AND (c.contract_number LIKE ? OR c.tenant_name LIKE ? OR p.property_name LIKE ?)";
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($status !== '') {
            $sql .= " AND c.contract_status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY c.created_at DESC";

        return db()->fetchAll($sql, $params);
    }

    private function getContractById(int $id, array $user, bool $isAdmin): ?array
    {
        $sql = "
            SELECT
                c.id,
                c.property_id,
                c.contract_number,
                c.tenant_name,
                c.tenant_phone,
                c.tenant_id_card,
                c.tenant_email,
                c.start_date,
                c.end_date,
                c.rent_amount,
                c.deposit_amount,
                c.payment_day,
                c.payment_method,
                c.contract_status,
                c.special_terms,
                c.termination_reason,
                c.created_by,
                p.property_name,
                p.owner_id,
                u.real_name AS owner_name
            FROM contracts c
            JOIN properties p ON p.id = c.property_id
            LEFT JOIN users u ON u.id = p.owner_id
            WHERE c.id = ?
        ";

        $params = [$id];
        if (!$isAdmin) {
            $sql .= " AND p.owner_id = ?";
            $params[] = (int) ($user['id'] ?? 0);
        }

        return db()->fetch($sql, $params);
    }

    private function getPropertyOptions(array $user, bool $isAdmin): array
    {
        $sql = "SELECT id, property_name, monthly_rent FROM properties WHERE 1 = 1";
        $params = [];

        if (!$isAdmin) {
            $sql .= " AND owner_id = ?";
            $params[] = (int) ($user['id'] ?? 0);
        }

        $sql .= " ORDER BY property_name ASC";
        return db()->fetchAll($sql, $params);
    }

    /**
     * 获取即将到期合同
     */
    private function getExpiringContracts(array $user, bool $isAdmin, int $days, bool $onlyUnreminded = false): array
    {
        $sql = "
            SELECT
                c.id,
                c.contract_number,
                c.tenant_name,
                c.end_date,
                c.contract_status,
                c.rent_amount,
                p.property_name,
                p.owner_id,
                                DATEDIFF(c.end_date, CURDATE()) AS days_left,
                                (
                                        SELECT COUNT(1)
                                        FROM notifications n
                                        WHERE n.related_type = 'contract'
                                            AND n.related_id = c.id
                                            AND n.type = 'reminder'
                                ) AS reminder_count,
                                (
                                        SELECT MAX(n.created_at)
                                        FROM notifications n
                                        WHERE n.related_type = 'contract'
                                            AND n.related_id = c.id
                                            AND n.type = 'reminder'
                                ) AS last_reminded_at
            FROM contracts c
            JOIN properties p ON p.id = c.property_id
            WHERE c.contract_status IN ('active', 'pending')
              AND DATEDIFF(c.end_date, CURDATE()) BETWEEN 0 AND ?
        ";

        $params = [$days];

        if (!$isAdmin) {
            $sql .= " AND p.owner_id = ?";
            $params[] = (int) ($user['id'] ?? 0);
        }

        if ($onlyUnreminded) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM notifications n2 WHERE n2.related_type = 'contract' AND n2.related_id = c.id AND n2.type = 'reminder')";
        }

        $sql .= " ORDER BY c.end_date ASC";

        return db()->fetchAll($sql, $params);
    }

    /**
     * 生成续约提醒文案
     */
    private function buildRenewalReminderMessages(array $contracts): array
    {
        $messages = [];

        foreach ($contracts as $contract) {
            $daysLeft = (int) ($contract['days_left'] ?? 0);
            $prefix = $daysLeft <= 7 ? '紧急' : '提醒';
            $messages[] = $prefix . '：合同 ' . (string) $contract['contract_number']
                . '（租客 ' . (string) $contract['tenant_name'] . '）将在 ' . $daysLeft . ' 天后到期。';
        }

        return $messages;
    }

    private function validateContractInput(array $input, bool $creating = true): array
    {
        $propertyId = (int) ($input['property_id'] ?? 0);
        $tenantName = trim((string) ($input['tenant_name'] ?? ''));
        $tenantPhone = trim((string) ($input['tenant_phone'] ?? ''));
        $tenantEmail = trim((string) ($input['tenant_email'] ?? ''));
        $tenantIdCard = trim((string) ($input['tenant_id_card'] ?? ''));
        $startDate = trim((string) ($input['start_date'] ?? ''));
        $endDate = trim((string) ($input['end_date'] ?? ''));
        $rentAmount = (float) ($input['rent_amount'] ?? -1);
        $depositAmount = (float) ($input['deposit_amount'] ?? -1);
        $paymentDay = (int) ($input['payment_day'] ?? 0);
        $paymentMethod = trim((string) ($input['payment_method'] ?? 'bank_transfer'));
        $contractStatus = trim((string) ($input['contract_status'] ?? 'pending'));
        $specialTerms = trim((string) ($input['special_terms'] ?? ''));
        $errors = [];

        if ($propertyId <= 0) {
            $errors['property_id'] = '请选择房产';
        }

        if ($tenantName === '') {
            $errors['tenant_name'] = '租客姓名不能为空';
        }

        if ($startDate === '') {
            $errors['start_date'] = '开始日期不能为空';
        }

        if ($endDate === '') {
            $errors['end_date'] = '结束日期不能为空';
        }

        if ($startDate !== '' && strtotime($startDate) === false) {
            $errors['start_date'] = '开始日期格式无效';
        }

        if ($endDate !== '' && strtotime($endDate) === false) {
            $errors['end_date'] = '结束日期格式无效';
        }

        if (($errors['start_date'] ?? '') === '' && ($errors['end_date'] ?? '') === '' && strtotime($endDate) < strtotime($startDate)) {
            $errors['end_date'] = '结束日期不能早于开始日期';
        }

        if ($rentAmount < 0 || $depositAmount < 0) {
            if ($rentAmount < 0) {
                $errors['rent_amount'] = '月租不能小于 0';
            }
            if ($depositAmount < 0) {
                $errors['deposit_amount'] = '押金不能小于 0';
            }
        }

        if ($paymentDay < 1 || $paymentDay > 31) {
            $errors['payment_day'] = '付款日需在 1-31 之间';
        }

        if ($tenantEmail !== '' && !filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['tenant_email'] = '请输入有效邮箱，例如 tenant@example.com';
        }

        if ($tenantPhone !== '' && !preg_match('/^1[3-9][0-9]{9}$/', $tenantPhone)) {
            $errors['tenant_phone'] = '手机号需为 11 位中国大陆手机号，例如 13800138000';
        }

        $paymentMethods = ['cash', 'bank_transfer', 'alipay', 'wechat_pay', 'other'];
        if (!in_array($paymentMethod, $paymentMethods, true)) {
            $errors['payment_method'] = '支付方式无效';
        }

        $statuses = ['active', 'expired', 'terminated', 'pending'];
        if (!in_array($contractStatus, $statuses, true)) {
            $errors['contract_status'] = '合同状态无效';
        }

        $data = [
            'property_id' => $propertyId,
            'tenant_name' => $tenantName,
            'tenant_phone' => $tenantPhone,
            'tenant_email' => $tenantEmail !== '' ? $tenantEmail : null,
            'tenant_id_card' => $tenantIdCard !== '' ? $tenantIdCard : null,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rent_amount' => number_format($rentAmount, 2, '.', ''),
            'deposit_amount' => number_format($depositAmount, 2, '.', ''),
            'payment_day' => $paymentDay,
            'payment_method' => $paymentMethod,
            'contract_status' => $contractStatus,
            'special_terms' => $specialTerms !== '' ? $specialTerms : null,
            'updated_at' => date('Y-m-d H:i:s')
        ] + ($creating ? ['created_at' => date('Y-m-d H:i:s')] : []);

        return [
            'data' => $data,
            'errors' => $errors,
        ];
    }

    private function assertPropertyAccessible(int $propertyId): void
    {
        $sql = 'SELECT id, owner_id FROM properties WHERE id = ?';
        $row = db()->fetch($sql, [$propertyId]);

        if (!$row) {
            throw HttpException::notFound('房产不存在');
        }

        if (!auth()->isAdmin() && (int) $row['owner_id'] !== (int) auth()->id()) {
            throw HttpException::forbidden('您无权使用该房产创建/编辑合同');
        }
    }

    private function assertContractManagePermission(array $contract): void
    {
        if (!auth()->isAdmin() && (int) ($contract['owner_id'] ?? 0) !== (int) auth()->id()) {
            throw HttpException::forbidden('您无权操作该合同');
        }
    }

    private function assertCreatorPermission(): void
    {
        if (!auth()->isAdmin() && !auth()->isLandlord()) {
            throw HttpException::forbidden('您没有权限创建合同');
        }
    }

    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }

    private function generateContractNumber(): string
    {
        return 'CT' . date('YmdHis') . random_int(10, 99);
    }

    private function statusBadge(string $status): string
    {
        $map = [
            'active' => ['生效中', 'success'],
            'pending' => ['待生效', 'secondary'],
            'expired' => ['已到期', 'warning'],
            'terminated' => ['已终止', 'danger'],
        ];

        [$label, $color] = $map[$status] ?? [$status, 'secondary'];
        return '<span class="badge bg-' . $color . '">' . $label . '</span>';
    }

    private function getContractMeters(int $contractId): array
    {
        return db()->fetchAll(
            'SELECT id, meter_type, meter_code, meter_name, default_unit_price, initial_reading, is_active, sort_order
             FROM contract_meters
             WHERE contract_id = ?
             ORDER BY sort_order ASC, id ASC',
            [$contractId]
        );
    }

    private function contractListTemplate(array $contracts, bool $isAdmin, string $keyword, string $status): string
    {
        $title = $isAdmin ? '所有合同管理' : '我的合同管理';
        $alerts = $this->renderFlashAlerts();
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'contracts',
            'is_admin' => $isAdmin,
            'show_user_menu' => true,
            'collapse_id' => 'contractListNavbar',
        ]);

        $rows = '';
        foreach ($contracts as $contract) {
            $rows .= '<tr>'
                . '<td data-label="ID">' . (int) $contract['id'] . '</td>'
                . '<td data-label="合同编号"><a href="/contracts/' . (int) $contract['id'] . '">' . htmlspecialchars((string) $contract['contract_number']) . '</a></td>'
                . '<td data-label="房产">' . htmlspecialchars((string) $contract['property_name']) . '</td>'
                . '<td data-label="租客">' . htmlspecialchars((string) $contract['tenant_name']) . '</td>'
                . '<td data-label="开始">' . htmlspecialchars((string) $contract['start_date']) . '</td>'
                . '<td data-label="结束">' . htmlspecialchars((string) $contract['end_date']) . '</td>'
                . '<td data-label="月租">¥' . number_format((float) $contract['rent_amount'], 2) . '</td>'
                . '<td data-label="状态">' . $this->statusBadge((string) $contract['contract_status']) . '</td>'
                . '<td data-label="操作"><a class="btn btn-sm btn-outline-primary" href="/contracts/' . (int) $contract['id'] . '">查看</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="9" class="text-center text-muted">暂无合同数据</td></tr>';
        }

        $presetPending = http_build_query(['status' => 'pending']);
        $presetActive = http_build_query(['status' => 'active']);
        $presetExpired = http_build_query(['status' => 'expired']);

        $pageStyles = '<style>.contract-list-page .preset-panel{margin-top:.5rem;}.contract-list-page .preset-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem;}.contract-list-page .preset-card{display:block;text-decoration:none;border:1px solid #dbe7f6;border-radius:.75rem;background:linear-gradient(120deg,#f8fbff,#f2f8ff);padding:.55rem .65rem;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;}.contract-list-page .preset-card:hover{transform:translateY(-1px);border-color:#bcd0ee;box-shadow:0 .3rem .75rem rgba(15,23,42,.09);}.contract-list-page .preset-card .preset-title{font-size:.82rem;font-weight:600;color:#0f172a;}.contract-list-page .preset-card .preset-desc{font-size:.72rem;color:#64748b;margin-top:.14rem;}@media (max-width: 767.98px){.contracts-toolbar{width:100%;display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem;}.contracts-toolbar .btn{flex:1 1 calc(50% - .5rem);} .contract-list-page .preset-grid{grid-template-columns:1fr;} .contracts-table thead{display:none;} .contracts-table tbody,.contracts-table tr,.contracts-table td{display:block;width:100%;} .contracts-table tr{border:1px solid #dee2e6;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.75rem;background:#fff;} .contracts-table td{border:0 !important;padding:.2rem 0 .2rem 8rem;position:relative;white-space:normal;} .contracts-table td::before{content:attr(data-label);position:absolute;left:0;top:.2rem;width:7.5rem;color:#6c757d;font-weight:600;font-size:.85rem;} .contracts-table td[colspan]{padding-left:0;text-align:center;} .contracts-table td[colspan]::before{display:none;}}</style>';

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>'
            . $title
            . '</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . $pageStyles
            . '</head><body>'
            . $navigation
            . '<div class="container mt-4 contract-list-page">'
            . $alerts
            . '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap"><h3>' . $title . '</h3><div class="d-flex gap-2 contracts-toolbar"><a href="/contracts/expiring" class="btn btn-outline-warning">到期提醒</a><a href="/contracts/create" class="btn btn-primary">创建合同</a></div></div>'
            . '<form id="contractsFilterForm" class="row g-2 mb-3" method="GET" action="/contracts">'
            . '<div class="col-md-6"><input class="form-control" type="text" name="keyword" value="' . htmlspecialchars($keyword) . '" placeholder="合同编号/租客/房产"></div>'
            . '<div class="col-md-3"><select class="form-select" name="status">'
            . '<option value="">全部状态</option>'
            . '<option value="pending"' . ($status === 'pending' ? ' selected' : '') . '>待生效</option>'
            . '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>生效中</option>'
            . '<option value="expired"' . ($status === 'expired' ? ' selected' : '') . '>已到期</option>'
            . '<option value="terminated"' . ($status === 'terminated' ? ' selected' : '') . '>已终止</option>'
            . '</select></div>'
            . '<div class="col-md-3 d-grid"><button class="btn btn-outline-primary" type="submit">筛选</button></div>'
            . '<div class="col-12 preset-panel mt-1">'
            . '<div class="text-muted small mb-2">常用预设：</div>'
            . '<div class="preset-grid">'
            . '<a class="preset-card" href="/contracts?' . htmlspecialchars($presetPending, ENT_QUOTES) . '"><div class="preset-title">待生效</div><div class="preset-desc">关注即将开始执行的合同</div></a>'
            . '<a class="preset-card" href="/contracts?' . htmlspecialchars($presetActive, ENT_QUOTES) . '"><div class="preset-title">生效中</div><div class="preset-desc">快速查看当前执行中的合同</div></a>'
            . '<a class="preset-card" href="/contracts?' . htmlspecialchars($presetExpired, ENT_QUOTES) . '"><div class="preset-title">已到期</div><div class="preset-desc">集中处理到期续约或终止</div></a>'
            . '<a class="preset-card" data-filter-reset="contracts" href="/contracts"><div class="preset-title">重置筛选</div><div class="preset-desc">恢复默认合同列表视图</div></a>'
            . '</div>'
            . '</div>'
            . '</form>'
                . '<div class="card"><div class="card-body table-responsive"><table class="table table-striped contracts-table"><thead><tr>'
            . '<th>ID</th><th>合同编号</th><th>房产</th><th>租客</th><th>开始</th><th>结束</th><th>月租</th><th>状态</th><th>操作</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>'
            . '<script>(function(){var storageKey="easyrent:filters:contracts:index";var form=document.getElementById("contractsFilterForm");if(!form||!window.localStorage){return;}var fields=["keyword","status"];var hasQueryValues=false;var params=new URLSearchParams(window.location.search);for(var i=0;i<fields.length;i++){if(params.has(fields[i])&&params.get(fields[i])!==""){hasQueryValues=true;break;}}if(!hasQueryValues){try{var raw=localStorage.getItem(storageKey);if(raw){var saved=JSON.parse(raw);for(var j=0;j<fields.length;j++){var name=fields[j];var input=form.elements[name];if(!input){continue;}var currentValue=typeof input.value==="string"?input.value:"";if(currentValue!==""){continue;}if(Object.prototype.hasOwnProperty.call(saved,name)&&typeof saved[name]==="string"){input.value=saved[name];}}}}catch(e){}}form.addEventListener("submit",function(){var payload={};for(var k=0;k<fields.length;k++){var key=fields[k];var el=form.elements[key];if(!el){continue;}var value=typeof el.value==="string"?el.value.trim():"";if(value!==""){payload[key]=value;}}if(Object.keys(payload).length>0){localStorage.setItem(storageKey,JSON.stringify(payload));}else{localStorage.removeItem(storageKey);}});var resetLink=form.querySelector("a[data-filter-reset=\"contracts\"]");if(resetLink){resetLink.addEventListener("click",function(){localStorage.removeItem(storageKey);});}})();</script>'
            . '</div></body></html>';
    }

    /**
     * 到期提醒页面
     */
    private function expiringContractsTemplate(array $contracts, array $messages, int $days, bool $onlyUnreminded = false, bool $isAdmin = false): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'contracts',
            'is_admin' => $isAdmin,
            'show_user_menu' => true,
            'collapse_id' => 'contractExpiringNavbar',
        ]);

        $actionStatus = (string) ($_GET['remind_status'] ?? '');
        $actionAlert = '';
        if ($actionStatus === 'success') {
            $actionAlert = '<div class="alert alert-success">续约提醒已记录。</div>';
        } elseif ($actionStatus === 'failed') {
            $actionAlert = '<div class="alert alert-danger">提醒记录失败，请稍后重试。</div>';
        }

        $rows = '';
        foreach ($contracts as $contract) {
            $reminderCount = (int) ($contract['reminder_count'] ?? 0);
            $lastRemindedAt = (string) ($contract['last_reminded_at'] ?? '');
            $reminderLabel = $reminderCount > 0
                ? '<span class="badge bg-success-subtle text-success-emphasis border">已提醒 ' . $reminderCount . ' 次</span>'
                : '<span class="badge bg-secondary-subtle text-secondary-emphasis border">未提醒</span>';

            if ($lastRemindedAt !== '') {
                $reminderLabel .= '<div class="small text-muted mt-1">最近：' . htmlspecialchars($lastRemindedAt) . '</div>';
            }

            $actionButtons = '<div class="d-flex gap-2">'
                . '<div class="expiring-actions d-flex gap-2">'
                . '<form method="POST" action="/contracts/' . (int) $contract['id'] . '/remind?days=' . $days . '&only_unreminded=' . ($onlyUnreminded ? '1' : '0') . '">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-warning">标记已提醒</button>'
                . '</form>'
                . '<form method="POST" action="/contracts/' . (int) $contract['id'] . '/renew" onsubmit="return confirm(\'确认基于当前合同创建续约合同吗？\')">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-primary">一键续约</button>'
                . '</form>'
                . '</div>'
                . '</div>';

            $rows .= '<tr>'
                . '<td data-label="ID">' . (int) $contract['id'] . '</td>'
                . '<td data-label="合同编号"><a href="/contracts/' . (int) $contract['id'] . '">' . htmlspecialchars((string) $contract['contract_number']) . '</a></td>'
                . '<td data-label="房产">' . htmlspecialchars((string) $contract['property_name']) . '</td>'
                . '<td data-label="租客">' . htmlspecialchars((string) $contract['tenant_name']) . '</td>'
                . '<td data-label="到期日">' . htmlspecialchars((string) $contract['end_date']) . '</td>'
                . '<td data-label="剩余天数">' . (int) ($contract['days_left'] ?? 0) . '</td>'
                . '<td data-label="状态">' . $this->statusBadge((string) $contract['contract_status']) . '</td>'
                . '<td data-label="提醒状态">' . $reminderLabel . '</td>'
                . '<td data-label="操作">' . $actionButtons . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="9" class="text-center text-muted">未来 ' . $days . ' 天暂无到期合同</td></tr>';
        }

        $messageHtml = '';
        if (!empty($messages)) {
            $messageHtml .= '<div class="alert alert-warning"><strong>续约提醒建议：</strong><ul class="mb-0 mt-2">';
            foreach ($messages as $message) {
                $messageHtml .= '<li>' . htmlspecialchars($message) . '</li>';
            }
            $messageHtml .= '</ul></div>';
        }

        $preset7Days = http_build_query(['days' => 7]);
        $preset15Days = http_build_query(['days' => 15]);
        $preset30Days = http_build_query(['days' => 30]);
        $presetUnreminded = http_build_query(['days' => $days, 'only_unreminded' => 1]);

        $mobileStyles = '<style>.contract-expiring-page .page-head{background:linear-gradient(120deg,#eef6ff,#e6f7f2);color:#0f172a;border:1px solid #d8e7f8;border-radius:1rem;padding:1rem 1.1rem;margin-bottom:.9rem;} .contract-expiring-page .page-head .subtitle{color:#334155;margin:.25rem 0 0;font-size:.9rem;} .contract-expiring-page .filter-card{border:1px solid #e2e8f0;box-shadow:0 .35rem .9rem rgba(15,23,42,.05);} .contract-expiring-page .filter-label{font-size:.72rem;font-weight:700;color:#64748b;letter-spacing:.04em;text-transform:uppercase;margin-bottom:.35rem;display:inline-block;} .contract-expiring-page .preset-panel{margin-top:.65rem;} .contract-expiring-page .preset-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem;} .contract-expiring-page .preset-card{display:block;text-decoration:none;border:1px solid #dbe7f6;border-radius:.75rem;background:linear-gradient(120deg,#f8fbff,#f2f8ff);padding:.55rem .65rem;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;} .contract-expiring-page .preset-card:hover{transform:translateY(-1px);border-color:#bcd0ee;box-shadow:0 .3rem .75rem rgba(15,23,42,.09);} .contract-expiring-page .preset-card .preset-title{font-size:.82rem;font-weight:600;color:#0f172a;} .contract-expiring-page .preset-card .preset-desc{font-size:.72rem;color:#64748b;margin-top:.14rem;}@media (max-width: 767.98px){.contract-expiring-page .page-head{padding:.85rem .9rem;} .contract-expiring-page .page-head .subtitle{font-size:.82rem;} .expiring-toolbar{width:100%;display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem;}.expiring-toolbar .btn{flex:1 1 calc(50% - .5rem);} .contract-expiring-page .preset-grid{grid-template-columns:1fr;} .expiring-table thead{display:none;} .expiring-table tbody,.expiring-table tr,.expiring-table td{display:block;width:100%;} .expiring-table tr{border:1px solid #dee2e6;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.75rem;background:#fff;} .expiring-table td{border:0 !important;padding:.2rem 0 .2rem 8rem;position:relative;white-space:normal;} .expiring-table td::before{content:attr(data-label);position:absolute;left:0;top:.2rem;width:7.5rem;color:#6c757d;font-weight:600;font-size:.85rem;} .expiring-table td[colspan]{padding-left:0;text-align:center;} .expiring-table td[colspan]::before{display:none;} .expiring-actions{flex-direction:column;align-items:stretch;width:100%;} .expiring-actions form{width:100%;} .expiring-actions .btn{width:100%;}}</style>';

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>合同到期提醒</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">' . $navbarStyles . $mobileStyles . '</head><body>'
            . $navigation
            . '<div class="container mt-4 contract-expiring-page">'
            . '<div class="page-head">'
            . '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">'
            . '<div><h3 class="mb-0">合同到期提醒</h3><p class="subtitle">集中跟进未来到期合同、提醒进度与续约处理状态。</p></div>'
            . '<div class="expiring-toolbar d-flex gap-2"><a href="/contracts" class="btn btn-outline-secondary">返回合同列表</a></div>'
            . '</div>'
            . '</div>'
            . '<form class="card filter-card mb-3" method="GET" action="/contracts/expiring">'
            . '<div class="card-body"><div class="row g-2 align-items-end">'
            . '<div class="col-md-3"><label class="filter-label" for="days">提醒范围（天）</label><input class="form-control" id="days" type="number" min="1" max="90" name="days" value="' . $days . '"></div>'
            . '<div class="col-md-5"><label class="filter-label d-block">提醒状态</label><div class="form-check pt-2">'
            . '<input class="form-check-input" type="checkbox" id="only_unreminded" name="only_unreminded" value="1"' . ($onlyUnreminded ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="only_unreminded">仅看未提醒</label>'
            . '</div></div>'
            . '<div class="col-md-4 d-grid"><button class="btn btn-outline-primary" type="submit">查询</button></div>'
            . '</div>'
            . '<div class="preset-panel">'
            . '<div class="text-muted small mb-2">常用预设：</div>'
            . '<div class="preset-grid">'
            . '<a class="preset-card" href="/contracts/expiring?' . htmlspecialchars($preset7Days, ENT_QUOTES) . '"><div class="preset-title">7 天内到期</div><div class="preset-desc">优先关注紧急续约合同</div></a>'
            . '<a class="preset-card" href="/contracts/expiring?' . htmlspecialchars($preset15Days, ENT_QUOTES) . '"><div class="preset-title">15 天窗口</div><div class="preset-desc">处理中短期到期提醒</div></a>'
            . '<a class="preset-card" href="/contracts/expiring?' . htmlspecialchars($preset30Days, ENT_QUOTES) . '"><div class="preset-title">30 天全览</div><div class="preset-desc">覆盖标准到期排期视图</div></a>'
            . '<a class="preset-card" href="/contracts/expiring?' . htmlspecialchars($presetUnreminded, ENT_QUOTES) . '"><div class="preset-title">仅看未提醒</div><div class="preset-desc">聚焦仍需跟进的合同</div></a>'
            . '</div>'
            . '</div></div>'
            . '</form>'
            . $actionAlert
            . $messageHtml
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-striped expiring-table"><thead><tr>'
                . '<th>ID</th><th>合同编号</th><th>房产</th><th>租客</th><th>到期日</th><th>剩余天数</th><th>状态</th><th>提醒状态</th><th>操作</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div></div></body></html>';
    }

    private function contractFormTemplate(string $title, string $action, string $method, array $contract, array $properties, array $tenants = []): string
    {
        $methodField = $method === 'PUT' ? '<input type="hidden" name="_method" value="PUT">' : '';
        $alerts = $this->renderFlashAlerts();
        $propertyId = (int) old('property_id', (int) ($contract['property_id'] ?? 0));
        $tenantName = (string) old('tenant_name', (string) ($contract['tenant_name'] ?? ''));
        $tenantPhone = (string) old('tenant_phone', (string) ($contract['tenant_phone'] ?? ''));
        $tenantEmail = (string) old('tenant_email', (string) ($contract['tenant_email'] ?? ''));
        $startDate = (string) old('start_date', (string) ($contract['start_date'] ?? ''));
        $endDate = (string) old('end_date', (string) ($contract['end_date'] ?? ''));
        $rentAmount = (string) old('rent_amount', (string) ($contract['rent_amount'] ?? '0.00'));
        $depositAmount = (string) old('deposit_amount', (string) ($contract['deposit_amount'] ?? '0.00'));
        $paymentDay = (int) old('payment_day', (int) ($contract['payment_day'] ?? 1));
        $paymentMethod = (string) old('payment_method', (string) ($contract['payment_method'] ?? 'bank_transfer'));
        $contractStatus = (string) old('contract_status', (string) ($contract['contract_status'] ?? 'pending'));
        $specialTerms = (string) old('special_terms', (string) ($contract['special_terms'] ?? ''));
        $formErrors = $this->consumeFormErrors();
        $propertyIdError = $this->fieldError($formErrors, 'property_id');
        $tenantNameError = $this->fieldError($formErrors, 'tenant_name');
        $tenantPhoneError = $this->fieldError($formErrors, 'tenant_phone');
        $tenantEmailError = $this->fieldError($formErrors, 'tenant_email');
        $startDateError = $this->fieldError($formErrors, 'start_date');
        $endDateError = $this->fieldError($formErrors, 'end_date');
        $rentAmountError = $this->fieldError($formErrors, 'rent_amount');
        $depositAmountError = $this->fieldError($formErrors, 'deposit_amount');
        $paymentDayError = $this->fieldError($formErrors, 'payment_day');
        $paymentMethodError = $this->fieldError($formErrors, 'payment_method');
        $contractStatusError = $this->fieldError($formErrors, 'contract_status');
        $isCreateForm = $method === 'POST';

        $propertyOptions = '';
        foreach ($properties as $p) {
            $selected = $propertyId === (int) $p['id'] ? ' selected' : '';
            $monthlyRent = number_format((float) ($p['monthly_rent'] ?? 0), 2, '.', '');
            $propertyOptions .= '<option value="' . (int) $p['id'] . '" data-monthly-rent="' . htmlspecialchars($monthlyRent) . '"' . $selected . '>' . htmlspecialchars((string) $p['property_name']) . '</option>';
        }

        $tenantOptions = '<option value="">请选择租客</option>';
        foreach ($tenants as $tenant) {
            $selected = $tenantName === (string) $tenant['name'] ? ' selected' : '';
            $phone = htmlspecialchars((string) ($tenant['phone'] ?? ''));
            $tenantOptions .= '<option value="' . htmlspecialchars((string) $tenant['name']) . '" data-phone="' . $phone . '"' . $selected . '>' . htmlspecialchars((string) $tenant['name']) . '</option>';
        }

        $rentAutofillScript = '';
        if ($isCreateForm) {
            $rentAutofillScript = '<script>(function(){const propertySelect=document.querySelector("select[name=\"property_id\"]");const rentInput=document.querySelector("input[name=\"rent_amount\"]");if(!propertySelect||!rentInput){return;}const applyRent=function(force){const selected=propertySelect.options[propertySelect.selectedIndex];if(!selected){return;}const monthlyRent=selected.getAttribute("data-monthly-rent")||"";if(monthlyRent===""){return;}const current=(rentInput.value||"").trim();const canOverwrite=force||current===""||current==="0"||current==="0.0"||current==="0.00";if(canOverwrite){rentInput.value=monthlyRent;}};applyRent(false);propertySelect.addEventListener("change",function(){applyRent(true);});})();</script>';
        }
        $tenantPhoneAutofillScript = '<script>(function(){const tenantSelect=document.querySelector("select[name=\"tenant_name\"]");const phoneInput=document.querySelector("input[name=\"tenant_phone\"]");if(!tenantSelect||!phoneInput){return;}tenantSelect.addEventListener("change",function(){const selectedOption=this.options[this.selectedIndex];const phone=selectedOption.getAttribute("data-phone")||"";phoneInput.value=phone;});})();</script>';

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>'
            . htmlspecialchars($title)
            . '</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"></head><body><div class="container mt-4">'
            . $alerts
            . '<h3 class="mb-3">' . htmlspecialchars($title) . '</h3>'
            . '<form method="POST" action="' . htmlspecialchars($action) . '">'
            . $methodField
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<div class="row">'
                . '<div class="col-md-6 mb-3"><label class="form-label">房产</label><select class="form-select' . ($propertyIdError !== '' ? ' is-invalid' : '') . '" name="property_id" required>' . $propertyOptions . '</select>' . $this->errorFeedbackHtml($propertyIdError) . '</div>'
                . '<div class="col-md-6 mb-3"><label class="form-label">租客姓名</label><select class="form-select' . ($tenantNameError !== '' ? ' is-invalid' : '') . '" name="tenant_name" required>' . $tenantOptions . '</select>' . $this->errorFeedbackHtml($tenantNameError) . '</div>'
                . '<div class="col-md-6 mb-3"><label class="form-label">租客电话</label><input class="form-control' . ($tenantPhoneError !== '' ? ' is-invalid' : '') . '" name="tenant_phone" placeholder="例如：13800138000" title="手机号格式：11 位中国大陆手机号" value="' . htmlspecialchars($tenantPhone) . '">' . $this->errorFeedbackHtml($tenantPhoneError) . '</div>'
                . '<div class="col-md-6 mb-3"><label class="form-label">租客邮箱</label><input type="email" class="form-control' . ($tenantEmailError !== '' ? ' is-invalid' : '') . '" name="tenant_email" placeholder="例如：tenant@example.com" title="请输入有效邮箱地址" value="' . htmlspecialchars($tenantEmail) . '">' . $this->errorFeedbackHtml($tenantEmailError) . '</div>'
                . '<div class="col-md-6 mb-3"><label class="form-label">开始日期</label><input type="date" class="form-control' . ($startDateError !== '' ? ' is-invalid' : '') . '" name="start_date" value="' . htmlspecialchars($startDate) . '" placeholder="年/月/日" title="请选择日期（年/月/日）" required>' . $this->errorFeedbackHtml($startDateError) . '</div>'
                . '<div class="col-md-6 mb-3"><label class="form-label">结束日期</label><input type="date" class="form-control' . ($endDateError !== '' ? ' is-invalid' : '') . '" name="end_date" value="' . htmlspecialchars($endDate) . '" placeholder="年/月/日" title="请选择日期（年/月/日）" required>' . $this->errorFeedbackHtml($endDateError) . '</div>'
                . '<div class="col-md-4 mb-3"><label class="form-label">月租</label><input type="number" step="0.01" min="0" class="form-control' . ($rentAmountError !== '' ? ' is-invalid' : '') . '" name="rent_amount" placeholder="例如：4200.00" title="月租不能小于 0" value="' . htmlspecialchars($rentAmount) . '" required>' . $this->errorFeedbackHtml($rentAmountError) . '</div>'
                . '<div class="col-md-4 mb-3"><label class="form-label">押金</label><input type="number" step="0.01" min="0" class="form-control' . ($depositAmountError !== '' ? ' is-invalid' : '') . '" name="deposit_amount" placeholder="例如：4200.00" title="押金不能小于 0" value="' . htmlspecialchars($depositAmount) . '" required>' . $this->errorFeedbackHtml($depositAmountError) . '</div>'
                . '<div class="col-md-4 mb-3"><label class="form-label">付款日</label><input type="number" min="1" max="31" class="form-control' . ($paymentDayError !== '' ? ' is-invalid' : '') . '" name="payment_day" placeholder="1-31" title="每月付款日，范围 1-31" value="' . $paymentDay . '" required>' . $this->errorFeedbackHtml($paymentDayError) . '</div>'
                . '<div class="col-md-6 mb-3"><label class="form-label">支付方式</label><select class="form-select' . ($paymentMethodError !== '' ? ' is-invalid' : '') . '" name="payment_method">'
            . '<option value="bank_transfer"' . ($paymentMethod === 'bank_transfer' ? ' selected' : '') . '>银行转账</option>'
            . '<option value="cash"' . ($paymentMethod === 'cash' ? ' selected' : '') . '>现金</option>'
            . '<option value="alipay"' . ($paymentMethod === 'alipay' ? ' selected' : '') . '>支付宝</option>'
            . '<option value="wechat_pay"' . ($paymentMethod === 'wechat_pay' ? ' selected' : '') . '>微信支付</option>'
            . '<option value="other"' . ($paymentMethod === 'other' ? ' selected' : '') . '>其他</option>'
                . '</select>' . $this->errorFeedbackHtml($paymentMethodError) . '</div>'
                . '<div class="col-md-6 mb-3"><label class="form-label">状态</label><select class="form-select' . ($contractStatusError !== '' ? ' is-invalid' : '') . '" name="contract_status">'
            . '<option value="pending"' . ($contractStatus === 'pending' ? ' selected' : '') . '>待生效</option>'
            . '<option value="active"' . ($contractStatus === 'active' ? ' selected' : '') . '>生效中</option>'
            . '<option value="expired"' . ($contractStatus === 'expired' ? ' selected' : '') . '>已到期</option>'
            . '<option value="terminated"' . ($contractStatus === 'terminated' ? ' selected' : '') . '>已终止</option>'
                . '</select>' . $this->errorFeedbackHtml($contractStatusError) . '</div>'
                . '<div class="col-12 mb-3"><label class="form-label">特殊条款</label><textarea class="form-control" rows="3" name="special_terms" placeholder="可填写违约条款、补充约定（可选）">' . htmlspecialchars($specialTerms) . '</textarea></div>'
            . '</div><div class="d-flex gap-2"><a href="/contracts" class="btn btn-secondary">取消</a><button class="btn btn-primary" type="submit">保存</button></div></form>' . $rentAutofillScript . $tenantPhoneAutofillScript . '</div></body></html>';
    }

    private function contractDetailTemplate(array $contract, array $meters = []): string
    {
        $canManage = auth()->isAdmin() || (int) ($contract['owner_id'] ?? 0) === (int) auth()->id();
        $alerts = $this->renderFlashAlerts();
        $renewStatus = (string) ($_GET['renew_status'] ?? '');
        $renewedFrom = (int) ($_GET['renewed_from'] ?? 0);
        $renewAlert = '';
        if ($renewStatus === 'failed') {
            $renewAlert = '<div class="alert alert-danger">续约创建失败，请稍后重试。</div>';
        } elseif ($renewedFrom > 0) {
            $renewAlert = '<div class="alert alert-success">续约合同创建成功，来源合同ID：' . $renewedFrom . '。</div>';
        }

        $meterRows = '';
        foreach ($meters as $meter) {
            $isActive = (int) ($meter['is_active'] ?? 0) === 1;
            $statusBadge = $isActive
                ? '<span class="badge bg-success">启用</span>'
                : '<span class="badge bg-secondary">停用</span>';

            $action = '-';
            if ($canManage && $isActive) {
                $action = '<form action="/contracts/' . (int) $contract['id'] . '/meters/' . (int) $meter['id'] . '/deactivate" method="POST" style="display:inline-block" onsubmit="return confirm(\'确认停用该表计吗？\')">'
                    . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                    . '<button class="btn btn-sm btn-outline-danger" type="submit">停用</button>'
                    . '</form>';
            }

            $meterRows .= '<tr>'
                . '<td>' . htmlspecialchars($this->meterTypeLabel((string) ($meter['meter_type'] ?? '')), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($meter['meter_code'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($meter['meter_name'] ?? '-'), ENT_QUOTES) . '</td>'
                . '<td class="text-end">' . number_format((float) ($meter['default_unit_price'] ?? 0), 4) . '</td>'
                . '<td class="text-end">' . number_format((float) ($meter['initial_reading'] ?? 0), 2) . '</td>'
                . '<td>' . $statusBadge . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }

        if ($meterRows === '') {
            $meterRows = '<tr><td colspan="7" class="text-center text-muted">暂无表计，请先添加表计</td></tr>';
        }

        $meterForm = '';
        $meterEditCards = '';
        if ($canManage) {
            $meterTypeOptions = '';
            foreach ($this->getMeterTypeDefinitions() as $definition) {
                $typeKey = (string) ($definition['type_key'] ?? '');
                if ($typeKey === '') {
                    continue;
                }

                $meterTypeOptions .= '<option value="' . htmlspecialchars($typeKey, ENT_QUOTES) . '">' . htmlspecialchars((string) ($definition['type_name'] ?? $typeKey), ENT_QUOTES) . '</option>';
            }

            if ($meterTypeOptions === '') {
                $meterTypeOptions = '<option value="water">水表</option><option value="electric">电表</option><option value="gas">天然气表</option>';
            }

            $meterForm = '<div class="card mt-3"><div class="card-body">'
                . '<h6 class="mb-3">添加表计</h6>'
                . '<form method="POST" action="/contracts/' . (int) $contract['id'] . '/meters">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<div class="row g-2">'
                . '<div class="col-md-2"><label class="form-label">类型</label><select class="form-select" name="meter_type" required>' . $meterTypeOptions . '</select></div>'
                . '<div class="col-md-3"><label class="form-label">表计编号</label><input class="form-control" name="meter_code" placeholder="如 WATER-2" required></div>'
                . '<div class="col-md-3"><label class="form-label">表计名称</label><input class="form-control" name="meter_name" placeholder="可选"></div>'
                . '<div class="col-md-2"><label class="form-label">默认单价</label><input class="form-control" type="number" step="0.0001" min="0" name="default_unit_price" value="0.0000" required></div>'
                . '<div class="col-md-2"><label class="form-label">初始读数</label><input class="form-control" type="number" step="0.01" min="0" name="initial_reading" value="0.00" required></div>'
                . '<div class="col-12 d-grid"><button class="btn btn-outline-primary" type="submit">添加表计</button></div>'
                . '</div></form></div></div>';

            foreach ($meters as $meter) {
                $editTypeOptions = '';
                foreach ($this->getMeterTypeDefinitions() as $definition) {
                    $typeKey = (string) ($definition['type_key'] ?? '');
                    if ($typeKey === '') {
                        continue;
                    }

                    $selected = $typeKey === (string) ($meter['meter_type'] ?? '') ? ' selected' : '';
                    $editTypeOptions .= '<option value="' . htmlspecialchars($typeKey, ENT_QUOTES) . '"' . $selected . '>'
                        . htmlspecialchars((string) ($definition['type_name'] ?? $typeKey), ENT_QUOTES)
                        . '</option>';
                }

                if ($editTypeOptions === '') {
                    $meterType = (string) ($meter['meter_type'] ?? 'water');
                    $editTypeOptions = '<option value="water"' . ($meterType === 'water' ? ' selected' : '') . '>水表</option>'
                        . '<option value="electric"' . ($meterType === 'electric' ? ' selected' : '') . '>电表</option>'
                        . '<option value="gas"' . ($meterType === 'gas' ? ' selected' : '') . '>天然气表</option>';
                }

                $meterEditCards .= '<div class="card mt-2"><div class="card-body">'
                    . '<div class="d-flex justify-content-between align-items-center mb-2">'
                    . '<h6 class="mb-0">编辑表计：' . htmlspecialchars((string) ($meter['meter_code'] ?? '-'), ENT_QUOTES) . '</h6>'
                    . ((int) ($meter['is_active'] ?? 0) === 1
                        ? '<span class="badge bg-success">启用</span>'
                        : '<span class="badge bg-secondary">停用</span>')
                    . '</div>'
                    . '<form method="POST" action="/contracts/' . (int) $contract['id'] . '/meters/' . (int) $meter['id'] . '">'
                    . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                    . '<div class="row g-2">'
                    . '<div class="col-md-2"><label class="form-label">类型</label><select class="form-select" name="meter_type" required>' . $editTypeOptions . '</select></div>'
                    . '<div class="col-md-3"><label class="form-label">表计编号</label><input class="form-control" name="meter_code" value="' . htmlspecialchars((string) ($meter['meter_code'] ?? ''), ENT_QUOTES) . '" required></div>'
                    . '<div class="col-md-3"><label class="form-label">表计名称</label><input class="form-control" name="meter_name" value="' . htmlspecialchars((string) ($meter['meter_name'] ?? ''), ENT_QUOTES) . '" placeholder="可选"></div>'
                    . '<div class="col-md-2"><label class="form-label">默认单价</label><input class="form-control" type="number" step="0.0001" min="0" name="default_unit_price" value="' . htmlspecialchars(number_format((float) ($meter['default_unit_price'] ?? 0), 4, '.', ''), ENT_QUOTES) . '" required></div>'
                    . '<div class="col-md-2"><label class="form-label">初始读数</label><input class="form-control" type="number" step="0.01" min="0" name="initial_reading" value="' . htmlspecialchars(number_format((float) ($meter['initial_reading'] ?? 0), 2, '.', ''), ENT_QUOTES) . '" required></div>'
                    . '<div class="col-12 d-grid d-md-flex justify-content-md-end"><button class="btn btn-primary" type="submit">保存表计修改</button></div>'
                    . '</div></form></div></div>';
            }
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>合同详情</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"></head><body><div class="container mt-4">'
            . '<h3>合同详情 #' . (int) $contract['id'] . '</h3><div class="card"><div class="card-body">'
            . $alerts
            . $renewAlert
            . '<p><strong>合同编号：</strong>' . htmlspecialchars((string) $contract['contract_number']) . '</p>'
            . '<p><strong>房产：</strong>' . htmlspecialchars((string) $contract['property_name']) . '</p>'
            . '<p><strong>租客：</strong>' . htmlspecialchars((string) $contract['tenant_name']) . '</p>'
            . '<p><strong>起止日期：</strong>' . htmlspecialchars((string) $contract['start_date']) . ' 至 ' . htmlspecialchars((string) $contract['end_date']) . '</p>'
            . '<p><strong>租金/押金：</strong>¥' . number_format((float) $contract['rent_amount'], 2) . ' / ¥' . number_format((float) $contract['deposit_amount'], 2) . '</p>'
            . '<p><strong>付款日：</strong>' . (int) $contract['payment_day'] . ' 号</p>'
            . '<p><strong>状态：</strong>' . $this->statusBadge((string) $contract['contract_status']) . '</p>'
            . '<hr>'
            . '<h5 class="mb-3">表计管理</h5>'
            . '<div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead><tr><th>类型</th><th>表计编号</th><th>表计名称</th><th class="text-end">默认单价</th><th class="text-end">初始读数</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $meterRows . '</tbody></table></div>'
            . $meterForm
            . ($meterEditCards !== '' ? '<div class="mt-3"><h6 class="mb-2">编辑已有表计（可修正初始读数）</h6>' . $meterEditCards . '</div>' : '')
            . '<div class="mt-3"><a class="btn btn-secondary" href="/contracts">返回列表</a>'
            . ($canManage ? ' <a class="btn btn-primary" href="/contracts/' . (int) $contract['id'] . '/edit">编辑</a>
                <form action="/contracts/' . (int) $contract['id'] . '/renew" method="POST" style="display:inline-block" onsubmit="return confirm(\'确认基于本合同创建续约合同吗？\')">
                    <input type="hidden" name="_token" value="' . csrf_token() . '">
                    <button class="btn btn-outline-primary" type="submit">基于本合同续约</button>
                </form>
                <form action="/contracts/' . (int) $contract['id'] . '" method="POST" style="display:inline-block" onsubmit="return confirm(\'确认删除该合同吗？\')">
                    <input type="hidden" name="_method" value="DELETE">
                    <input type="hidden" name="_token" value="' . csrf_token() . '">
                    <button class="btn btn-danger" type="submit">删除</button>
                </form>' : '')
            . '</div></div></div></div></body></html>';
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

    private function getMeterTypeDefinitions(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $fallback = [
            ['type_key' => 'water', 'type_name' => '水表', 'default_code_prefix' => 'WATER', 'sort_order' => 10],
            ['type_key' => 'electric', 'type_name' => '电表', 'default_code_prefix' => 'ELECTRIC', 'sort_order' => 20],
            ['type_key' => 'gas', 'type_name' => '天然气表', 'default_code_prefix' => 'GAS', 'sort_order' => 30],
        ];

        try {
            $rows = db()->fetchAll(
                'SELECT type_key, type_name, default_code_prefix, sort_order
                 FROM meter_types
                 WHERE is_active = 1
                 ORDER BY sort_order ASC, id ASC'
            );
            if (is_array($rows) && $rows !== []) {
                $cache = $rows;
                return $cache;
            }
        } catch (\Throwable $e) {
            // Fallback to built-in defaults when migration is not yet applied.
        }

        $cache = $fallback;
        return $cache;
    }

    private function isValidMeterTypeKey(string $meterType): bool
    {
        if ($meterType === '' || !preg_match('/^[a-z][a-z0-9_]{1,29}$/', $meterType)) {
            return false;
        }

        foreach ($this->getMeterTypeDefinitions() as $definition) {
            if ((string) ($definition['type_key'] ?? '') === $meterType) {
                return true;
            }
        }

        return false;
    }

    private function meterTypeLabel(string $meterType): string
    {
        foreach ($this->getMeterTypeDefinitions() as $definition) {
            if ((string) ($definition['type_key'] ?? '') === $meterType) {
                return (string) ($definition['type_name'] ?? $meterType);
            }
        }

        return $meterType !== '' ? strtoupper($meterType) : '-';
    }
}