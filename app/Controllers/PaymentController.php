<?php
/**
 * 收租管理系统 - 支付管理控制器
 *
 * P1-A: 账单生成、逾期计算、支付记录、收据页面
 */

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;

class PaymentController
{
    public function index(): Response
    {
        $this->ensureAuthenticated();

        $this->refreshOverduePayments();

        $filters = $this->collectPaymentFiltersFromQuery();

        $allPayments = $this->getPayments(auth()->user(), auth()->isAdmin(), $filters);
        $perPage = 6;
        $total = count($allPayments);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $payments = array_slice($allPayments, $offset, $perPage);

        return Response::html($this->paymentListTemplate($payments, $filters, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ]));
    }

    public function export(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectPaymentFiltersFromQuery();
        $meterDetail = (string) ($filters['meter_detail'] ?? '') === '1';

        $payments = $this->getPayments(auth()->user(), auth()->isAdmin(), $filters);

        $lines = [];
        if ($meterDetail) {
            $lines[] = '支付编号,账期,合同编号,租客,房产,应收总额,状态,到期日,支付日期,表计类型,表计编号,表计名称,上月读数,本月读数,当月用量,单价,费用';
            foreach ($payments as $payment) {
                $meterRows = $this->getPaymentMeterDetails((int) ($payment['id'] ?? 0));
                if ($meterRows === []) {
                    $row = [
                        (string) ($payment['payment_number'] ?? ''),
                        (string) ($payment['payment_period'] ?? ''),
                        (string) ($payment['contract_number'] ?? ''),
                        (string) ($payment['tenant_name'] ?? ''),
                        (string) ($payment['property_name'] ?? ''),
                        (string) ($payment['amount_due'] ?? ''),
                        (string) ($payment['payment_status'] ?? ''),
                        (string) ($payment['due_date'] ?? ''),
                        (string) ($payment['paid_date'] ?? ''),
                        '', '', '', '', '', '', '', '',
                    ];
                    $lines[] = implode(',', array_map([$this, 'escapeCsv'], $row));
                    continue;
                }

                foreach ($meterRows as $meterRow) {
                    $row = [
                        (string) ($payment['payment_number'] ?? ''),
                        (string) ($payment['payment_period'] ?? ''),
                        (string) ($payment['contract_number'] ?? ''),
                        (string) ($payment['tenant_name'] ?? ''),
                        (string) ($payment['property_name'] ?? ''),
                        (string) ($payment['amount_due'] ?? ''),
                        (string) ($payment['payment_status'] ?? ''),
                        (string) ($payment['due_date'] ?? ''),
                        (string) ($payment['paid_date'] ?? ''),
                        $this->meterTypeLabel((string) ($meterRow['meter_type'] ?? '')),
                        (string) ($meterRow['meter_code_snapshot'] ?? ''),
                        (string) ($meterRow['meter_name_snapshot'] ?? ''),
                        (string) ($meterRow['previous_reading'] ?? ''),
                        (string) ($meterRow['current_reading'] ?? ''),
                        (string) ($meterRow['usage_amount'] ?? ''),
                        (string) ($meterRow['unit_price'] ?? ''),
                        (string) ($meterRow['line_amount'] ?? ''),
                    ];
                    $lines[] = implode(',', array_map([$this, 'escapeCsv'], $row));
                }
            }
        } else {
            $lines[] = '支付编号,账期,合同编号,租客,房产,固定租金,上月水表,本月水表,当月水量,水费单价,水费,上月电表,本月电表,当月电量,电费单价,电费,应收总额,状态,到期日,支付日期';

            foreach ($payments as $payment) {
                $details = $this->extractBillDetails($payment['notes'] ?? null);
                $formula = is_array($details['formula'] ?? null) ? $details['formula'] : [];

                $row = [
                    (string) ($payment['payment_number'] ?? ''),
                    (string) ($payment['payment_period'] ?? ''),
                    (string) ($payment['contract_number'] ?? ''),
                    (string) ($payment['tenant_name'] ?? ''),
                    (string) ($payment['property_name'] ?? ''),
                    (string) ($formula['rent_amount'] ?? ''),
                    (string) ($formula['water_previous'] ?? ''),
                    (string) ($formula['water_current'] ?? ''),
                    (string) ($formula['water_usage'] ?? ''),
                    (string) ($formula['water_unit_price'] ?? ''),
                    (string) ($formula['water_fee'] ?? ''),
                    (string) ($formula['electric_previous'] ?? ''),
                    (string) ($formula['electric_current'] ?? ''),
                    (string) ($formula['electric_usage'] ?? ''),
                    (string) ($formula['electric_unit_price'] ?? ''),
                    (string) ($formula['electric_fee'] ?? ''),
                    (string) ($payment['amount_due'] ?? ''),
                    (string) ($payment['payment_status'] ?? ''),
                    (string) ($payment['due_date'] ?? ''),
                    (string) ($payment['paid_date'] ?? ''),
                ];

                $lines[] = implode(',', array_map([$this, 'escapeCsv'], $row));
            }
        }

        $period = (string) ($filters['period'] ?? '');
        $periodFrom = (string) ($filters['period_from'] ?? '');
        $periodTo = (string) ($filters['period_to'] ?? '');

        $filenamePeriod = $period;
        if ($filenamePeriod === '' && $periodFrom !== '') {
            $filenamePeriod = $periodFrom;
        }
        if ($filenamePeriod === '' && $periodTo !== '') {
            $filenamePeriod = $periodTo;
        }

        $filename = 'rent-bills-' . ($filenamePeriod !== '' ? $filenamePeriod : date('Y-m')) . ($meterDetail ? '-meter-details' : '') . '.csv';
        $csvContent = implode("\n", $lines);
        if ($this->isCsvBomEnabled($filters)) {
            $csvContent = "\xEF\xBB\xBF" . $csvContent;
        }

        return new Response(
            $csvContent,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    public function reconciliation(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectReconciliationFiltersFromQuery();
        $rows = $this->getMonthlyReconciliationRows(auth()->user(), auth()->isAdmin(), $filters);
        $meterSummaryRows = $this->getReconciliationMeterSummaryRows(auth()->user(), auth()->isAdmin(), $filters);

        return Response::html($this->reconciliationTemplate($rows, $filters, $meterSummaryRows));
    }

    public function reconciliationExport(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectReconciliationFiltersFromQuery();
        $rows = $this->getMonthlyReconciliationRows(auth()->user(), auth()->isAdmin(), $filters);

        $dataLines = [];
        $dataLines[] = '账期,账单数,应收总额,实收总额,未收差额,已收账单数,收款率%';
        $sumBills = 0;
        $sumReceivable = 0.0;
        $sumReceived = 0.0;
        $sumUnpaid = 0.0;

        foreach ($rows as $row) {
            $period = (string) ($row['payment_period'] ?? '');
            $billCount = (int) ($row['bill_count'] ?? 0);
            $receivable = (float) ($row['receivable_amount'] ?? 0);
            $received = (float) ($row['received_amount'] ?? 0);
            $unpaid = max(0.0, $receivable - $received);
            $paidCount = (int) ($row['paid_count'] ?? 0);
            $paidRate = $billCount > 0 ? round(($paidCount / $billCount) * 100, 2) : 0;

            $sumBills += $billCount;
            $sumReceivable += $receivable;
            $sumReceived += $received;
            $sumUnpaid += $unpaid;

            $dataLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                $period,
                (string) $billCount,
                number_format($receivable, 2, '.', ''),
                number_format($received, 2, '.', ''),
                number_format($unpaid, 2, '.', ''),
                (string) $paidCount,
                number_format($paidRate, 2, '.', ''),
            ]));
        }

        $periodFrom = (string) ($filters['period_from'] ?? '');
        $periodTo = (string) ($filters['period_to'] ?? '');
        $keyword = (string) ($filters['keyword'] ?? '');
        $meterType = (string) ($filters['meter_type'] ?? '');
        $meterCode = (string) ($filters['meter_code'] ?? '');
        $unpaidOnly = (string) ($filters['unpaid_only'] ?? '') === '1';
        $sortBy = (string) ($filters['sort_by'] ?? 'payment_period');
        $sortDir = (string) ($filters['sort_dir'] ?? 'desc');
        $summaryRef = trim((string) ($filters['summary_ref'] ?? ''));
        if ($summaryRef !== '' && !preg_match('/^REC-\d{12}-[A-F0-9]{6}$/', $summaryRef)) {
            $summaryRef = '';
        }
        if ($summaryRef === '') {
            $summaryRef = $this->buildReconciliationSummaryRef($keyword, $periodFrom, $periodTo, $unpaidOnly, $sortBy, $sortDir, $sumBills, $sumReceivable, $sumReceived, $sumUnpaid);
        }

        $metaRows = [
            ['口径字段', '值'],
            ['schema_version', 'reconciliation_csv_v2'],
            ['export_type', 'reconciliation'],
            ['摘要编号', $summaryRef],
            ['导出时间', date('Y-m-d H:i')],
            ['关键字', $keyword !== '' ? $keyword : '全部'],
            ['账期', ($periodFrom !== '' || $periodTo !== '') ? (($periodFrom !== '' ? $periodFrom : '不限') . '~' . ($periodTo !== '' ? $periodTo : '不限')) : '全部'],
            ['表计类型', $meterType !== '' ? $this->meterTypeLabel($meterType) : '全部'],
            ['表计编号', $meterCode !== '' ? $meterCode : '全部'],
            ['仅欠费', $unpaidOnly ? '是' : '否'],
            ['排序', $this->getReconciliationSortLabel($sortBy, $sortDir)],
        ];

        $metaLines = [];
        foreach ($metaRows as $metaRow) {
            $metaLines[] = implode(',', array_map([$this, 'escapeCsv'], $metaRow));
        }

        $lines = array_merge($metaLines, [''], $dataLines);

        $filenamePeriod = $periodFrom !== '' ? $periodFrom : ($periodTo !== '' ? $periodTo : date('Y-m'));
        $filenameSuffix = $summaryRef !== '' ? '-' . $summaryRef : '';
        $filename = 'rent-reconciliation-' . $filenamePeriod . $filenameSuffix . '.csv';
        $csvContent = implode("\n", $lines);
        if ($this->isCsvBomEnabled($filters)) {
            $csvContent = "\xEF\xBB\xBF" . $csvContent;
        }

        return new Response(
            $csvContent,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    public function financialReport(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectFinancialReportFiltersFromQuery();
        $dataset = $this->buildFinancialReportDataset(auth()->user(), auth()->isAdmin(), $filters);

        return Response::html($this->financialReportTemplate($dataset, $filters, false));
    }

    public function personalReport(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectFinancialReportFiltersFromQuery();
        $dataset = $this->buildFinancialReportDataset(auth()->user(), auth()->isAdmin(), $filters);

        return Response::html($this->financialReportTemplate($dataset, $filters, true));
    }

    public function financialReportExport(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectFinancialReportFiltersFromQuery();
        $dataset = $this->buildFinancialReportDataset(auth()->user(), auth()->isAdmin(), $filters);

        return $this->financialReportCsvResponse($dataset, $filters, false);
    }

    public function personalReportExport(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectFinancialReportFiltersFromQuery();
        $dataset = $this->buildFinancialReportDataset(auth()->user(), auth()->isAdmin(), $filters);

        return $this->financialReportCsvResponse($dataset, $filters, true);
    }

    public function occupancyReport(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectOccupancyFiltersFromQuery();
        $dataset = $this->buildOccupancyReportDataset(auth()->user(), auth()->isAdmin(), $filters);

        return Response::html($this->occupancyReportTemplate($dataset, $filters));
    }

    public function occupancyReportExport(): Response
    {
        $this->ensureAuthenticated();

        $filters = $this->collectOccupancyFiltersFromQuery();
        $dataset = $this->buildOccupancyReportDataset(auth()->user(), auth()->isAdmin(), $filters);

        return $this->occupancyReportCsvResponse($dataset, $filters);
    }

    public function generate(): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $period = trim((string) ($_POST['period'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw HttpException::badRequest('账单周期格式无效，应为 YYYY-MM');
        }

        $generated = $this->generateBillsForPeriod($period, auth()->user(), auth()->isAdmin());
        return Response::redirect('/payments?period=' . urlencode($period) . '&generated=' . $generated);
    }

    public function create(): Response
    {
        $this->ensureAuthenticated();

        $period = trim((string) ($_GET['period'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m');
        }

        $contracts = $this->getActiveContracts(auth()->user(), auth()->isAdmin());
        $selectedContractId = (int) ($_GET['contract_id'] ?? ($contracts[0]['id'] ?? 0));

        $selectedContract = null;
        foreach ($contracts as $contract) {
            if ((int) $contract['id'] === $selectedContractId) {
                $selectedContract = $contract;
                break;
            }
        }

        $meterRows = [];
        if ($selectedContract !== null) {
            $contractId = (int) $selectedContract['id'];
            $this->ensureDefaultContractMeters($contractId);
            $meterRows = $this->buildMeterRowsForCreate($contractId);
        }

        return Response::html($this->billCreateTemplate($contracts, $selectedContract, $period, $meterRows));
    }

    public function store(): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $period = trim((string) ($_POST['period'] ?? date('Y-m')));

        if ($contractId <= 0) {
            throw HttpException::badRequest('合同参数无效');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw HttpException::badRequest('账单周期格式无效，应为 YYYY-MM');
        }

        $contract = $this->getActiveContractById($contractId, auth()->user(), auth()->isAdmin());
        if (!$contract) {
            throw HttpException::notFound('合同不存在或无权访问');
        }

        $exists = db()->fetch(
            'SELECT id FROM rent_payments WHERE contract_id = ? AND payment_period = ? LIMIT 1',
            [$contractId, $period]
        );
        if ($exists) {
            flash('error', '该合同当前周期账单已存在');
            return Response::redirect('/payments/create?contract_id=' . $contractId . '&period=' . urlencode($period));
        }

        $meterEntries = $this->parseMeterEntriesFromRequest($_POST, $contractId);
        if ($meterEntries === []) {
            throw HttpException::badRequest('请至少填写一条计量表记录');
        }

        $waterFee = 0.0;
        $electricFee = 0.0;
        $waterUsage = 0.0;
        $electricUsage = 0.0;
        $waterPreviousTotal = 0.0;
        $waterCurrentTotal = 0.0;
        $electricPreviousTotal = 0.0;
        $electricCurrentTotal = 0.0;

        foreach ($meterEntries as $entry) {
            if ($entry['meter_type'] === 'water') {
                $waterFee += (float) $entry['line_amount'];
                $waterUsage += (float) $entry['usage_amount'];
                $waterPreviousTotal += (float) $entry['previous_reading'];
                $waterCurrentTotal += (float) $entry['current_reading'];
            } else {
                $electricFee += (float) $entry['line_amount'];
                $electricUsage += (float) $entry['usage_amount'];
                $electricPreviousTotal += (float) $entry['previous_reading'];
                $electricCurrentTotal += (float) $entry['current_reading'];
            }
        }

        $rentAmount = (float) $contract['rent_amount'];
        $amountDue = $rentAmount + $waterFee + $electricFee;

        $dueDate = $this->buildDueDate($period, (int) $contract['payment_day']);

        $billDetails = [
            'bill_type' => 'metered_rent',
            'bill_schema_version' => 2,
            'formula' => [
                'water_previous' => round($waterPreviousTotal, 2),
                'water_current' => round($waterCurrentTotal, 2),
                'water_usage' => round($waterUsage, 2),
                'water_unit_price' => 0,
                'water_fee' => round($waterFee, 2),
                'electric_previous' => round($electricPreviousTotal, 2),
                'electric_current' => round($electricCurrentTotal, 2),
                'electric_usage' => round($electricUsage, 2),
                'electric_unit_price' => 0,
                'electric_fee' => round($electricFee, 2),
                'rent_amount' => round($rentAmount, 2),
                'total_amount_due' => round($amountDue, 2),
            ],
            'meter_items' => array_map(static function (array $entry): array {
                return [
                    'meter_type' => $entry['meter_type'],
                    'meter_code' => $entry['meter_code_snapshot'],
                    'meter_name' => $entry['meter_name_snapshot'],
                    'previous_reading' => round((float) $entry['previous_reading'], 2),
                    'current_reading' => round((float) $entry['current_reading'], 2),
                    'usage_amount' => round((float) $entry['usage_amount'], 2),
                    'unit_price' => round((float) $entry['unit_price'], 4),
                    'line_amount' => round((float) $entry['line_amount'], 2),
                ];
            }, $meterEntries),
            'payment_note' => null,
        ];

        $pdo = db()->getPdo();
        $pdo->beginTransaction();

        try {
            $insertedId = db()->insert('rent_payments', [
                'contract_id' => $contractId,
                'payment_number' => $this->generatePaymentNumber(),
                'payment_period' => $period,
                'due_date' => $dueDate,
                'amount_due' => number_format($amountDue, 2, '.', ''),
                'payment_method' => 'bank_transfer',
                'payment_status' => 'pending',
                'late_fee' => 0,
                'discount' => 0,
                'notes' => json_encode($billDetails, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($meterEntries as $entry) {
                db()->insert('rent_payment_meter_details', [
                    'rent_payment_id' => $insertedId,
                    'contract_id' => $contractId,
                    'meter_id' => $entry['meter_id'] > 0 ? $entry['meter_id'] : null,
                    'meter_type' => $entry['meter_type'],
                    'meter_code_snapshot' => $entry['meter_code_snapshot'],
                    'meter_name_snapshot' => $entry['meter_name_snapshot'],
                    'previous_reading' => number_format((float) $entry['previous_reading'], 2, '.', ''),
                    'current_reading' => number_format((float) $entry['current_reading'], 2, '.', ''),
                    'usage_amount' => number_format((float) $entry['usage_amount'], 2, '.', ''),
                    'unit_price' => number_format((float) $entry['unit_price'], 4, '.', ''),
                    'line_amount' => number_format((float) $entry['line_amount'], 2, '.', ''),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return Response::redirect('/payments/' . (int) $insertedId . '/receipt');
    }

    public function record(int $id): Response
    {
        $this->ensureAuthenticated();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $payment = $this->getPaymentById($id, auth()->user(), auth()->isAdmin());
        if (!$payment) {
            throw HttpException::notFound('账单不存在或无权访问');
        }

        $amountPaid = (float) ($_POST['amount_paid'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? ($payment['discount'] ?? 0));
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'bank_transfer'));
        $discountSource = trim((string) ($_POST['discount_source'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($amountPaid < 0) {
            throw HttpException::badRequest('实付金额不能小于 0');
        }

        if ($discount < 0) {
            throw HttpException::badRequest('折扣金额不能小于 0');
        }

        $allowedMethods = ['cash', 'bank_transfer', 'alipay', 'wechat_pay', 'other'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            throw HttpException::badRequest('支付方式无效');
        }

        $allowedDiscountSources = ['', 'deposit_offset', 'promotion', 'bad_debt', 'other'];
        if (!in_array($discountSource, $allowedDiscountSources, true)) {
            throw HttpException::badRequest('折扣抵扣来源无效');
        }

        $grossDue = (float) $payment['amount_due'] + (float) $payment['late_fee'];
        if ($discount > $grossDue) {
            throw HttpException::badRequest('折扣金额不能大于应收合计（应付+滞纳金）');
        }

        if ($discount > 0 && $discountSource === '') {
            throw HttpException::badRequest('存在折扣抵扣时，请选择抵扣来源');
        }

        $totalDue = max(0.0, $grossDue - $discount);
        $status = 'pending';
        if ($amountPaid >= $totalDue) {
            $status = 'paid';
        } elseif ($amountPaid > 0 || $discount > 0) {
            $status = 'partial';
        }

        $existingBill = $this->extractBillDetails($payment['notes'] ?? null);
        $storedNotes = $notes !== '' ? $notes : null;
        if ($existingBill !== null) {
            $existingBill['payment_note'] = $notes !== '' ? $notes : null;
            $existingBill['discount_source'] = $discountSource !== '' ? $discountSource : null;
            $storedNotes = json_encode($existingBill, JSON_UNESCAPED_UNICODE);
        }

        db()->update('rent_payments', [
            'amount_paid' => number_format($amountPaid, 2, '.', ''),
            'discount' => number_format($discount, 2, '.', ''),
            'payment_method' => $paymentMethod,
            'payment_status' => $status,
            'paid_date' => date('Y-m-d'),
            'confirmed_by' => (int) auth()->id(),
            'confirmed_at' => date('Y-m-d H:i:s'),
            'notes' => $storedNotes,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        if ($amountPaid > 0) {
            db()->insert('financial_records', [
                'record_type' => 'income',
                'category' => 'rent',
                'amount' => number_format($amountPaid, 2, '.', ''),
                'currency' => 'CNY',
                'description' => '租金到账: ' . $payment['payment_number'],
                'reference_type' => 'rent_payment',
                'reference_id' => $id,
                'payment_method' => $paymentMethod,
                'transaction_date' => date('Y-m-d'),
                'recorded_by' => (int) auth()->id(),
                'notes' => $notes !== '' ? $notes : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return Response::redirect('/payments/' . $id . '/receipt');
    }

    public function receipt(int $id): Response
    {
        $this->ensureAuthenticated();

        $payment = $this->getPaymentById($id, auth()->user(), auth()->isAdmin());
        if (!$payment) {
            throw HttpException::notFound('账单不存在或无权访问');
        }

        return Response::html($this->receiptTemplate($payment));
    }

    public function meterTypes(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureAdminOnly();

        $rows = $this->getMeterTypeRows(true);

        return Response::html($this->meterTypesTemplate($rows));
    }

    public function meterTypeStore(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureAdminOnly();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $typeKey = strtolower(trim((string) ($_POST['type_key'] ?? '')));
        $typeName = trim((string) ($_POST['type_name'] ?? ''));
        $prefix = strtoupper(trim((string) ($_POST['default_code_prefix'] ?? '')));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = (string) ($_POST['is_active'] ?? '') === '1' ? 1 : 0;

        if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/', $typeKey)) {
            flash('error', '类型编码仅支持小写字母、数字、下划线，且长度 2-30');
            return Response::redirect('/meter-types');
        }

        if ($typeName === '') {
            flash('error', '类型名称不能为空');
            return Response::redirect('/meter-types');
        }

        if (mb_strlen($typeName) > 50) {
            $typeName = mb_substr($typeName, 0, 50);
        }

        if (!preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $prefix)) {
            flash('error', '默认编号前缀仅支持大写字母、数字、下划线，且长度 2-20');
            return Response::redirect('/meter-types');
        }

        $exists = db()->fetch('SELECT id FROM meter_types WHERE type_key = ? LIMIT 1', [$typeKey]);
        if ($exists) {
            flash('error', '类型编码已存在');
            return Response::redirect('/meter-types');
        }

        db()->insert('meter_types', [
            'type_key' => $typeKey,
            'type_name' => $typeName,
            'default_code_prefix' => $prefix,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        flash('success', '计量类型添加成功');
        return Response::redirect('/meter-types');
    }

    public function meterTypeUpdate(int $id): Response
    {
        $this->ensureAuthenticated();
        $this->ensureAdminOnly();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            return Response::json(['success' => false, 'message' => 'CSRF令牌无效'], 403);
        }

        $row = db()->fetch('SELECT id, type_key FROM meter_types WHERE id = ? LIMIT 1', [$id]);
        if (!$row) {
            throw HttpException::notFound('计量类型不存在');
        }

        $typeName = trim((string) ($_POST['type_name'] ?? ''));
        $prefix = strtoupper(trim((string) ($_POST['default_code_prefix'] ?? '')));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = (string) ($_POST['is_active'] ?? '') === '1' ? 1 : 0;

        if ($typeName === '') {
            flash('error', '类型名称不能为空');
            return Response::redirect('/meter-types');
        }

        if (mb_strlen($typeName) > 50) {
            $typeName = mb_substr($typeName, 0, 50);
        }

        if (!preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $prefix)) {
            flash('error', '默认编号前缀仅支持大写字母、数字、下划线，且长度 2-20');
            return Response::redirect('/meter-types');
        }

        db()->update('meter_types', [
            'type_name' => $typeName,
            'default_code_prefix' => $prefix,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        flash('success', '计量类型更新成功');
        return Response::redirect('/meter-types');
    }

    private function generateBillsForPeriod(string $period, array $user, bool $isAdmin): int
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = date('Y-m-t', strtotime($periodStart));

        $sql = "
            SELECT
                c.id AS contract_id,
                c.rent_amount,
                c.payment_day,
                c.contract_status,
                c.start_date,
                c.end_date,
                p.owner_id
            FROM contracts c
            JOIN properties p ON p.id = c.property_id
            WHERE c.contract_status = 'active'
              AND c.start_date <= ?
              AND c.end_date >= ?
        ";

        $params = [$periodEnd, $periodStart];
        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        $contracts = db()->fetchAll($sql, $params);

        $generated = 0;
        foreach ($contracts as $contract) {
            $contractId = (int) $contract['contract_id'];

            $exists = db()->fetch(
                'SELECT id FROM rent_payments WHERE contract_id = ? AND payment_period = ? LIMIT 1',
                [$contractId, $period]
            );
            if ($exists) {
                continue;
            }

            $dueDate = $this->buildDueDate($period, (int) $contract['payment_day']);

            $this->ensureDefaultContractMeters($contractId);
            $meterRows = $this->buildMeterRowsForCreate($contractId);
            $meterEntries = $this->buildMeterEntriesForAutoGenerate($meterRows);

            $waterUsage = 0.0;
            $electricUsage = 0.0;
            $waterFee = 0.0;
            $electricFee = 0.0;
            $waterPreviousTotal = 0.0;
            $waterCurrentTotal = 0.0;
            $electricPreviousTotal = 0.0;
            $electricCurrentTotal = 0.0;

            foreach ($meterEntries as $entry) {
                if ($entry['meter_type'] === 'water') {
                    $waterPreviousTotal += (float) $entry['previous_reading'];
                    $waterCurrentTotal += (float) $entry['current_reading'];
                    $waterUsage += (float) $entry['usage_amount'];
                    $waterFee += (float) $entry['line_amount'];
                } else {
                    $electricPreviousTotal += (float) $entry['previous_reading'];
                    $electricCurrentTotal += (float) $entry['current_reading'];
                    $electricUsage += (float) $entry['usage_amount'];
                    $electricFee += (float) $entry['line_amount'];
                }
            }

            $rentAmount = (float) $contract['rent_amount'];
            $totalAmountDue = $rentAmount + $waterFee + $electricFee;

            $billDetails = [
                'bill_type' => 'metered_rent',
                'bill_schema_version' => 2,
                'auto_generated' => true,
                'formula' => [
                    'water_previous' => round($waterPreviousTotal, 2),
                    'water_current' => round($waterCurrentTotal, 2),
                    'water_usage' => round($waterUsage, 2),
                    'water_unit_price' => 0,
                    'water_fee' => round($waterFee, 2),
                    'electric_previous' => round($electricPreviousTotal, 2),
                    'electric_current' => round($electricCurrentTotal, 2),
                    'electric_usage' => round($electricUsage, 2),
                    'electric_unit_price' => 0,
                    'electric_fee' => round($electricFee, 2),
                    'rent_amount' => round($rentAmount, 2),
                    'total_amount_due' => round($totalAmountDue, 2),
                ],
                'meter_items' => array_map(static function (array $entry): array {
                    return [
                        'meter_type' => $entry['meter_type'],
                        'meter_code' => $entry['meter_code_snapshot'],
                        'meter_name' => $entry['meter_name_snapshot'],
                        'previous_reading' => round((float) $entry['previous_reading'], 2),
                        'current_reading' => round((float) $entry['current_reading'], 2),
                        'usage_amount' => round((float) $entry['usage_amount'], 2),
                        'unit_price' => round((float) $entry['unit_price'], 4),
                        'line_amount' => round((float) $entry['line_amount'], 2),
                    ];
                }, $meterEntries),
            ];

            $pdo = db()->getPdo();
            $pdo->beginTransaction();

            try {
                $paymentId = db()->insert('rent_payments', [
                    'contract_id' => $contractId,
                    'payment_number' => $this->generatePaymentNumber(),
                    'payment_period' => $period,
                    'due_date' => $dueDate,
                    'amount_due' => number_format($totalAmountDue, 2, '.', ''),
                    'payment_method' => 'bank_transfer',
                    'payment_status' => 'pending',
                    'late_fee' => 0,
                    'discount' => 0,
                    'notes' => json_encode($billDetails, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                foreach ($meterEntries as $entry) {
                    db()->insert('rent_payment_meter_details', [
                        'rent_payment_id' => $paymentId,
                        'contract_id' => $contractId,
                        'meter_id' => $entry['meter_id'] > 0 ? $entry['meter_id'] : null,
                        'meter_type' => $entry['meter_type'],
                        'meter_code_snapshot' => $entry['meter_code_snapshot'],
                        'meter_name_snapshot' => $entry['meter_name_snapshot'],
                        'previous_reading' => number_format((float) $entry['previous_reading'], 2, '.', ''),
                        'current_reading' => number_format((float) $entry['current_reading'], 2, '.', ''),
                        'usage_amount' => number_format((float) $entry['usage_amount'], 2, '.', ''),
                        'unit_price' => number_format((float) $entry['unit_price'], 4, '.', ''),
                        'line_amount' => number_format((float) $entry['line_amount'], 2, '.', ''),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $generated++;
        }

        return $generated;
    }

    private function refreshOverduePayments(): void
    {
        $lateFeeRate = (float) $this->getSetting('rent.late_fee_rate', '0.05');
        $gracePeriod = (int) $this->getSetting('rent.grace_period', '3');

        $rows = db()->fetchAll(
            "SELECT id, due_date, amount_due, discount, payment_status
             FROM rent_payments
             WHERE payment_status IN ('pending', 'partial')"
        );

        $today = strtotime(date('Y-m-d'));

        foreach ($rows as $row) {
            $dueDate = strtotime((string) $row['due_date']);
            if ($dueDate === false) {
                continue;
            }

            $daysOverdue = (int) floor(($today - $dueDate) / 86400) - $gracePeriod;
            if ($daysOverdue <= 0) {
                continue;
            }

            $lateFee = max(0, ((float) $row['amount_due'] - (float) $row['discount']) * $lateFeeRate * $daysOverdue);

            db()->update('rent_payments', [
                'payment_status' => 'overdue',
                'late_fee' => number_format($lateFee, 2, '.', ''),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);
        }
    }

    private function getPayments(array $user, bool $isAdmin, array $filters = []): array
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $period = trim((string) ($filters['period'] ?? ''));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $periodFrom = trim((string) ($filters['period_from'] ?? ''));
        $periodTo = trim((string) ($filters['period_to'] ?? ''));
        $amountMin = $this->parseOptionalNonNegativeFloat($filters['amount_min'] ?? null);
        $amountMax = $this->parseOptionalNonNegativeFloat($filters['amount_max'] ?? null);

        $sql = "
            SELECT
                rp.id,
                rp.contract_id,
                rp.payment_number,
                rp.payment_period,
                rp.due_date,
                rp.paid_date,
                rp.amount_due,
                rp.amount_paid,
                rp.payment_method,
                rp.payment_status,
                rp.late_fee,
                rp.discount,
                rp.notes,
                c.contract_number,
                c.tenant_name,
                p.property_code,
                p.property_name,
                p.owner_id
            FROM rent_payments rp
            JOIN contracts c ON c.id = rp.contract_id
            JOIN properties p ON p.id = c.property_id
            WHERE 1 = 1
        ";

        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        if ($status !== '') {
            if ($status === 'unpaid') {
                $sql .= " AND rp.payment_status IN ('pending', 'partial', 'overdue')";
            } else {
                $sql .= ' AND rp.payment_status = ?';
                $params[] = $status;
            }
        }

        if ($period !== '') {
            $sql .= ' AND rp.payment_period = ?';
            $params[] = $period;
        }

        if ($periodFrom !== '') {
            $sql .= ' AND rp.payment_period >= ?';
            $params[] = $periodFrom;
        }

        if ($periodTo !== '') {
            $sql .= ' AND rp.payment_period <= ?';
            $params[] = $periodTo;
        }

        if ($amountMin !== null) {
            $sql .= ' AND rp.amount_due >= ?';
            $params[] = number_format($amountMin, 2, '.', '');
        }

        if ($amountMax !== null) {
            $sql .= ' AND rp.amount_due <= ?';
            $params[] = number_format($amountMax, 2, '.', '');
        }

        if ($keyword !== '') {
            $sql .= ' AND (c.tenant_name LIKE ? OR p.property_name LIKE ? OR p.property_code LIKE ?)';
            $pattern = '%' . $keyword . '%';
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $sql .= ' ORDER BY rp.due_date DESC, rp.id DESC';

        return db()->fetchAll($sql, $params);
    }

    private function getMonthlyReconciliationRows(array $user, bool $isAdmin, array $filters = []): array
    {
        $periodFrom = trim((string) ($filters['period_from'] ?? ''));
        $periodTo = trim((string) ($filters['period_to'] ?? ''));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $meterType = trim((string) ($filters['meter_type'] ?? ''));
        $meterCode = trim((string) ($filters['meter_code'] ?? ''));
        $unpaidOnly = (string) ($filters['unpaid_only'] ?? '') === '1';
        $sortBy = (string) ($filters['sort_by'] ?? 'payment_period');
        $sortDir = (string) ($filters['sort_dir'] ?? 'desc');

        $sql = "
            SELECT
                rp.payment_period,
                COUNT(*) AS bill_count,
                SUM(rp.amount_due) AS receivable_amount,
                SUM(COALESCE(rp.amount_paid, 0)) AS received_amount,
                SUM(rp.late_fee) AS late_fee_amount,
                SUM(rp.discount) AS discount_amount,
                SUM(CASE WHEN rp.payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count
            FROM rent_payments rp
            JOIN contracts c ON c.id = rp.contract_id
            JOIN properties p ON p.id = c.property_id
            WHERE 1 = 1
        ";

        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        if ($periodFrom !== '') {
            $sql .= ' AND rp.payment_period >= ?';
            $params[] = $periodFrom;
        }

        if ($periodTo !== '') {
            $sql .= ' AND rp.payment_period <= ?';
            $params[] = $periodTo;
        }

        if ($keyword !== '') {
            $sql .= ' AND (c.tenant_name LIKE ? OR p.property_name LIKE ? OR p.property_code LIKE ?)';
            $pattern = '%' . $keyword . '%';
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        if ($meterType !== '' || $meterCode !== '') {
            $sql .= ' AND EXISTS (SELECT 1 FROM rent_payment_meter_details d WHERE d.rent_payment_id = rp.id';
            if ($meterType !== '') {
                $sql .= ' AND d.meter_type = ?';
                $params[] = $meterType;
            }
            if ($meterCode !== '') {
                $sql .= ' AND d.meter_code_snapshot LIKE ?';
                $params[] = '%' . $meterCode . '%';
            }
            $sql .= ')';
        }

        $sql .= ' GROUP BY rp.payment_period';
        if ($unpaidOnly) {
            $sql .= ' HAVING (SUM(rp.amount_due) - SUM(COALESCE(rp.amount_paid, 0))) > 0';
        }
        $sql .= ' ORDER BY rp.payment_period DESC';

        $rows = db()->fetchAll($sql, $params);
        return $this->applyReconciliationSorting($rows, $sortBy, $sortDir);
    }

    private function getReconciliationMeterSummaryRows(array $user, bool $isAdmin, array $filters = []): array
    {
        $periodFrom = trim((string) ($filters['period_from'] ?? ''));
        $periodTo = trim((string) ($filters['period_to'] ?? ''));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $meterType = trim((string) ($filters['meter_type'] ?? ''));
        $meterCode = trim((string) ($filters['meter_code'] ?? ''));
        $unpaidOnly = (string) ($filters['unpaid_only'] ?? '') === '1';

        $sql = '
            SELECT
                d.meter_type,
                d.meter_code_snapshot AS meter_code,
                MAX(COALESCE(NULLIF(d.meter_name_snapshot, \'\'), \'-\')) AS meter_name,
                COUNT(DISTINCT d.rent_payment_id) AS bill_count,
                SUM(d.usage_amount) AS total_usage,
                SUM(d.line_amount) AS total_fee
            FROM rent_payment_meter_details d
            INNER JOIN rent_payments rp ON rp.id = d.rent_payment_id
            INNER JOIN contracts c ON c.id = rp.contract_id
            INNER JOIN properties p ON p.id = c.property_id
            WHERE 1 = 1
        ';

        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        if ($periodFrom !== '') {
            $sql .= ' AND rp.payment_period >= ?';
            $params[] = $periodFrom;
        }

        if ($periodTo !== '') {
            $sql .= ' AND rp.payment_period <= ?';
            $params[] = $periodTo;
        }

        if ($keyword !== '') {
            $sql .= ' AND (c.tenant_name LIKE ? OR p.property_name LIKE ? OR p.property_code LIKE ?)';
            $pattern = '%' . $keyword . '%';
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        if ($meterType !== '') {
            $sql .= ' AND d.meter_type = ?';
            $params[] = $meterType;
        }

        if ($meterCode !== '') {
            $sql .= ' AND d.meter_code_snapshot LIKE ?';
            $params[] = '%' . $meterCode . '%';
        }

        if ($unpaidOnly) {
            $sql .= " AND rp.payment_status IN ('pending', 'partial', 'overdue')";
        }

        $sql .= ' GROUP BY d.meter_type, d.meter_code_snapshot ORDER BY total_fee DESC, meter_code ASC';

        return db()->fetchAll($sql, $params);
    }

    private function buildFinancialReportDataset(array $user, bool $isAdmin, array $filters): array
    {
        $methodFilter = (string) ($filters['method'] ?? '');
        $overdueBucketFilter = (string) ($filters['overdue_bucket'] ?? '');

        $paymentFilters = [
            'status' => '',
            'period' => '',
            'keyword' => (string) ($filters['keyword'] ?? ''),
            'period_from' => (string) ($filters['period_from'] ?? ''),
            'period_to' => (string) ($filters['period_to'] ?? ''),
            'amount_min' => '',
            'amount_max' => '',
            'bom' => (string) ($filters['bom'] ?? ''),
            'source' => '',
            'source_period' => '',
        ];

        $payments = $this->getPayments($user, $isAdmin, $paymentFilters);

        $summary = [
            'bill_count' => 0,
            'total_due' => 0.0,
            'total_paid' => 0.0,
            'total_unpaid' => 0.0,
            'paid_count' => 0,
            'overdue_count' => 0,
            'rent_total' => 0.0,
            'water_total' => 0.0,
            'electric_total' => 0.0,
            'other_total' => 0.0,
            'expense_total' => 0.0,
            'net_profit' => 0.0,
        ];

        $periodMap = [];
        $propertyMap = [];
        $tenantMap = [];
        $contractMap = [];
        $methodMap = [];
        $overdueBuckets = [
            'not_due' => ['label' => '未逾期', 'count' => 0, 'amount' => 0.0],
            '1_7' => ['label' => '逾期1-7天', 'count' => 0, 'amount' => 0.0],
            '8_30' => ['label' => '逾期8-30天', 'count' => 0, 'amount' => 0.0],
            '31_plus' => ['label' => '逾期31天以上', 'count' => 0, 'amount' => 0.0],
        ];
        $today = date('Y-m-d');

        foreach ($payments as $payment) {
            $period = (string) ($payment['payment_period'] ?? '未知账期');
            $amountDue = (float) ($payment['amount_due'] ?? 0);
            $amountPaid = (float) ($payment['amount_paid'] ?? 0);
            $status = (string) ($payment['payment_status'] ?? 'pending');
            $propertyName = (string) ($payment['property_name'] ?? '未命名房产');
            $tenantName = (string) ($payment['tenant_name'] ?? '未知租客');
            $contractNumber = (string) ($payment['contract_number'] ?? '未知合同');
            $method = (string) ($payment['payment_method'] ?? 'other');
            $dueDate = (string) ($payment['due_date'] ?? '');
            $unpaidAmount = max(0.0, $amountDue - $amountPaid);
            $bucketKey = $this->resolveOverdueBucketKey($dueDate, $status, $unpaidAmount, $today);

            if ($methodFilter !== '' && $method !== $methodFilter) {
                continue;
            }

            if ($overdueBucketFilter !== '') {
                if ($overdueBucketFilter === 'none') {
                    if ($bucketKey !== null) {
                        continue;
                    }
                } elseif ($bucketKey !== $overdueBucketFilter) {
                    continue;
                }
            }

            $summary['bill_count']++;
            $summary['total_due'] += $amountDue;
            $summary['total_paid'] += $amountPaid;
            if ($status === 'paid') {
                $summary['paid_count']++;
            }
            if ($status === 'overdue') {
                $summary['overdue_count']++;
            }

            if (!isset($periodMap[$period])) {
                $periodMap[$period] = [
                    'period' => $period,
                    'bill_count' => 0,
                    'total_due' => 0.0,
                    'total_paid' => 0.0,
                ];
            }

            $periodMap[$period]['bill_count']++;
            $periodMap[$period]['total_due'] += $amountDue;
            $periodMap[$period]['total_paid'] += $amountPaid;

            if (!isset($propertyMap[$propertyName])) {
                $propertyMap[$propertyName] = [
                    'property_name' => $propertyName,
                    'bill_count' => 0,
                    'total_due' => 0.0,
                    'total_paid' => 0.0,
                ];
            }

            $propertyMap[$propertyName]['bill_count']++;
            $propertyMap[$propertyName]['total_due'] += $amountDue;
            $propertyMap[$propertyName]['total_paid'] += $amountPaid;

            if (!isset($tenantMap[$tenantName])) {
                $tenantMap[$tenantName] = [
                    'tenant_name' => $tenantName,
                    'bill_count' => 0,
                    'total_due' => 0.0,
                    'total_paid' => 0.0,
                ];
            }

            $tenantMap[$tenantName]['bill_count']++;
            $tenantMap[$tenantName]['total_due'] += $amountDue;
            $tenantMap[$tenantName]['total_paid'] += $amountPaid;

            $contractKey = $contractNumber . '::' . $tenantName;
            if (!isset($contractMap[$contractKey])) {
                $contractMap[$contractKey] = [
                    'contract_number' => $contractNumber,
                    'tenant_name' => $tenantName,
                    'bill_count' => 0,
                    'total_due' => 0.0,
                    'total_paid' => 0.0,
                ];
            }

            $contractMap[$contractKey]['bill_count']++;
            $contractMap[$contractKey]['total_due'] += $amountDue;
            $contractMap[$contractKey]['total_paid'] += $amountPaid;

            if (!isset($methodMap[$method])) {
                $methodMap[$method] = [
                    'method' => $method,
                    'bill_count' => 0,
                    'paid_amount' => 0.0,
                ];
            }
            $methodMap[$method]['bill_count']++;
            $methodMap[$method]['paid_amount'] += $amountPaid;

            if ($bucketKey !== null) {
                $overdueBuckets[$bucketKey]['count']++;
                $overdueBuckets[$bucketKey]['amount'] += $unpaidAmount;
            }

            $details = $this->extractBillDetails(isset($payment['notes']) ? (string) $payment['notes'] : null);
            if ($details !== null && isset($details['formula']) && is_array($details['formula'])) {
                $formula = $details['formula'];
                $rent = (float) ($formula['rent_amount'] ?? 0);
                $water = (float) ($formula['water_fee'] ?? 0);
                $electric = (float) ($formula['electric_fee'] ?? 0);
                $known = $rent + $water + $electric;

                $summary['rent_total'] += $rent;
                $summary['water_total'] += $water;
                $summary['electric_total'] += $electric;
                if ($amountDue > $known) {
                    $summary['other_total'] += ($amountDue - $known);
                }
            } else {
                $summary['other_total'] += $amountDue;
            }
        }

        $summary['total_unpaid'] = max(0.0, $summary['total_due'] - $summary['total_paid']);
        $summary['paid_rate'] = $summary['bill_count'] > 0
            ? round(($summary['paid_count'] / $summary['bill_count']) * 100, 2)
            : 0.0;

        $expenseSummary = $this->getExpenseSummaryForReport($user, $isAdmin, $filters);
        $summary['expense_total'] = (float) ($expenseSummary['total_expense'] ?? 0.0);
        $summary['net_profit'] = (float) ($summary['total_paid'] ?? 0.0) - (float) ($summary['expense_total'] ?? 0.0);

        krsort($periodMap);

        $monthlyRows = [];
        foreach ($periodMap as $periodRow) {
            $rowDue = (float) $periodRow['total_due'];
            $rowPaid = (float) $periodRow['total_paid'];
            $rowUnpaid = max(0.0, $rowDue - $rowPaid);
            $rowBillCount = (int) $periodRow['bill_count'];

            $monthlyRows[] = [
                'period' => (string) $periodRow['period'],
                'bill_count' => $rowBillCount,
                'total_due' => $rowDue,
                'total_paid' => $rowPaid,
                'total_unpaid' => $rowUnpaid,
                'paid_rate' => $rowBillCount > 0 ? round(($rowPaid / max($rowDue, 0.0001)) * 100, 2) : 0.0,
            ];
        }

        $propertyRows = array_values($propertyMap);
        usort($propertyRows, static function (array $a, array $b): int {
            $cmp = ((float) $b['total_due']) <=> ((float) $a['total_due']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['property_name'], (string) $b['property_name']);
        });

        $tenantRows = array_values($tenantMap);
        usort($tenantRows, static function (array $a, array $b): int {
            $cmp = ((float) $b['total_due']) <=> ((float) $a['total_due']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['tenant_name'], (string) $b['tenant_name']);
        });

        $contractRows = array_values($contractMap);
        usort($contractRows, static function (array $a, array $b): int {
            $cmp = ((float) $b['total_due']) <=> ((float) $a['total_due']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['contract_number'], (string) $b['contract_number']);
        });

        $totalDueBase = max(0.0001, (float) ($summary['total_due'] ?? 0));
        $categoryRows = [
            [
                'category' => '固定租金',
                'amount' => (float) ($summary['rent_total'] ?? 0),
                'ratio' => round(((float) ($summary['rent_total'] ?? 0) / $totalDueBase) * 100, 2),
            ],
            [
                'category' => '水费',
                'amount' => (float) ($summary['water_total'] ?? 0),
                'ratio' => round(((float) ($summary['water_total'] ?? 0) / $totalDueBase) * 100, 2),
            ],
            [
                'category' => '电费',
                'amount' => (float) ($summary['electric_total'] ?? 0),
                'ratio' => round(((float) ($summary['electric_total'] ?? 0) / $totalDueBase) * 100, 2),
            ],
            [
                'category' => '其他/历史数据',
                'amount' => (float) ($summary['other_total'] ?? 0),
                'ratio' => round(((float) ($summary['other_total'] ?? 0) / $totalDueBase) * 100, 2),
            ],
        ];

        usort($categoryRows, static function (array $a, array $b): int {
            return ((float) $b['amount']) <=> ((float) $a['amount']);
        });

        $trendRows = array_slice(array_reverse($monthlyRows), -12);

        $methodRows = array_values($methodMap);
        usort($methodRows, static function (array $a, array $b): int {
            $cmp = ((float) $b['paid_amount']) <=> ((float) $a['paid_amount']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['method'], (string) $b['method']);
        });

        $overdueRows = [];
        foreach (['not_due', '1_7', '8_30', '31_plus'] as $key) {
            $overdueRows[] = [
                'bucket' => $overdueBuckets[$key]['label'],
                'count' => (int) $overdueBuckets[$key]['count'],
                'amount' => (float) $overdueBuckets[$key]['amount'],
            ];
        }

        return [
            'summary' => $summary,
            'monthly_rows' => $monthlyRows,
            'trend_rows' => $trendRows,
            'category_rows' => $categoryRows,
            'method_rows' => $methodRows,
            'overdue_rows' => $overdueRows,
            'property_rows' => array_slice($propertyRows, 0, 10),
            'tenant_rows' => array_slice($tenantRows, 0, 10),
            'contract_rows' => array_slice($contractRows, 0, 10),
            'raw_count' => count($payments),
        ];
    }

    private function getExpenseSummaryForReport(array $user, bool $isAdmin, array $filters): array
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $periodFrom = trim((string) ($filters['period_from'] ?? ''));
        $periodTo = trim((string) ($filters['period_to'] ?? ''));

        $sql = '
            SELECT COALESCE(SUM(fr.amount), 0) AS total_expense
            FROM financial_records fr
            LEFT JOIN properties p ON fr.reference_type = "property" AND fr.reference_id = p.id
            WHERE fr.record_type = "expense"
        ';
        $params = [];

        if (!$isAdmin) {
            $uid = (int) ($user['id'] ?? 0);
            $sql .= ' AND (fr.recorded_by = ? OR (fr.reference_type = "property" AND p.owner_id = ?))';
            $params[] = $uid;
            $params[] = $uid;
        }

        if ($periodFrom !== '' && preg_match('/^\d{4}-\d{2}$/', $periodFrom)) {
            $sql .= ' AND fr.transaction_date >= ?';
            $params[] = $periodFrom . '-01';
        }

        if ($periodTo !== '' && preg_match('/^\d{4}-\d{2}$/', $periodTo)) {
            $sql .= ' AND fr.transaction_date <= LAST_DAY(?)';
            $params[] = $periodTo . '-01';
        }

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $sql .= ' AND (fr.description LIKE ? OR fr.category LIKE ? OR COALESCE(p.property_name, "") LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $row = db()->fetch($sql, $params);

        return [
            'total_expense' => (float) ($row['total_expense'] ?? 0),
        ];
    }

    private function financialReportCsvResponse(array $dataset, array $filters, bool $personal): Response
    {
        $summary = is_array($dataset['summary'] ?? null) ? $dataset['summary'] : [];
        $rows = is_array($dataset['monthly_rows'] ?? null) ? $dataset['monthly_rows'] : [];
        $categoryRows = is_array($dataset['category_rows'] ?? null) ? $dataset['category_rows'] : [];
        $methodRows = is_array($dataset['method_rows'] ?? null) ? $dataset['method_rows'] : [];
        $overdueRows = is_array($dataset['overdue_rows'] ?? null) ? $dataset['overdue_rows'] : [];
        $propertyRows = is_array($dataset['property_rows'] ?? null) ? $dataset['property_rows'] : [];
        $tenantRows = is_array($dataset['tenant_rows'] ?? null) ? $dataset['tenant_rows'] : [];
        $contractRows = is_array($dataset['contract_rows'] ?? null) ? $dataset['contract_rows'] : [];

        $periodFrom = (string) ($filters['period_from'] ?? '');
        $periodTo = (string) ($filters['period_to'] ?? '');
        $keyword = (string) ($filters['keyword'] ?? '');
        $method = (string) ($filters['method'] ?? '');
        $overdueBucket = (string) ($filters['overdue_bucket'] ?? '');

        $metaRows = [
            ['口径字段', '值'],
            ['schema_version', 'financial_report_csv_v2'],
            ['report_type', $personal ? 'personal' : 'financial'],
            ['导出时间', date('Y-m-d H:i')],
            ['关键字', $keyword !== '' ? $keyword : '全部'],
            ['账期', ($periodFrom !== '' || $periodTo !== '') ? (($periodFrom !== '' ? $periodFrom : '不限') . '~' . ($periodTo !== '' ? $periodTo : '不限')) : '全部'],
            ['支付方式', $method !== '' ? $this->paymentMethodLabel($method) : '全部'],
            ['逾期层级', $this->overdueBucketLabel($overdueBucket)],
            ['账单总数', (string) ((int) ($summary['bill_count'] ?? 0))],
            ['应收总额', number_format((float) ($summary['total_due'] ?? 0), 2, '.', '')],
            ['实收总额', number_format((float) ($summary['total_paid'] ?? 0), 2, '.', '')],
            ['未收总额', number_format((float) ($summary['total_unpaid'] ?? 0), 2, '.', '')],
            ['支出总额', number_format((float) ($summary['expense_total'] ?? 0), 2, '.', '')],
            ['实际盈利', number_format((float) ($summary['net_profit'] ?? 0), 2, '.', '')],
        ];

        $metaLines = [];
        foreach ($metaRows as $metaRow) {
            $metaLines[] = implode(',', array_map([$this, 'escapeCsv'], $metaRow));
        }

        $dataLines = [];
        $dataLines[] = '账期,账单数,应收总额,实收总额,未收总额,收款率%';
        foreach ($rows as $row) {
            $dataLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                (string) ($row['period'] ?? ''),
                (string) ((int) ($row['bill_count'] ?? 0)),
                number_format((float) ($row['total_due'] ?? 0), 2, '.', ''),
                number_format((float) ($row['total_paid'] ?? 0), 2, '.', ''),
                number_format((float) ($row['total_unpaid'] ?? 0), 2, '.', ''),
                number_format((float) ($row['paid_rate'] ?? 0), 2, '.', ''),
            ]));
        }

        $categoryLines = [];
        $categoryLines[] = '分类,金额,占比%';
        foreach ($categoryRows as $row) {
            $categoryLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                (string) ($row['category'] ?? ''),
                number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                number_format((float) ($row['ratio'] ?? 0), 2, '.', ''),
            ]));
        }

        $methodLines = [];
        $methodLines[] = '支付方式,账单数,实收金额';
        foreach ($methodRows as $row) {
            $methodLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                $this->paymentMethodLabel((string) ($row['method'] ?? 'other')),
                (string) ((int) ($row['bill_count'] ?? 0)),
                number_format((float) ($row['paid_amount'] ?? 0), 2, '.', ''),
            ]));
        }

        $overdueLines = [];
        $overdueLines[] = '逾期分层,账单数,未收金额';
        foreach ($overdueRows as $row) {
            $overdueLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                (string) ($row['bucket'] ?? ''),
                (string) ((int) ($row['count'] ?? 0)),
                number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
            ]));
        }

        $propertyLines = [];
        $propertyLines[] = '房产,账单数,应收总额,实收总额';
        foreach ($propertyRows as $row) {
            $propertyLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                (string) ($row['property_name'] ?? ''),
                (string) ((int) ($row['bill_count'] ?? 0)),
                number_format((float) ($row['total_due'] ?? 0), 2, '.', ''),
                number_format((float) ($row['total_paid'] ?? 0), 2, '.', ''),
            ]));
        }

        $tenantLines = [];
        $tenantLines[] = '租客,账单数,应收总额,实收总额';
        foreach ($tenantRows as $row) {
            $tenantLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                (string) ($row['tenant_name'] ?? ''),
                (string) ((int) ($row['bill_count'] ?? 0)),
                number_format((float) ($row['total_due'] ?? 0), 2, '.', ''),
                number_format((float) ($row['total_paid'] ?? 0), 2, '.', ''),
            ]));
        }

        $contractLines = [];
        $contractLines[] = '合同编号,租客,账单数,应收总额,实收总额';
        foreach ($contractRows as $row) {
            $contractLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                (string) ($row['contract_number'] ?? ''),
                (string) ($row['tenant_name'] ?? ''),
                (string) ((int) ($row['bill_count'] ?? 0)),
                number_format((float) ($row['total_due'] ?? 0), 2, '.', ''),
                number_format((float) ($row['total_paid'] ?? 0), 2, '.', ''),
            ]));
        }

        $content = implode("\n", array_merge($metaLines, [''], $dataLines, [''], $categoryLines, [''], $methodLines, [''], $overdueLines, [''], $propertyLines, [''], $tenantLines, [''], $contractLines));
        if ($this->isCsvBomEnabled($filters)) {
            $content = "\xEF\xBB\xBF" . $content;
        }

        $filenamePeriod = $periodFrom !== '' ? $periodFrom : ($periodTo !== '' ? $periodTo : date('Y-m'));
        $filename = ($personal ? 'personal' : 'financial') . '-report-' . $filenamePeriod . '.csv';

        return new Response(
            $content,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    private function buildOccupancyReportDataset(array $user, bool $isAdmin, array $filters): array
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $city = trim((string) ($filters['city'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));

        $sql = '
            SELECT
                p.id,
                p.property_name,
                p.property_code,
                p.city,
                p.district,
                p.total_rooms,
                p.available_rooms,
                p.property_status,
                u.real_name AS owner_name,
                p.owner_id
            FROM properties p
            LEFT JOIN users u ON u.id = p.owner_id
            WHERE 1 = 1
        ';

        $params = [];
        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        if ($keyword !== '') {
            $sql .= ' AND (p.property_name LIKE ? OR p.property_code LIKE ? OR p.address LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($city !== '') {
            $sql .= ' AND p.city = ?';
            $params[] = $city;
        }

        if ($status !== '') {
            $sql .= ' AND p.property_status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY p.created_at DESC, p.id DESC';
        $rows = db()->fetchAll($sql, $params);

        $items = [];
        $summary = [
            'property_count' => 0,
            'total_rooms' => 0,
            'occupied_rooms' => 0,
            'vacant_rooms' => 0,
            'vacant_property_count' => 0,
            'occupied_property_count' => 0,
            'maintenance_property_count' => 0,
        ];

        foreach ($rows as $row) {
            $totalRooms = max(0, (int) ($row['total_rooms'] ?? 0));
            $vacantRooms = max(0, min($totalRooms, (int) ($row['available_rooms'] ?? 0)));
            $occupiedRooms = max(0, $totalRooms - $vacantRooms);
            $propertyStatus = (string) ($row['property_status'] ?? 'vacant');

            $summary['property_count']++;
            $summary['total_rooms'] += $totalRooms;
            $summary['occupied_rooms'] += $occupiedRooms;
            $summary['vacant_rooms'] += $vacantRooms;

            if ($propertyStatus === 'vacant') {
                $summary['vacant_property_count']++;
            } elseif ($propertyStatus === 'occupied') {
                $summary['occupied_property_count']++;
            } elseif ($propertyStatus === 'under_maintenance') {
                $summary['maintenance_property_count']++;
            }

            $items[] = [
                'property_name' => (string) ($row['property_name'] ?? ''),
                'property_code' => (string) ($row['property_code'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'district' => (string) ($row['district'] ?? ''),
                'owner_name' => (string) ($row['owner_name'] ?? '未知'),
                'total_rooms' => $totalRooms,
                'occupied_rooms' => $occupiedRooms,
                'vacant_rooms' => $vacantRooms,
                'occupancy_rate' => $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 2) : 0.0,
                'property_status' => $propertyStatus,
            ];
        }

        $summary['occupancy_rate'] = $summary['total_rooms'] > 0
            ? round(($summary['occupied_rooms'] / $summary['total_rooms']) * 100, 2)
            : 0.0;

        return [
            'summary' => $summary,
            'rows' => $items,
        ];
    }

    private function occupancyReportCsvResponse(array $dataset, array $filters): Response
    {
        $summary = is_array($dataset['summary'] ?? null) ? $dataset['summary'] : [];
        $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];

        $metaRows = [
            ['口径字段', '值'],
            ['schema_version', 'occupancy_report_csv_v1'],
            ['report_type', 'occupancy'],
            ['导出时间', date('Y-m-d H:i')],
            ['关键字', (string) ($filters['keyword'] ?? '') !== '' ? (string) $filters['keyword'] : '全部'],
            ['城市', (string) ($filters['city'] ?? '') !== '' ? (string) $filters['city'] : '全部'],
            ['状态', (string) ($filters['status'] ?? '') !== '' ? (string) $filters['status'] : '全部'],
            ['房产数', (string) ((int) ($summary['property_count'] ?? 0))],
            ['总房间数', (string) ((int) ($summary['total_rooms'] ?? 0))],
            ['已出租房间数', (string) ((int) ($summary['occupied_rooms'] ?? 0))],
            ['空置房间数', (string) ((int) ($summary['vacant_rooms'] ?? 0))],
            ['整体出租率%', number_format((float) ($summary['occupancy_rate'] ?? 0), 2, '.', '')],
        ];

        $metaLines = [];
        foreach ($metaRows as $metaRow) {
            $metaLines[] = implode(',', array_map([$this, 'escapeCsv'], $metaRow));
        }

        $dataLines = [];
        $dataLines[] = '房产,房号,城市,区域,房东,总房间数,已出租房间数,空置房间数,出租率%,状态';
        foreach ($rows as $row) {
            $dataLines[] = implode(',', array_map([$this, 'escapeCsv'], [
                (string) ($row['property_name'] ?? ''),
                (string) ($row['property_code'] ?? ''),
                (string) ($row['city'] ?? ''),
                (string) ($row['district'] ?? ''),
                (string) ($row['owner_name'] ?? ''),
                (string) ((int) ($row['total_rooms'] ?? 0)),
                (string) ((int) ($row['occupied_rooms'] ?? 0)),
                (string) ((int) ($row['vacant_rooms'] ?? 0)),
                number_format((float) ($row['occupancy_rate'] ?? 0), 2, '.', ''),
                (string) ($row['property_status'] ?? ''),
            ]));
        }

        $content = implode("\n", array_merge($metaLines, [''], $dataLines));
        if ($this->isCsvBomEnabled($filters)) {
            $content = "\xEF\xBB\xBF" . $content;
        }

        $filename = 'occupancy-report-' . date('Y-m') . '.csv';

        return new Response(
            $content,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    private function getPaymentById(int $id, array $user, bool $isAdmin): ?array
    {
        $sql = "
            SELECT
                rp.id,
                rp.payment_number,
                rp.payment_period,
                rp.due_date,
                rp.paid_date,
                rp.amount_due,
                rp.amount_paid,
                rp.payment_method,
                rp.payment_status,
                rp.late_fee,
                rp.discount,
                rp.notes,
                c.contract_number,
                c.tenant_name,
                p.property_name,
                p.owner_id
            FROM rent_payments rp
            JOIN contracts c ON c.id = rp.contract_id
            JOIN properties p ON p.id = c.property_id
            WHERE rp.id = ?
        ";

        $params = [$id];
        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        return db()->fetch($sql, $params);
    }

    private function getPaymentMeterDetails(int $paymentId): array
    {
        if ($paymentId <= 0) {
            return [];
        }

        return db()->fetchAll(
            'SELECT meter_type, meter_code_snapshot, meter_name_snapshot, previous_reading, current_reading, usage_amount, unit_price, line_amount
             FROM rent_payment_meter_details
             WHERE rent_payment_id = ?
             ORDER BY id ASC',
            [$paymentId]
        );
    }

    private function getActiveContracts(array $user, bool $isAdmin): array
    {
        $sql = "
            SELECT
                c.id,
                c.contract_number,
                c.tenant_name,
                c.rent_amount,
                c.payment_day,
                p.property_name,
                p.owner_id
            FROM contracts c
            JOIN properties p ON p.id = c.property_id
            WHERE c.contract_status = 'active'
        ";

        $params = [];
        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        $sql .= ' ORDER BY c.id DESC';

        return db()->fetchAll($sql, $params);
    }

    private function getActiveContractById(int $contractId, array $user, bool $isAdmin): ?array
    {
        $sql = "
            SELECT
                c.id,
                c.contract_number,
                c.tenant_name,
                c.rent_amount,
                c.payment_day,
                p.property_name,
                p.owner_id
            FROM contracts c
            JOIN properties p ON p.id = c.property_id
            WHERE c.id = ?
              AND c.contract_status = 'active'
        ";

        $params = [$contractId];
        if (!$isAdmin) {
            $sql .= ' AND p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        return db()->fetch($sql, $params);
    }

    private function ensureDefaultContractMeters(int $contractId): void
    {
        $existingCount = (int) db()->fetchColumn(
            'SELECT COUNT(*) FROM contract_meters WHERE contract_id = ? AND is_active = 1',
            [$contractId]
        );

        if ($existingCount > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($this->getMeterTypeDefinitions() as $definition) {
            $typeKey = (string) ($definition['type_key'] ?? '');
            if ($typeKey === '') {
                continue;
            }

            $typeName = (string) ($definition['type_name'] ?? $typeKey);
            $prefix = strtoupper((string) ($definition['default_code_prefix'] ?? $typeKey));
            $sortOrder = (int) ($definition['sort_order'] ?? 0);

            db()->insert('contract_meters', [
                'contract_id' => $contractId,
                'meter_type' => $typeKey,
                'meter_code' => $prefix . '-1',
                'meter_name' => '默认' . $typeName,
                'default_unit_price' => number_format($this->getDefaultUnitPriceByMeterType($typeKey), 4, '.', ''),
                'initial_reading' => number_format(0, 2, '.', ''),
                'is_active' => 1,
                'sort_order' => $sortOrder > 0 ? $sortOrder : 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function buildMeterRowsForCreate(int $contractId): array
    {
        $meters = db()->fetchAll(
            'SELECT id, meter_type, meter_code, meter_name, default_unit_price, initial_reading, sort_order
             FROM contract_meters
             WHERE contract_id = ? AND is_active = 1
             ORDER BY sort_order ASC, id ASC',
            [$contractId]
        );

        $rows = [];
        foreach ($meters as $meter) {
            $meterId = (int) ($meter['id'] ?? 0);
            $lastDetail = db()->fetch(
                'SELECT d.current_reading, d.unit_price
                 FROM rent_payment_meter_details d
                 INNER JOIN rent_payments rp ON rp.id = d.rent_payment_id
                 WHERE d.meter_id = ? AND rp.contract_id = ?
                 ORDER BY rp.payment_period DESC, d.id DESC
                 LIMIT 1',
                [$meterId, $contractId]
            );

            $previousReading = $lastDetail !== null
                ? (float) ($lastDetail['current_reading'] ?? 0)
                : (float) ($meter['initial_reading'] ?? 0);

            $unitPrice = $lastDetail !== null
                ? (float) ($lastDetail['unit_price'] ?? 0)
                : (float) ($meter['default_unit_price'] ?? 0);

            $rows[] = [
                'meter_id' => $meterId,
                'meter_type' => (string) ($meter['meter_type'] ?? 'water'),
                'meter_code' => (string) ($meter['meter_code'] ?? ''),
                'meter_name' => (string) ($meter['meter_name'] ?? ''),
                'previous_reading' => round($previousReading, 2),
                'current_reading' => round($previousReading, 2),
                'unit_price' => round($unitPrice, 4),
            ];
        }

        return $rows;
    }

    private function parseMeterEntriesFromRequest(array $input, int $contractId): array
    {
        $meterIds = is_array($input['meter_id'] ?? null) ? array_values($input['meter_id']) : [];
        $meterTypes = is_array($input['meter_type'] ?? null) ? array_values($input['meter_type']) : [];
        $meterCodes = is_array($input['meter_code'] ?? null) ? array_values($input['meter_code']) : [];
        $meterNames = is_array($input['meter_name'] ?? null) ? array_values($input['meter_name']) : [];
        $previousReadings = is_array($input['previous_reading'] ?? null) ? array_values($input['previous_reading']) : [];
        $currentReadings = is_array($input['current_reading'] ?? null) ? array_values($input['current_reading']) : [];
        $unitPrices = is_array($input['unit_price'] ?? null) ? array_values($input['unit_price']) : [];

        $max = max(
            count($meterIds),
            count($meterTypes),
            count($meterCodes),
            count($meterNames),
            count($previousReadings),
            count($currentReadings),
            count($unitPrices)
        );

        $entries = [];
        $usedCodes = [];
        $now = date('Y-m-d H:i:s');

        for ($i = 0; $i < $max; $i++) {
            $meterType = trim((string) ($meterTypes[$i] ?? ''));
            $meterCode = trim((string) ($meterCodes[$i] ?? ''));
            $meterName = trim((string) ($meterNames[$i] ?? ''));

            if ($meterType === '' && $meterCode === '') {
                continue;
            }

            if (!$this->isAllowedMeterType($meterType)) {
                throw HttpException::badRequest('表计类型无效，请先在计量类型表中配置后再使用');
            }

            if ($meterCode === '') {
                throw HttpException::badRequest('表计编号不能为空');
            }

            if (isset($usedCodes[$meterCode])) {
                throw HttpException::badRequest('同一账单内存在重复表计编号: ' . $meterCode);
            }
            $usedCodes[$meterCode] = true;

            $previous = $this->parseNonNegativeFloat($previousReadings[$i] ?? 0, '上月读数无效: ' . $meterCode);
            $current = $this->parseNonNegativeFloat($currentReadings[$i] ?? 0, '本月读数无效: ' . $meterCode);
            $unitPrice = $this->parseNonNegativeFloat($unitPrices[$i] ?? 0, '单价无效: ' . $meterCode);

            if ($current < $previous) {
                throw HttpException::badRequest('表计 ' . $meterCode . ' 本月读数不能小于上月读数');
            }

            $meterId = (int) ($meterIds[$i] ?? 0);
            if ($meterId > 0) {
                $meter = db()->fetch(
                    'SELECT id, meter_type, meter_code FROM contract_meters WHERE id = ? AND contract_id = ? LIMIT 1',
                    [$meterId, $contractId]
                );

                if (!$meter) {
                    throw HttpException::badRequest('表计不存在或不属于当前合同');
                }

                if ((string) ($meter['meter_type'] ?? '') !== $meterType) {
                    throw HttpException::badRequest('表计类型与已登记表计不一致: ' . $meterCode);
                }

                db()->update('contract_meters', [
                    'meter_name' => $meterName !== '' ? $meterName : null,
                    'default_unit_price' => number_format($unitPrice, 4, '.', ''),
                    'updated_at' => $now,
                ], ['id' => $meterId]);
            } else {
                $existing = db()->fetch(
                    'SELECT id FROM contract_meters WHERE contract_id = ? AND meter_code = ? LIMIT 1',
                    [$contractId, $meterCode]
                );

                if ($existing) {
                    $meterId = (int) ($existing['id'] ?? 0);
                } else {
                    $meterId = db()->insert('contract_meters', [
                        'contract_id' => $contractId,
                        'meter_type' => $meterType,
                        'meter_code' => $meterCode,
                        'meter_name' => $meterName !== '' ? $meterName : null,
                        'default_unit_price' => number_format($unitPrice, 4, '.', ''),
                        'initial_reading' => number_format($previous, 2, '.', ''),
                        'is_active' => 1,
                        'sort_order' => ($i + 1) * 10,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $usage = $current - $previous;
            $lineAmount = $usage * $unitPrice;

            $entries[] = [
                'meter_id' => $meterId,
                'meter_type' => $meterType,
                'meter_code_snapshot' => $meterCode,
                'meter_name_snapshot' => $meterName !== '' ? $meterName : null,
                'previous_reading' => $previous,
                'current_reading' => $current,
                'usage_amount' => $usage,
                'unit_price' => $unitPrice,
                'line_amount' => $lineAmount,
            ];
        }

        return $entries;
    }

    private function buildMeterEntriesForAutoGenerate(array $meterRows): array
    {
        $entries = [];
        foreach ($meterRows as $row) {
            $meterType = (string) ($row['meter_type'] ?? 'water');
            $previous = (float) ($row['previous_reading'] ?? 0);
            $current = (float) ($row['current_reading'] ?? $previous);
            $unitPrice = (float) ($row['unit_price'] ?? 0);

            $entries[] = [
                'meter_id' => (int) ($row['meter_id'] ?? 0),
                'meter_type' => $meterType,
                'meter_code_snapshot' => (string) ($row['meter_code'] ?? ''),
                'meter_name_snapshot' => trim((string) ($row['meter_name'] ?? '')) !== '' ? (string) $row['meter_name'] : null,
                'previous_reading' => $previous,
                'current_reading' => $current,
                'usage_amount' => max(0, $current - $previous),
                'unit_price' => $unitPrice,
                'line_amount' => max(0, $current - $previous) * $unitPrice,
            ];
        }

        return $entries;
    }

    private function parseNonNegativeFloat($value, string $errorMessage): float
    {
        if (!is_numeric($value)) {
            throw HttpException::badRequest($errorMessage);
        }

        $number = (float) $value;
        if ($number < 0) {
            throw HttpException::badRequest($errorMessage);
        }

        return $number;
    }

    private function buildDueDate(string $period, int $paymentDay): string
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = date('Y-m-t', strtotime($periodStart));
        $lastDay = (int) date('d', strtotime($periodEnd));

        $dueDay = max(1, min(31, $paymentDay));
        $dueDay = min($dueDay, $lastDay);

        return sprintf('%04d-%02d-%02d', $year, $month, $dueDay);
    }

    private function extractBillDetails(?string $notes): ?array
    {
        if ($notes === null || trim($notes) === '') {
            return null;
        }

        $decoded = json_decode($notes, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!isset($decoded['bill_type']) || !isset($decoded['formula']) || !is_array($decoded['formula'])) {
            return null;
        }

        return $decoded;
    }

    private function getSetting(string $key, string $default): string
    {
        $row = db()->fetch('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [$key]);
        if (!$row || !isset($row['setting_value'])) {
            return $default;
        }

        return (string) $row['setting_value'];
    }

    private function generatePaymentNumber(): string
    {
        return 'PAY' . date('YmdHis') . random_int(10, 99);
    }

    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }

    private function ensureAdminOnly(): void
    {
        if (!auth()->isAdmin()) {
            throw HttpException::forbidden('仅管理员可执行此操作');
        }
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

    private function getMeterTypeRows(bool $includeInactive = true): array
    {
        $sql = 'SELECT id, type_key, type_name, default_code_prefix, sort_order, is_active, created_at, updated_at FROM meter_types WHERE 1 = 1';
        $params = [];

        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY sort_order ASC, id ASC';

        try {
            return db()->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function meterTypesTemplate(array $rows): string
    {
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'meter_types',
            'is_admin' => true,
            'show_user_menu' => true,
            'collapse_id' => 'meterTypesNavbar',
        ]);

        $alerts = $this->renderFlashAlerts();
        $tableRows = '';

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $isActive = (int) ($row['is_active'] ?? 1) === 1;
            $statusBadge = $isActive
                ? '<span class="badge bg-success">启用</span>'
                : '<span class="badge bg-secondary">停用</span>';

            $tableRows .= '<tr>'
                . '<td>' . $id . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['type_key'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['type_name'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['default_code_prefix'] ?? ''), ENT_QUOTES) . '</td>'
                . '<td class="text-end">' . (int) ($row['sort_order'] ?? 0) . '</td>'
                . '<td>' . $statusBadge . '</td>'
                . '<td>'
                . '<form method="POST" action="/meter-types/' . $id . '" class="row g-1 align-items-center">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<div class="col-md-3"><input class="form-control form-control-sm" name="type_name" value="' . htmlspecialchars((string) ($row['type_name'] ?? ''), ENT_QUOTES) . '" required></div>'
                . '<div class="col-md-2"><input class="form-control form-control-sm" name="default_code_prefix" value="' . htmlspecialchars((string) ($row['default_code_prefix'] ?? ''), ENT_QUOTES) . '" required></div>'
                . '<div class="col-md-2"><input class="form-control form-control-sm" type="number" name="sort_order" value="' . (int) ($row['sort_order'] ?? 0) . '"></div>'
                . '<div class="col-md-2"><select class="form-select form-select-sm" name="is_active"><option value="1"' . ($isActive ? ' selected' : '') . '>启用</option><option value="0"' . (!$isActive ? ' selected' : '') . '>停用</option></select></div>'
                . '<div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100" type="submit">保存</button></div>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="7" class="text-center text-muted">暂无计量类型，请先新增</td></tr>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>计量类型管理</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css">' . $navbarStyles . '</head><body>'
            . $navigation
            . '<div class="container mt-4">'
            . '<div class="d-flex justify-content-between align-items-center mb-3"><h3 class="mb-0">计量类型管理</h3><a class="btn btn-outline-secondary" href="/payments">返回账单</a></div>'
            . $alerts
            . '<div class="card mb-3"><div class="card-body">'
            . '<h6 class="mb-3">新增计量类型</h6>'
            . '<form class="row g-2" method="POST" action="/meter-types">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<div class="col-md-2"><label class="form-label">类型编码</label><input class="form-control" name="type_key" placeholder="如 gas" required></div>'
            . '<div class="col-md-3"><label class="form-label">类型名称</label><input class="form-control" name="type_name" placeholder="如 天然气表" required></div>'
            . '<div class="col-md-3"><label class="form-label">默认编号前缀</label><input class="form-control" name="default_code_prefix" placeholder="如 GAS" required></div>'
            . '<div class="col-md-2"><label class="form-label">排序</label><input class="form-control" type="number" name="sort_order" value="0"></div>'
            . '<div class="col-md-2"><label class="form-label">状态</label><select class="form-select" name="is_active"><option value="1" selected>启用</option><option value="0">停用</option></select></div>'
            . '<div class="col-12 d-grid"><button class="btn btn-primary" type="submit">添加类型</button></div>'
            . '</form>'
            . '</div></div>'
            . '<div class="card"><div class="card-body table-responsive">'
            . '<h6 class="mb-3">已有类型</h6>'
            . '<table class="table table-sm table-bordered align-middle"><thead><tr><th>ID</th><th>编码</th><th>名称</th><th>默认前缀</th><th class="text-end">排序</th><th>状态</th><th style="min-width:420px;">维护</th></tr></thead><tbody>' . $tableRows . '</tbody></table>'
            . '</div></div>'
            . '</div></body></html>';
    }

    private function paymentStatusBadge(string $status): string
    {
        $map = [
            'pending' => ['待支付', 'secondary'],
            'paid' => ['已支付', 'success'],
            'overdue' => ['已逾期', 'danger'],
            'partial' => ['部分支付', 'warning'],
            'cancelled' => ['已取消', 'dark'],
        ];

        [$label, $color] = $map[$status] ?? [$status, 'secondary'];
        return '<span class="badge bg-' . $color . '">' . $label . '</span>';
    }

    private function paymentMethodLabel(string $method): string
    {
        $labels = [
            'cash' => '现金',
            'bank_transfer' => '银行转账',
            'alipay' => '支付宝',
            'wechat_pay' => '微信支付',
            'other' => '其他',
        ];

        return $labels[$method] ?? '其他';
    }

    private function discountSourceLabel(string $source): string
    {
        $labels = [
            'deposit_offset' => '押金冲抵',
            'promotion' => '优惠减免',
            'bad_debt' => '坏账核销',
            'other' => '其他',
        ];

        return $labels[$source] ?? '未指定';
    }

    private function overdueBucketLabel(string $bucket): string
    {
        $labels = [
            '' => '全部',
            'none' => '仅无逾期',
            'not_due' => '未逾期',
            '1_7' => '逾期1-7天',
            '8_30' => '逾期8-30天',
            '31_plus' => '逾期31天以上',
        ];

        return $labels[$bucket] ?? '全部';
    }

    private function resolveOverdueBucketKey(string $dueDate, string $status, float $unpaidAmount, string $today): ?string
    {
        $isUnpaid = in_array($status, ['pending', 'partial', 'overdue'], true) && $unpaidAmount > 0;
        if (!$isUnpaid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            return null;
        }

        $days = (int) floor((strtotime($today) - strtotime($dueDate)) / 86400);
        if ($days <= 0) {
            return 'not_due';
        }
        if ($days <= 7) {
            return '1_7';
        }
        if ($days <= 30) {
            return '8_30';
        }

        return '31_plus';
    }

    private function paymentListTemplate(array $payments, array $filters, array $pagination = []): string
    {
        $isAdmin = auth()->isAdmin();
        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'payments',
            'is_admin' => $isAdmin,
            'show_user_menu' => true,
            'collapse_id' => 'paymentsListNavbar',
        ]);

        $status = (string) ($filters['status'] ?? '');
        $period = (string) ($filters['period'] ?? '');
        $keyword = (string) ($filters['keyword'] ?? '');
        $periodFrom = (string) ($filters['period_from'] ?? '');
        $periodTo = (string) ($filters['period_to'] ?? '');
        $amountMin = (string) ($filters['amount_min'] ?? '');
        $amountMax = (string) ($filters['amount_max'] ?? '');
        $bom = (string) ($filters['bom'] ?? '');
        $source = (string) ($filters['source'] ?? '');
        $sourcePeriod = (string) ($filters['source_period'] ?? '');
        $meterDetail = (string) ($filters['meter_detail'] ?? '');

        $rows = '';
        foreach ($payments as $payment) {
            $id = (int) $payment['id'];

            $rows .= '<tr>'
                . '<td data-label="ID">' . $id . '</td>'
                . '<td data-label="支付编号">' . htmlspecialchars((string) $payment['payment_number']) . '</td>'
                . '<td data-label="租客">' . htmlspecialchars((string) $payment['tenant_name']) . '</td>'
                . '<td data-label="房产">' . htmlspecialchars((string) $payment['property_name']) . '</td>'
                . '<td data-label="到期日">' . htmlspecialchars((string) $payment['due_date']) . '</td>'
                . '<td data-label="应付">¥' . number_format((float) $payment['amount_due'], 2) . '</td>'
                . '<td data-label="状态">' . $this->paymentStatusBadge((string) $payment['payment_status']) . '</td>'
                . '<td data-label="操作" class="bill-actions"><div class="bill-actions-wrap"><a class="btn btn-sm btn-outline-primary" href="/payments/' . $id . '/receipt">查看</a></div></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8" class="text-center text-muted">暂无账单数据</td></tr>';
        }

        $pageStyles = '<style>'
            . '.payments-list-page .card { border: 0; box-shadow: 0 0.25rem 1rem rgba(15, 23, 42, 0.08); }'
            . '.payments-list-page .page-head { background: linear-gradient(120deg, #eef6ff, #e6f7f2); color: #0f172a; border: 1px solid #d8e7f8; border-radius: 1rem; padding: 1rem 1.1rem; margin-bottom: 0.9rem; }'
            . '.payments-list-page .page-head .subtitle { color: #334155; margin: 0.25rem 0 0; font-size: 0.9rem; }'
            . '.payments-list-page .filter-panel .card-body { padding: 0.85rem; }'
            . '.payments-list-page .filter-form .form-label { font-size: 0.78rem; color: #64748b; margin-bottom: 0.22rem; }'
            . '.payments-list-page .filter-form .form-select, .payments-list-page .filter-form .form-control { border-color: #cbd5e1; }'
            . '.payments-list-page .filter-form .form-select:focus, .payments-list-page .filter-form .form-control:focus { border-color: #0f766e; box-shadow: 0 0 0 0.2rem rgba(15, 118, 110, 0.12); }'
            . '.payments-list-page .filter-actions { display: flex; gap: 0.45rem; flex-wrap: wrap; }'
            . '.payments-list-page .filter-actions .btn { min-width: 118px; }'
            . '.payments-list-page .preset-panel { margin-top: 0.65rem; }'
            . '.payments-list-page .preset-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.55rem; }'
            . '.payments-list-page .preset-card { display: block; text-decoration: none; border: 1px solid #dbe7f6; border-radius: 0.75rem; background: linear-gradient(120deg, #f8fbff, #f2f8ff); padding: 0.6rem 0.7rem; transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease; }'
            . '.payments-list-page .preset-card:hover { transform: translateY(-1px); border-color: #bcd0ee; box-shadow: 0 0.3rem 0.75rem rgba(15, 23, 42, 0.09); }'
            . '.payments-list-page .preset-card .preset-title { font-size: 0.83rem; font-weight: 600; color: #0f172a; }'
            . '.payments-list-page .preset-card .preset-desc { font-size: 0.73rem; color: #64748b; margin-top: 0.15rem; }'
            . '.payments-list-page .generator-panel .card-body { padding: 0.8rem 0.85rem; }'
            . '.payments-list-page .generator-form .form-label { font-size: 0.78rem; color: #64748b; margin-bottom: 0.22rem; }'
            . '.payments-list-page .table thead th { white-space: nowrap; font-size: 0.82rem; }'
            . '.payments-list-page .table tbody td { vertical-align: middle; }'
            . '.payments-list-page .table tbody tr:hover { background: #f8fafc; }'
            . '.payments-list-page .bill-actions { min-width: 96px; }'
            . '.payments-list-page .bill-actions-wrap { display: flex; align-items: center; justify-content: flex-start; }'
            . '.payments-list-page .payments-toolbar { width: 100%; display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }'
            . '.payments-list-page .payments-toolbar .btn { flex: 1 1 calc(50% - 0.5rem); }'
            . '@media (max-width: 767.98px) {'
            . '  .payments-list-page .page-head { padding: 0.85rem 0.9rem; }'
            . '  .payments-list-page .page-head .subtitle { font-size: 0.82rem; }'
            . '  .payments-list-page .filter-panel .card-body { padding: 0.65rem; }'
            . '  .payments-list-page .filter-actions .btn { width: 100%; min-width: 0; }'
            . '  .payments-list-page .preset-grid { grid-template-columns: 1fr; }'
            . '  .payments-list-page .generator-panel .card-body { padding: 0.65rem; }'
            . '  .payments-list-page .mobile-table thead { display: none; }'
            . '  .payments-list-page .mobile-table tbody tr { display: block; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 0.6rem 0.75rem; margin-bottom: 0.75rem; background: #fff; }'
            . '  .payments-list-page .mobile-table tbody td { display: flex; justify-content: space-between; gap: 0.75rem; border: 0 !important; padding: 0.35rem 0; }'
            . '  .payments-list-page .mobile-table tbody td::before { content: attr(data-label); font-size: 0.78rem; color: #64748b; flex: 0 0 38%; }'
            . '  .payments-list-page .mobile-table tbody td[colspan] { display: block; text-align: center; }'
            . '  .payments-list-page .mobile-table tbody td[colspan]::before { display: none; }'
            . '  .payments-list-page .bill-actions { min-width: 0; }'
            . '  .payments-list-page .bill-actions-wrap { justify-content: stretch; }'
            . '  .payments-list-page .bill-actions-wrap .btn { width: 100%; }'
            . '}'
            . '</style>';

        $generated = isset($_GET['generated']) ? (int) $_GET['generated'] : null;
        $notice = '';
        if ($generated !== null) {
            $notice = '<div class="alert alert-success">账单生成完成，本次新增 ' . $generated . ' 条</div>';
        }

        $drilldownNotice = '';
        if ($source === 'reconciliation') {
            $periodHint = $sourcePeriod !== '' ? '（账期：' . htmlspecialchars($sourcePeriod) . '）' : '';
            $drilldownNotice = '<div class="alert alert-info py-2">当前列表来自对账页钻取' . $periodHint . '</div>';
        }

        $queryString = http_build_query([
            'status' => $status,
            'period' => $period,
            'keyword' => $keyword,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'amount_min' => $amountMin,
            'amount_max' => $amountMax,
            'bom' => $bom,
            'source' => $source,
            'source_period' => $sourcePeriod,
            'meter_detail' => $meterDetail,
        ]);

        $detailExportQuery = http_build_query([
            'status' => $status,
            'period' => $period,
            'keyword' => $keyword,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'amount_min' => $amountMin,
            'amount_max' => $amountMax,
            'bom' => $bom,
            'source' => $source,
            'source_period' => $sourcePeriod,
            'meter_detail' => '1',
        ]);
        $meterTypesQuickAction = $isAdmin
            ? '<a href="/meter-types" class="btn btn-outline-warning">计量类型管理</a>'
            : '';

        $currentMonth = date('Y-m');
        $presetUnpaidCurrentMonth = http_build_query(['status' => 'unpaid', 'period' => $currentMonth]);
        $presetOverdueCurrentMonth = http_build_query(['status' => 'overdue', 'period' => $currentMonth]);
        $presetPaidAll = http_build_query(['status' => 'paid']);

        $currentPage = max(1, (int) ($pagination['page'] ?? 1));
        $lastPage = max(1, (int) ($pagination['last_page'] ?? 1));
        $total = max(0, (int) ($pagination['total'] ?? count($payments)));
        $perPage = max(1, (int) ($pagination['per_page'] ?? 10));

        $paginationBase = [
            'status' => $status,
            'period' => $period,
            'keyword' => $keyword,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'amount_min' => $amountMin,
            'amount_max' => $amountMax,
            'bom' => $bom,
            'source' => $source,
            'source_period' => $sourcePeriod,
        ];

        $paginationHtml = '';
        if ($lastPage > 1) {
            $windowSize = 10;
            $startPage = $currentPage;
            $endPage = min($lastPage, $startPage + $windowSize - 1);

            $paginationHtml .= '<nav aria-label="Page navigation example"><ul class="pagination justify-content-center mb-0">';

            $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
            $prevQuery = http_build_query($paginationBase + ['page' => max(1, $currentPage - 1)]);
            $paginationHtml .= '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="/payments?' . htmlspecialchars($prevQuery, ENT_QUOTES) . '">Previous</a></li>';

            if ($startPage > 1) {
                $firstQuery = http_build_query($paginationBase + ['page' => 1]);
                $paginationHtml .= '<li class="page-item"><a class="page-link" href="/payments?' . htmlspecialchars($firstQuery, ENT_QUOTES) . '">1</a></li>';
                if ($startPage > 2) {
                    $paginationHtml .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }

            for ($i = $startPage; $i <= $endPage; $i++) {
                $pageQuery = http_build_query($paginationBase + ['page' => $i]);
                $active = $i === $currentPage ? ' active' : '';
                $paginationHtml .= '<li class="page-item' . $active . '"><a class="page-link" href="/payments?' . htmlspecialchars($pageQuery, ENT_QUOTES) . '">' . $i . '</a></li>';
            }

            if ($endPage < $lastPage) {
                if ($endPage < $lastPage - 1) {
                    $paginationHtml .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                $lastQuery = http_build_query($paginationBase + ['page' => $lastPage]);
                $paginationHtml .= '<li class="page-item"><a class="page-link" href="/payments?' . htmlspecialchars($lastQuery, ENT_QUOTES) . '">' . $lastPage . '</a></li>';
            }

            $nextDisabled = $currentPage >= $lastPage ? ' disabled' : '';
            $nextQuery = http_build_query($paginationBase + ['page' => min($lastPage, $currentPage + 1)]);
            $paginationHtml .= '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="/payments?' . htmlspecialchars($nextQuery, ENT_QUOTES) . '">Next</a></li>';
            $paginationHtml .= '</ul></nav>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>支付与账单</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . $pageStyles
            . '</head><body>'
            . $navigation
            . '<div class="container mt-4 payments-list-page">'
            . '<div class="page-head">'
            . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">'
            . '<h3 class="mb-0">支付与账单</h3>'
            . '<div class="d-flex gap-2 payments-toolbar"><a href="/payments/reconciliation" class="btn btn-outline-dark">月度对账</a><a href="/payments/create" class="btn btn-outline-primary">新建月度账单</a>' . $meterTypesQuickAction . '<a href="/payments/export?' . htmlspecialchars($queryString, ENT_QUOTES) . '" class="btn btn-outline-success">导出CSV</a><a href="/payments/export?' . htmlspecialchars($detailExportQuery, ENT_QUOTES) . '" class="btn btn-outline-success">导出表计明细CSV</a><a href="/dashboard" class="btn btn-outline-secondary">返回仪表板</a></div>'
            . '</div>'
            . '<p class="subtitle">集中查看账单状态、收款进度与金额区间，支持按条件筛选和快速导出。</p>'
            . '</div>'
            . $notice
            . $drilldownNotice
            . '<div class="card filter-panel mb-2"><div class="card-body">'
            . '<form id="paymentsFilterForm" class="filter-form" method="GET" action="/payments">'
            . '<input type="hidden" name="bom" value="' . htmlspecialchars($bom) . '">'
            . '<input type="hidden" name="source" value="' . htmlspecialchars($source) . '">'
            . '<input type="hidden" name="source_period" value="' . htmlspecialchars($sourcePeriod) . '">'
            . '<div class="row g-2">'
            . '<div class="col-md-4"><label class="form-label">关键词</label><input class="form-control" type="search" name="keyword" placeholder="租客/房号/房产名" value="' . htmlspecialchars($keyword) . '"></div>'
            . '<div class="col-md-2"><label class="form-label">账期</label><input class="form-control" type="month" name="period" value="' . htmlspecialchars($period) . '" title="精确账期"></div>'
            . '<div class="col-md-2"><label class="form-label">起始账期</label><input class="form-control" type="month" name="period_from" value="' . htmlspecialchars($periodFrom) . '" title="起始账期"></div>'
            . '<div class="col-md-2"><label class="form-label">结束账期</label><input class="form-control" type="month" name="period_to" value="' . htmlspecialchars($periodTo) . '" title="结束账期"></div>'
            . '<div class="col-md-2"><label class="form-label">状态</label><select class="form-select" name="status">'
            . '<option value="">全部状态</option>'
            . '<option value="pending"' . ($status === 'pending' ? ' selected' : '') . '>待支付</option>'
            . '<option value="overdue"' . ($status === 'overdue' ? ' selected' : '') . '>逾期</option>'
            . '<option value="paid"' . ($status === 'paid' ? ' selected' : '') . '>已支付</option>'
            . '<option value="partial"' . ($status === 'partial' ? ' selected' : '') . '>部分支付</option>'
            . '<option value="unpaid"' . ($status === 'unpaid' ? ' selected' : '') . '>全部未收</option>'
            . '</select></div>'
            . '</div>'
            . '<div class="row g-2 mt-1">'
            . '<div class="col-md-3"><label class="form-label">最低金额</label><input class="form-control" type="number" step="0.01" min="0" name="amount_min" placeholder="最低金额" value="' . htmlspecialchars($amountMin) . '"></div>'
            . '<div class="col-md-3"><label class="form-label">最高金额</label><input class="form-control" type="number" step="0.01" min="0" name="amount_max" placeholder="最高金额" value="' . htmlspecialchars($amountMax) . '"></div>'
            . '<div class="col-md-6 d-flex align-items-end"><div class="filter-actions w-100">'
            . '<button class="btn btn-outline-primary" type="submit">筛选</button>'
            . '<a class="btn btn-outline-secondary" data-filter-reset="payments" href="/payments">重置</a>'
            . '</div></div>'
            . '</div>'
            . '<div class="preset-panel">'
            . '<div class="text-muted small mb-2">常用预设：</div>'
            . '<div class="preset-grid">'
            . '<a class="preset-card" href="/payments?' . htmlspecialchars($presetUnpaidCurrentMonth, ENT_QUOTES) . '"><div class="preset-title">本月待收</div><div class="preset-desc">快速查看本月未收账单</div></a>'
            . '<a class="preset-card" href="/payments?' . htmlspecialchars($presetOverdueCurrentMonth, ENT_QUOTES) . '"><div class="preset-title">本月逾期</div><div class="preset-desc">优先处理逾期账单项目</div></a>'
            . '<a class="preset-card" href="/payments?' . htmlspecialchars($presetPaidAll, ENT_QUOTES) . '"><div class="preset-title">全部已收</div><div class="preset-desc">查看已完成收款记录</div></a>'
            . '</div>'
            . '</div>'
            . '</form></div></div>'
            . '<div class="card generator-panel mb-3"><div class="card-body">'
            . '<form class="row g-2 generator-form" method="POST" action="/payments/generate">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<div class="col-md-4"><label class="form-label">生成账期</label><input class="form-control" type="month" name="period" value="' . htmlspecialchars($period !== '' ? $period : date('Y-m')) . '" required></div>'
            . '<div class="col-md-4 d-grid align-self-end"><button class="btn btn-primary" type="submit">生成当期账单</button></div>'
            . '</form></div></div>'
            . '<div class="mb-2"><small class="text-muted">共 ' . $total . ' 条，当前第 ' . $currentPage . '/' . $lastPage . ' 页，每页 ' . $perPage . ' 条</small></div>'
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-striped mobile-table"><thead><tr>'
                . '<th>ID</th><th>支付编号</th><th>租客</th><th>房产</th><th>到期日</th><th>应付</th><th>状态</th><th>操作</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>'
            . '<div class="mt-3">' . $paginationHtml . '</div>'
                . '<script>(function(){var storageKey="easyrent:filters:payments:index";var form=document.getElementById("paymentsFilterForm");if(!form||!window.localStorage){return;}var fields=["keyword","period","period_from","period_to","status","amount_min","amount_max"];var hasQueryValues=false;var params=new URLSearchParams(window.location.search);for(var i=0;i<fields.length;i++){if(params.has(fields[i])&&params.get(fields[i])!==""){hasQueryValues=true;break;}}if(!hasQueryValues){try{var raw=localStorage.getItem(storageKey);if(raw){var saved=JSON.parse(raw);for(var j=0;j<fields.length;j++){var name=fields[j];var input=form.elements[name];if(!input){continue;}var currentValue=typeof input.value==="string"?input.value:"";if(currentValue!==""){continue;}if(Object.prototype.hasOwnProperty.call(saved,name)&&typeof saved[name]==="string"){input.value=saved[name];}}}}catch(e){}}form.addEventListener("submit",function(){var payload={};for(var k=0;k<fields.length;k++){var key=fields[k];var el=form.elements[key];if(!el){continue;}var value=typeof el.value==="string"?el.value.trim():"";if(value!==""){payload[key]=value;}}if(Object.keys(payload).length>0){localStorage.setItem(storageKey,JSON.stringify(payload));}else{localStorage.removeItem(storageKey);}});var resetLink=form.querySelector("a[data-filter-reset=\"payments\"]");if(resetLink){resetLink.addEventListener("click",function(){localStorage.removeItem(storageKey);});}})();</script>'
            . '</div></body></html>';
    }

    private function reconciliationTemplate(array $rows, array $filters, array $meterSummaryRows = []): string
    {
        $periodFrom = (string) ($filters['period_from'] ?? '');
        $periodTo = (string) ($filters['period_to'] ?? '');
        $keyword = (string) ($filters['keyword'] ?? '');
        $meterType = (string) ($filters['meter_type'] ?? '');
        $meterCode = (string) ($filters['meter_code'] ?? '');
        $unpaidOnly = (string) ($filters['unpaid_only'] ?? '') === '1';
        $liteMode = (string) ($filters['lite'] ?? '') === '1';
        $bom = (string) ($filters['bom'] ?? '');
        $sortBy = (string) ($filters['sort_by'] ?? 'payment_period');
        $sortDir = (string) ($filters['sort_dir'] ?? 'desc');

        $quick3m = $this->buildRecentPeriodLink(3, $keyword, $unpaidOnly, $sortBy, $sortDir, $liteMode, $meterType, $meterCode);
        $quick6m = $this->buildRecentPeriodLink(6, $keyword, $unpaidOnly, $sortBy, $sortDir, $liteMode, $meterType, $meterCode);
        $quick12m = $this->buildRecentPeriodLink(12, $keyword, $unpaidOnly, $sortBy, $sortDir, $liteMode, $meterType, $meterCode);
        $quickYear = $this->buildCurrentYearLink($keyword, $unpaidOnly, $sortBy, $sortDir, $liteMode, $meterType, $meterCode);
        $quick3mActive = $this->isRecentPeriodActive($periodFrom, $periodTo, 3);
        $quick6mActive = $this->isRecentPeriodActive($periodFrom, $periodTo, 6);
        $quick12mActive = $this->isRecentPeriodActive($periodFrom, $periodTo, 12);
        $quickYearActive = $this->isCurrentYearActive($periodFrom, $periodTo);

        $sortLinkPeriod = $this->buildReconciliationSortLink($filters, 'payment_period');
        $sortLinkUnpaid = $this->buildReconciliationSortLink($filters, 'unpaid_amount');
        $sortLinkPaidRate = $this->buildReconciliationSortLink($filters, 'paid_rate');

        $periodIndicator = $sortBy === 'payment_period' ? $this->getSortDirectionIndicator($sortDir) : '';
        $unpaidIndicator = $sortBy === 'unpaid_amount' ? $this->getSortDirectionIndicator($sortDir) : '';
        $paidRateIndicator = $sortBy === 'paid_rate' ? $this->getSortDirectionIndicator($sortDir) : '';

        $tableRows = '';
        $trendRows = [];
        $sumBills = 0;
        $sumReceivable = 0.0;
        $sumReceived = 0.0;
        $sumUnpaid = 0.0;
        $sumPaidCount = 0;

        foreach ($rows as $row) {
            $period = (string) ($row['payment_period'] ?? '');
            $billCount = (int) ($row['bill_count'] ?? 0);
            $receivable = (float) ($row['receivable_amount'] ?? 0);
            $received = (float) ($row['received_amount'] ?? 0);
            $unpaid = max(0.0, $receivable - $received);
            $paidCount = (int) ($row['paid_count'] ?? 0);
            $paidRate = $billCount > 0 ? round(($paidCount / $billCount) * 100, 2) : 0;

            $sumBills += $billCount;
            $sumReceivable += $receivable;
            $sumReceived += $received;
            $sumUnpaid += $unpaid;
            $sumPaidCount += $paidCount;

            $trendRows[] = [
                'period' => $period,
                'receivable' => $receivable,
                'received' => $received,
                'unpaid' => $unpaid,
            ];

            $unpaidCell = '¥' . number_format($unpaid, 2);
            if ($unpaid > 0) {
                $drillDownQuery = [
                    'period' => $period,
                    'status' => 'unpaid',
                    'keyword' => $keyword,
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'unpaid_only' => $unpaidOnly ? '1' : '',
                    'sort_by' => $sortBy !== 'payment_period' ? $sortBy : '',
                    'sort_dir' => $sortDir !== 'desc' ? $sortDir : '',
                    'source' => 'reconciliation',
                    'source_period' => $period,
                ];
                $drillDownQuery = array_filter($drillDownQuery, static function ($value): bool {
                    return (string) $value !== '';
                });

                $unpaidCell = '<a class="link-danger fw-semibold" href="/payments?' . htmlspecialchars(http_build_query($drillDownQuery), ENT_QUOTES) . '">' . $unpaidCell . '</a>';
            }

            $tableRows .= '<tr>'
                . '<td data-label="账期">' . htmlspecialchars($period) . '</td>'
                . '<td data-label="账单数">' . $billCount . '</td>'
                . '<td data-label="应收总额">¥' . number_format($receivable, 2) . '</td>'
                . '<td data-label="实收总额">¥' . number_format($received, 2) . '</td>'
                . '<td data-label="未收差额" class="' . ($unpaid > 0 ? 'text-danger fw-semibold' : 'text-success') . '">' . $unpaidCell . '</td>'
                . '<td data-label="收款完成度">' . $paidCount . '/' . $billCount . ' (' . number_format($paidRate, 2) . '%)</td>'
                . '</tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="6" class="text-center text-muted">暂无对账数据</td></tr>';
        }

        $totalPaidRate = $sumBills > 0 ? round(($sumPaidCount / $sumBills) * 100, 2) : 0;
        $trendSvg = $this->buildReconciliationTrendSvg($trendRows, $filters);
        $trendCardHtml = $liteMode
            ? '<div class="alert alert-secondary py-2 no-print">弱网优先模式已开启：趋势图已折叠，保留核心对账数据。</div>'
            : '<div class="card mb-3 trend-card"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0">近12个月趋势</h5><small class="text-muted">蓝线=应收，绿线=实收，红线=未收（可点击月份钻取）</small></div>' . $trendSvg . '</div></div>';
        $summaryRef = $this->buildReconciliationSummaryRef($keyword, $periodFrom, $periodTo, $unpaidOnly, $sortBy, $sortDir, $sumBills, $sumReceivable, $sumReceived, $sumUnpaid);
        $queryString = http_build_query([
            'keyword' => $keyword,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'meter_type' => $meterType,
            'meter_code' => $meterCode,
            'unpaid_only' => $unpaidOnly ? '1' : '',
            'lite' => $liteMode ? '1' : '',
            'sort_by' => $sortBy !== 'payment_period' ? $sortBy : '',
            'sort_dir' => $sortDir !== 'desc' ? $sortDir : '',
            'bom' => $bom,
            'summary_ref' => $summaryRef,
        ]);
        $printFilterParts = [];
        if ($keyword !== '') {
            $printFilterParts[] = '关键字：' . htmlspecialchars($keyword);
        }
        if ($periodFrom !== '' || $periodTo !== '') {
            $printFilterParts[] = '账期：' . htmlspecialchars($periodFrom !== '' ? $periodFrom : '不限') . ' ~ ' . htmlspecialchars($periodTo !== '' ? $periodTo : '不限');
        }
        if ($meterType !== '') {
            $printFilterParts[] = '表计类型：' . htmlspecialchars($this->meterTypeLabel($meterType));
        }
        if ($meterCode !== '') {
            $printFilterParts[] = '表计编号：' . htmlspecialchars($meterCode);
        }
        $printFilterParts[] = '排序：' . htmlspecialchars($this->getReconciliationSortLabel($sortBy, $sortDir));
        if ($printFilterParts === []) {
            $printFilterParts[] = '筛选：全部数据';
        }

        $meterSummaryTbody = '';
        foreach ($meterSummaryRows as $meterRow) {
            $meterSummaryTbody .= '<tr>'
                . '<td data-label="表计类型">' . htmlspecialchars($this->meterTypeLabel((string) ($meterRow['meter_type'] ?? ''))) . '</td>'
                . '<td data-label="表计编号">' . htmlspecialchars((string) ($meterRow['meter_code'] ?? '')) . '</td>'
                . '<td data-label="表计名称">' . htmlspecialchars((string) ($meterRow['meter_name'] ?? '-')) . '</td>'
                . '<td data-label="涉及账单" class="text-end">' . (int) ($meterRow['bill_count'] ?? 0) . '</td>'
                . '<td data-label="累计用量" class="text-end">' . number_format((float) ($meterRow['total_usage'] ?? 0), 2) . '</td>'
                . '<td data-label="累计费用" class="text-end">¥' . number_format((float) ($meterRow['total_fee'] ?? 0), 2) . '</td>'
                . '</tr>';
        }
        if ($meterSummaryTbody === '') {
            $meterSummaryTbody = '<tr><td colspan="6" class="text-center text-muted">当前筛选条件下暂无表计统计数据</td></tr>';
        }

        $printStyles = '<style>.copy-hint{display:inline-block;min-width:14em;opacity:0;transition:opacity .25s ease;}.copy-hint.show{opacity:1;}@media (max-width: 767.98px){.copy-shortcut-hint{display:none !important;}}@page{size:A4 landscape;margin:10mm;}@media print{.no-print{display:none !important;}.print-header{display:block !important;}.print-footer{display:flex !important;}body{background:#fff;}.container{max-width:100% !important;padding:0 !important;margin:0 !important;}.card{box-shadow:none !important;border:1px solid #dee2e6 !important;break-inside:avoid;}.trend-card{display:none !important;}.table{font-size:12px;}h3{font-size:20px;}.print-footer{position:fixed;left:0;right:0;bottom:0;padding:4mm 10mm;border-top:1px solid #dee2e6;font-size:11px;color:#6c757d;background:#fff;justify-content:space-between;}.print-page-number::after{content:counter(page);}}</style>';
        $mobileStyles = '<style>.report-page-head{background:linear-gradient(120deg,#eef6ff,#e6f7f2);color:#0f172a;border:1px solid #d8e7f8;border-radius:1rem;padding:1rem 1.1rem;margin-bottom:.9rem;}.report-page-head .subtitle{color:#334155;margin:.25rem 0 0;font-size:.9rem;}.report-filter-panel .card-body{padding:.8rem .85rem;}.report-filter-form .form-label{font-size:.78rem;color:#64748b;margin-bottom:.2rem;}.report-filter-form .form-select,.report-filter-form .form-control{border-color:#cbd5e1;}.report-filter-form .form-select:focus,.report-filter-form .form-control:focus{border-color:#0f766e;box-shadow:0 0 0 .2rem rgba(15,118,110,.12);}@media (max-width: 767.98px){.report-page-head{padding:.85rem .9rem;}.report-page-head .subtitle{font-size:.82rem;}.report-filter-panel .card-body{padding:.65rem;}.reconciliation-toolbar{width:100%;display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem;}.reconciliation-toolbar .btn{flex:1 1 calc(50% - .5rem);} .reconciliation-toolbar .badge{width:100%;order:-1;} .mobile-table thead{display:none;} .mobile-table tbody,.mobile-table tr,.mobile-table td{display:block;width:100%;} .mobile-table tr{border:1px solid #dee2e6;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.75rem;background:#fff;} .mobile-table td{border:0 !important;padding:.2rem 0 .2rem 8rem;position:relative;white-space:normal;} .mobile-table td::before{content:attr(data-label);position:absolute;left:0;top:.2rem;width:7.5rem;color:#6c757d;font-weight:600;font-size:.85rem;} .mobile-table td[colspan]{padding-left:0;text-align:center;} .mobile-table td[colspan]::before{display:none;}}</style>';
        $printHeader = '<div class="print-header mb-3" style="display:none;"><h4 class="mb-1">月度对账报表</h4><div class="text-muted">打印时间：' . date('Y-m-d H:i') . '</div><div class="text-muted">摘要编号：<span class="summary-ref">' . htmlspecialchars($summaryRef) . '</span></div><div class="text-muted">' . implode(' ｜ ', $printFilterParts) . '</div></div>';
        $printFooter = '<div class="print-footer" style="display:none;"><span>收租管理系统 · 对账打印</span><span>导出时间：' . date('Y-m-d H:i') . '</span><span>页码：<span class="print-page-number"></span></span></div>';
        $copyScript = '<script>(function(){const btn=document.getElementById("copySummaryRefBtn");const ref=document.querySelector(".summary-ref-inline");const hint=document.getElementById("copySummaryRefHint");if(!btn||!ref||!hint){return;}let clearTimer=null;const setHint=function(text,colorClass){if(clearTimer!==null){clearTimeout(clearTimer);clearTimer=null;}if(text===""){hint.className="small copy-hint text-muted";clearTimer=setTimeout(function(){hint.textContent="";},260);return;}hint.textContent=text;hint.className="small copy-hint show "+colorClass;};const doCopy=async function(){const text=(ref.textContent||"").trim();if(text===""){return;}try{if(navigator.clipboard&&navigator.clipboard.writeText){await navigator.clipboard.writeText(text);}else{const ta=document.createElement("textarea");ta.value=text;document.body.appendChild(ta);ta.select();document.execCommand("copy");ta.remove();}btn.textContent="已复制";setHint("已复制到剪贴板","text-success");setTimeout(function(){btn.textContent="复制";setHint("","text-muted");},1400);}catch(e){btn.textContent="复制失败";setHint("复制失败，请手动复制","text-danger");setTimeout(function(){btn.textContent="复制";setHint("","text-muted");},1600);}};btn.addEventListener("click",doCopy);document.addEventListener("keydown",function(e){if(e.altKey&&e.shiftKey&&(e.key==="C"||e.key==="c")){e.preventDefault();doCopy();setHint("已通过快捷键 Alt+Shift+C 触发复制","text-info");}});})();</script>';

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>月度对账</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css">' . $printStyles . $mobileStyles . '</head><body>'
            . '<div class="container mt-4">'
            . '<div class="report-page-head">'
            . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h3 class="mb-0">月度对账</h3><div class="d-flex gap-2 align-items-center no-print reconciliation-toolbar"><span class="badge text-bg-light border">摘要编号：<span class="summary-ref-inline">' . htmlspecialchars($summaryRef) . '</span></span><button type="button" class="btn btn-sm btn-outline-secondary" id="copySummaryRefBtn" aria-label="复制摘要编号" title="复制摘要编号（Alt+Shift+C）">复制</button><span class="small text-muted copy-shortcut-hint">快捷键 Alt+Shift+C</span><span id="copySummaryRefHint" class="small copy-hint text-muted" role="status" aria-live="polite" aria-atomic="true"></span><button type="button" class="btn btn-outline-dark" onclick="window.print()">打印</button><a href="/payments/reconciliation/export?' . htmlspecialchars($queryString, ENT_QUOTES) . '" class="btn btn-outline-success">导出CSV</a><a href="/payments" class="btn btn-outline-secondary">返回账单列表</a><a href="/dashboard" class="btn btn-outline-secondary">返回仪表板</a></div></div>'
            . '<p class="subtitle">按账期查看应收、实收与未收差额，支持趋势分析、排序钻取与打印导出。</p>'
            . '</div>'
            . $printHeader
            . '<div class="card report-filter-panel mb-3 no-print"><div class="card-body">'
            . '<form class="row g-2 report-filter-form" method="GET" action="/payments/reconciliation">'
            . '<input type="hidden" name="bom" value="' . htmlspecialchars($bom) . '">'
            . '<div class="col-md-3"><label class="form-label">关键词</label><input class="form-control" type="search" name="keyword" placeholder="租客/房号/房产名" value="' . htmlspecialchars($keyword) . '"></div>'
            . '<div class="col-md-2"><label class="form-label">起始账期</label><input class="form-control" type="month" name="period_from" value="' . htmlspecialchars($periodFrom) . '"></div>'
            . '<div class="col-md-2"><label class="form-label">结束账期</label><input class="form-control" type="month" name="period_to" value="' . htmlspecialchars($periodTo) . '"></div>'
            . '<div class="col-md-2"><label class="form-label">表计类型</label><select class="form-select" name="meter_type"><option value="">全部</option>' . $this->buildMeterTypeSelectOptionsHtml($meterType) . '</select></div>'
            . '<div class="col-md-2"><label class="form-label">表计编号</label><input class="form-control" type="text" name="meter_code" placeholder="支持模糊匹配" value="' . htmlspecialchars($meterCode) . '"></div>'
            . '<div class="col-md-2"><label class="form-label">排序字段</label><select class="form-select" name="sort_by"><option value="payment_period"' . ($sortBy === 'payment_period' ? ' selected' : '') . '>按账期</option><option value="unpaid_amount"' . ($sortBy === 'unpaid_amount' ? ' selected' : '') . '>按未收差额</option><option value="paid_rate"' . ($sortBy === 'paid_rate' ? ' selected' : '') . '>按收款率</option></select></div>'
            . '<div class="col-md-1"><label class="form-label">方向</label><select class="form-select" name="sort_dir"><option value="desc"' . ($sortDir === 'desc' ? ' selected' : '') . '>降序</option><option value="asc"' . ($sortDir === 'asc' ? ' selected' : '') . '>升序</option></select></div>'
            . '<div class="col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="unpaidOnly" name="unpaid_only" value="1"' . ($unpaidOnly ? ' checked' : '') . '><label class="form-check-label" for="unpaidOnly">仅看有未收差额</label></div></div>'
            . '<div class="col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="liteModeReconciliation" name="lite" value="1"' . ($liteMode ? ' checked' : '') . '><label class="form-check-label" for="liteModeReconciliation">弱网优先模式</label></div></div>'
            . '<div class="col-md-3 d-flex gap-2"><button class="btn btn-outline-primary flex-fill" type="submit">筛选</button><a class="btn btn-outline-secondary flex-fill" href="/payments/reconciliation">重置</a></div>'
            . '</form></div></div>'
            . '<div class="small text-muted mb-2 no-print">排序说明：同值时按账期降序兜底。</div>'
            . '<div class="mb-3 d-flex flex-wrap gap-2 no-print">'
            . '<a class="btn btn-sm ' . ($quick3mActive ? 'btn-primary' : 'btn-outline-primary') . '" href="' . htmlspecialchars($quick3m, ENT_QUOTES) . '">近3个月</a>'
            . '<a class="btn btn-sm ' . ($quick6mActive ? 'btn-primary' : 'btn-outline-primary') . '" href="' . htmlspecialchars($quick6m, ENT_QUOTES) . '">近6个月</a>'
            . '<a class="btn btn-sm ' . ($quick12mActive ? 'btn-primary' : 'btn-outline-primary') . '" href="' . htmlspecialchars($quick12m, ENT_QUOTES) . '">近12个月</a>'
            . '<a class="btn btn-sm ' . ($quickYearActive ? 'btn-primary' : 'btn-outline-primary') . '" href="' . htmlspecialchars($quickYear, ENT_QUOTES) . '">本年</a>'
            . '</div>'
            . '<div class="row g-2 mb-3">'
            . '<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">账单总数</div><div class="fs-5 fw-semibold">' . $sumBills . '</div></div></div></div>'
            . '<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">应收总额</div><div class="fs-5 fw-semibold">¥' . number_format($sumReceivable, 2) . '</div></div></div></div>'
            . '<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">实收总额</div><div class="fs-5 fw-semibold text-success">¥' . number_format($sumReceived, 2) . '</div></div></div></div>'
            . '<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">收款率</div><div class="fs-5 fw-semibold">' . number_format($totalPaidRate, 2) . '%</div></div></div></div>'
            . '</div>'
            . '<div class="card mb-3"><div class="card-body table-responsive"><div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0">表计维度汇总</h5><small class="text-muted">按表计编号聚合用量与费用</small></div><table class="table table-striped mobile-table"><thead><tr><th>表计类型</th><th>表计编号</th><th>表计名称</th><th class="text-end">涉及账单</th><th class="text-end">累计用量</th><th class="text-end">累计费用</th></tr></thead><tbody>' . $meterSummaryTbody . '</tbody></table></div></div>'
            . $trendCardHtml
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-striped mobile-table"><thead><tr>'
            . '<th><a class="link-dark text-decoration-none" href="' . htmlspecialchars($sortLinkPeriod, ENT_QUOTES) . '">账期' . $periodIndicator . '</a></th>'
            . '<th>账单数</th>'
            . '<th>应收总额</th>'
            . '<th>实收总额</th>'
            . '<th><a class="link-dark text-decoration-none" href="' . htmlspecialchars($sortLinkUnpaid, ENT_QUOTES) . '">未收差额' . $unpaidIndicator . '</a></th>'
            . '<th><a class="link-dark text-decoration-none" href="' . htmlspecialchars($sortLinkPaidRate, ENT_QUOTES) . '">收款完成度' . $paidRateIndicator . '</a></th>'
            . '</tr></thead><tbody>' . $tableRows . '</tbody></table></div></div>'
            . $printFooter
            . $copyScript
            . '</div></body></html>';
    }

    private function financialReportTemplate(array $dataset, array $filters, bool $personal): string
    {
        $summary = is_array($dataset['summary'] ?? null) ? $dataset['summary'] : [];
        $monthlyRows = is_array($dataset['monthly_rows'] ?? null) ? $dataset['monthly_rows'] : [];
        $trendRows = is_array($dataset['trend_rows'] ?? null) ? $dataset['trend_rows'] : [];
        $categoryRows = is_array($dataset['category_rows'] ?? null) ? $dataset['category_rows'] : [];
        $methodRows = is_array($dataset['method_rows'] ?? null) ? $dataset['method_rows'] : [];
        $overdueRows = is_array($dataset['overdue_rows'] ?? null) ? $dataset['overdue_rows'] : [];
        $propertyRows = is_array($dataset['property_rows'] ?? null) ? $dataset['property_rows'] : [];
        $tenantRows = is_array($dataset['tenant_rows'] ?? null) ? $dataset['tenant_rows'] : [];
        $contractRows = is_array($dataset['contract_rows'] ?? null) ? $dataset['contract_rows'] : [];

        $periodFrom = (string) ($filters['period_from'] ?? '');
        $periodTo = (string) ($filters['period_to'] ?? '');
        $keyword = (string) ($filters['keyword'] ?? '');
        $method = (string) ($filters['method'] ?? '');
        $overdueBucket = (string) ($filters['overdue_bucket'] ?? '');
        $liteMode = (string) ($filters['lite'] ?? '') === '1';
        $bom = (string) ($filters['bom'] ?? '');

        $reportTitle = $personal ? '我的报表' : '财务报表';
        $basePath = $personal ? '/reports/personal' : '/reports/financial';
        $exportPath = $personal ? '/reports/personal/export' : '/reports/financial/export';
        $queryString = http_build_query([
            'keyword' => $keyword,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'method' => $method,
            'overdue_bucket' => $overdueBucket,
            'lite' => $liteMode ? '1' : '',
            'bom' => $bom,
        ]);

        $monthlyTbody = '';
        foreach ($monthlyRows as $row) {
            $monthlyTbody .= '<tr>'
                . '<td data-label="账期">' . htmlspecialchars((string) ($row['period'] ?? '')) . '</td>'
                . '<td data-label="账单数">' . (int) ($row['bill_count'] ?? 0) . '</td>'
                . '<td data-label="应收总额">¥' . number_format((float) ($row['total_due'] ?? 0), 2) . '</td>'
                . '<td data-label="实收总额">¥' . number_format((float) ($row['total_paid'] ?? 0), 2) . '</td>'
                . '<td data-label="未收总额">¥' . number_format((float) ($row['total_unpaid'] ?? 0), 2) . '</td>'
                . '<td data-label="收款率">' . number_format((float) ($row['paid_rate'] ?? 0), 2) . '%</td>'
                . '</tr>';
        }
        if ($monthlyTbody === '') {
            $monthlyTbody = '<tr><td colspan="6" class="text-center text-muted">当前筛选条件下暂无数据</td></tr>';
        }

        $propertyTbody = '';
        foreach ($propertyRows as $row) {
            $propertyTbody .= '<tr>'
                . '<td data-label="房产">' . htmlspecialchars((string) ($row['property_name'] ?? '')) . '</td>'
                . '<td data-label="账单数">' . (int) ($row['bill_count'] ?? 0) . '</td>'
                . '<td data-label="应收总额">¥' . number_format((float) ($row['total_due'] ?? 0), 2) . '</td>'
                . '<td data-label="实收总额">¥' . number_format((float) ($row['total_paid'] ?? 0), 2) . '</td>'
                . '</tr>';
        }
        if ($propertyTbody === '') {
            $propertyTbody = '<tr><td colspan="4" class="text-center text-muted">暂无房产汇总数据</td></tr>';
        }

        $tenantTbody = '';
        foreach ($tenantRows as $row) {
            $tenantTbody .= '<tr>'
                . '<td data-label="租客">' . htmlspecialchars((string) ($row['tenant_name'] ?? '')) . '</td>'
                . '<td data-label="账单数">' . (int) ($row['bill_count'] ?? 0) . '</td>'
                . '<td data-label="应收总额">¥' . number_format((float) ($row['total_due'] ?? 0), 2) . '</td>'
                . '<td data-label="实收总额">¥' . number_format((float) ($row['total_paid'] ?? 0), 2) . '</td>'
                . '</tr>';
        }
        if ($tenantTbody === '') {
            $tenantTbody = '<tr><td colspan="4" class="text-center text-muted">暂无租客汇总数据</td></tr>';
        }

        $contractTbody = '';
        foreach ($contractRows as $row) {
            $contractTbody .= '<tr>'
                . '<td data-label="合同编号">' . htmlspecialchars((string) ($row['contract_number'] ?? '')) . '</td>'
                . '<td data-label="租客">' . htmlspecialchars((string) ($row['tenant_name'] ?? '')) . '</td>'
                . '<td data-label="账单数">' . (int) ($row['bill_count'] ?? 0) . '</td>'
                . '<td data-label="应收总额">¥' . number_format((float) ($row['total_due'] ?? 0), 2) . '</td>'
                . '<td data-label="实收总额">¥' . number_format((float) ($row['total_paid'] ?? 0), 2) . '</td>'
                . '</tr>';
        }
        if ($contractTbody === '') {
            $contractTbody = '<tr><td colspan="5" class="text-center text-muted">暂无合同汇总数据</td></tr>';
        }

        $categoryTbody = '';
        foreach ($categoryRows as $row) {
            $categoryTbody .= '<tr>'
                . '<td data-label="分类">' . htmlspecialchars((string) ($row['category'] ?? '')) . '</td>'
                . '<td data-label="金额">¥' . number_format((float) ($row['amount'] ?? 0), 2) . '</td>'
                . '<td data-label="占比">' . number_format((float) ($row['ratio'] ?? 0), 2) . '%</td>'
                . '</tr>';
        }
        if ($categoryTbody === '') {
            $categoryTbody = '<tr><td colspan="3" class="text-center text-muted">暂无分类数据</td></tr>';
        }

        $methodTbody = '';
        foreach ($methodRows as $row) {
            $methodTbody .= '<tr>'
                . '<td data-label="支付方式">' . htmlspecialchars($this->paymentMethodLabel((string) ($row['method'] ?? 'other'))) . '</td>'
                . '<td data-label="账单数">' . (int) ($row['bill_count'] ?? 0) . '</td>'
                . '<td data-label="实收金额">¥' . number_format((float) ($row['paid_amount'] ?? 0), 2) . '</td>'
                . '</tr>';
        }
        if ($methodTbody === '') {
            $methodTbody = '<tr><td colspan="3" class="text-center text-muted">暂无支付方式统计</td></tr>';
        }

        $overdueTbody = '';
        foreach ($overdueRows as $row) {
            $overdueTbody .= '<tr>'
                . '<td data-label="分层">' . htmlspecialchars((string) ($row['bucket'] ?? '')) . '</td>'
                . '<td data-label="账单数">' . (int) ($row['count'] ?? 0) . '</td>'
                . '<td data-label="未收金额">¥' . number_format((float) ($row['amount'] ?? 0), 2) . '</td>'
                . '</tr>';
        }
        if ($overdueTbody === '') {
            $overdueTbody = '<tr><td colspan="3" class="text-center text-muted">暂无逾期分层数据</td></tr>';
        }

        $trendSvg = $this->buildFinancialTrendSvg($trendRows);
        $liteHintHtml = $liteMode
            ? '<div class="alert alert-secondary py-2">弱网优先模式已开启：趋势图与扩展维度区块已折叠，保留核心摘要与月度汇总。</div>'
            : '';
        $trendCardHtml = $liteMode
            ? ''
            : '<div class="card mt-3"><div class="card-header bg-white fw-semibold">近12个月收支趋势</div><div class="card-body">' . $trendSvg . '</div></div>';
        $extendedBlocksHtml = $liteMode
            ? ''
            : '<div class="row g-3 mt-1">'
                . '<div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">支付方式统计</div><div class="card-body table-responsive"><table class="table table-sm align-middle mobile-table"><thead><tr><th>支付方式</th><th>账单数</th><th>实收金额</th></tr></thead><tbody>' . $methodTbody . '</tbody></table></div></div></div>'
                . '<div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">逾期账单分层</div><div class="card-body table-responsive"><table class="table table-sm align-middle mobile-table"><thead><tr><th>分层</th><th>账单数</th><th>未收金额</th></tr></thead><tbody>' . $overdueTbody . '</tbody></table></div></div></div>'
                . '</div>'
                . '<div class="card mt-3"><div class="card-header bg-white fw-semibold">房产维度汇总（按应收降序）</div><div class="card-body table-responsive"><table class="table table-sm align-middle mobile-table"><thead><tr><th>房产</th><th>账单数</th><th>应收总额</th><th>实收总额</th></tr></thead><tbody>' . $propertyTbody . '</tbody></table></div></div>'
                . '<div class="row g-3 mt-1">'
                . '<div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">租客维度汇总（按应收降序）</div><div class="card-body table-responsive"><table class="table table-sm align-middle mobile-table"><thead><tr><th>租客</th><th>账单数</th><th>应收总额</th><th>实收总额</th></tr></thead><tbody>' . $tenantTbody . '</tbody></table></div></div></div>'
                . '<div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">合同维度汇总（按应收降序）</div><div class="card-body table-responsive"><table class="table table-sm align-middle mobile-table"><thead><tr><th>合同编号</th><th>租客</th><th>账单数</th><th>应收总额</th><th>实收总额</th></tr></thead><tbody>' . $contractTbody . '</tbody></table></div></div></div>'
                . '</div>';
        $mobileSummaryCards = '<div class="mobile-report-summary d-md-none mb-3">'
            . '<div class="summary-title">核心摘要</div>'
            . '<div class="row g-2">'
            . '<div class="col-6"><div class="card"><div class="card-body"><div class="summary-label">应收总额</div><div class="summary-value text-primary">¥' . number_format((float) ($summary['total_due'] ?? 0), 2) . '</div></div></div></div>'
            . '<div class="col-6"><div class="card"><div class="card-body"><div class="summary-label">实收总额</div><div class="summary-value text-success">¥' . number_format((float) ($summary['total_paid'] ?? 0), 2) . '</div></div></div></div>'
            . '<div class="col-6"><div class="card"><div class="card-body"><div class="summary-label">未收总额</div><div class="summary-value text-danger">¥' . number_format((float) ($summary['total_unpaid'] ?? 0), 2) . '</div></div></div></div>'
            . '<div class="col-6"><div class="card"><div class="card-body"><div class="summary-label">收款率</div><div class="summary-value">' . number_format((float) ($summary['paid_rate'] ?? 0), 2) . '%</div></div></div></div>'
            . '<div class="col-6"><div class="card"><div class="card-body"><div class="summary-label">支出总额</div><div class="summary-value text-warning">¥' . number_format((float) ($summary['expense_total'] ?? 0), 2) . '</div></div></div></div>'
            . '<div class="col-6"><div class="card"><div class="card-body"><div class="summary-label">实际盈利</div><div class="summary-value ' . (((float) ($summary['net_profit'] ?? 0)) >= 0 ? 'text-success' : 'text-danger') . '">¥' . number_format((float) ($summary['net_profit'] ?? 0), 2) . '</div></div></div></div>'
            . '</div>'
            . '<div class="d-flex gap-2 mt-2 flex-wrap">'
            . '<span class="badge text-bg-light border summary-chip">账单数 ' . number_format((int) ($summary['bill_count'] ?? 0)) . '</span>'
            . '<span class="badge text-bg-light border summary-chip">逾期账单 ' . number_format((int) ($summary['overdue_count'] ?? 0)) . '</span>'
            . '</div>'
            . '</div>';

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $reportTitle . '</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><style>.report-page-head{background:linear-gradient(120deg,#eef6ff,#e6f7f2);color:#0f172a;border:1px solid #d8e7f8;border-radius:1rem;padding:1rem 1.1rem;margin-bottom:.9rem;}.report-page-head .subtitle{color:#334155;margin:.25rem 0 0;font-size:.9rem;}.report-filter-panel .card-body{padding:.8rem .85rem;}.report-filter-form .form-label{font-size:.78rem;color:#64748b;margin-bottom:.2rem;}.report-filter-form .form-select,.report-filter-form .form-control{border-color:#cbd5e1;}.report-filter-form .form-select:focus,.report-filter-form .form-control:focus{border-color:#0f766e;box-shadow:0 0 0 .2rem rgba(15,118,110,.12);}.stat-card{border:none;border-radius:14px;box-shadow:0 6px 20px rgba(15,23,42,.08);}.muted-label{font-size:12px;color:#6c757d;}.breakdown-list .list-group-item{display:flex;justify-content:space-between;align-items:center;}.mobile-report-summary .summary-title{font-size:.9rem;font-weight:600;color:#6c757d;margin-bottom:.5rem;}.mobile-report-summary .card{border:none;box-shadow:0 4px 12px rgba(15,23,42,.08);}.mobile-report-summary .summary-label{font-size:.75rem;color:#6c757d;}.mobile-report-summary .summary-value{font-size:1rem;font-weight:700;}.mobile-report-summary .summary-chip{font-weight:500;}@media (max-width: 767.98px){.report-page-head{padding:.85rem .9rem;}.report-page-head .subtitle{font-size:.82rem;}.report-filter-panel .card-body{padding:.65rem;}.report-toolbar{width:100%;display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem;}.report-toolbar .btn{flex:1 1 calc(50% - .5rem);} .mobile-table thead{display:none;} .mobile-table tbody,.mobile-table tr,.mobile-table td{display:block;width:100%;} .mobile-table tr{border:1px solid #dee2e6;border-radius:.5rem;padding:.5rem .75rem;margin-bottom:.75rem;background:#fff;} .mobile-table td{border:0 !important;padding:.2rem 0 .2rem 8rem;position:relative;white-space:normal;} .mobile-table td::before{content:attr(data-label);position:absolute;left:0;top:.2rem;width:7.5rem;color:#6c757d;font-weight:600;font-size:.85rem;} .mobile-table td[colspan]{padding-left:0;text-align:center;} .mobile-table td[colspan]::before{display:none;}}</style></head><body>'
            . '<div class="container mt-4">'
            . '<div class="report-page-head">'
            . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h3 class="mb-0">' . $reportTitle . '</h3><div class="d-flex gap-2 report-toolbar"><a href="' . $exportPath . '?' . htmlspecialchars($queryString, ENT_QUOTES) . '" class="btn btn-outline-success">导出CSV</a><a href="/expenses" class="btn btn-outline-warning">支出记录</a><a href="/payments/reconciliation" class="btn btn-outline-dark">月度对账</a><a href="/dashboard" class="btn btn-outline-secondary">返回仪表板</a></div></div>'
            . '<p class="subtitle">按账期和筛选维度分析收支结构、逾期分层与趋势变化，支持明细导出与移动端查看。</p>'
            . '</div>'
            . '<div class="card report-filter-panel mb-3"><div class="card-body">'
            . '<form class="row g-2 report-filter-form" method="GET" action="' . $basePath . '">'
            . '<input type="hidden" name="bom" value="' . htmlspecialchars($bom) . '">'
            . '<div class="col-md-3"><label class="form-label">关键词</label><input class="form-control" type="search" name="keyword" placeholder="租客/房产/房号关键字" value="' . htmlspecialchars($keyword) . '"></div>'
            . '<div class="col-md-2"><label class="form-label">起始账期</label><input class="form-control" type="month" name="period_from" value="' . htmlspecialchars($periodFrom) . '" title="起始账期"></div>'
            . '<div class="col-md-2"><label class="form-label">结束账期</label><input class="form-control" type="month" name="period_to" value="' . htmlspecialchars($periodTo) . '" title="结束账期"></div>'
            . '<div class="col-md-2"><label class="form-label">支付方式</label><select class="form-select" name="method">'
            . '<option value="">全部支付方式</option>'
            . '<option value="bank_transfer"' . ($method === 'bank_transfer' ? ' selected' : '') . '>银行转账</option>'
            . '<option value="cash"' . ($method === 'cash' ? ' selected' : '') . '>现金</option>'
            . '<option value="alipay"' . ($method === 'alipay' ? ' selected' : '') . '>支付宝</option>'
            . '<option value="wechat_pay"' . ($method === 'wechat_pay' ? ' selected' : '') . '>微信支付</option>'
            . '<option value="other"' . ($method === 'other' ? ' selected' : '') . '>其他</option>'
            . '</select></div>'
            . '<div class="col-md-2"><label class="form-label">逾期层级</label><select class="form-select" name="overdue_bucket">'
            . '<option value="">全部逾期层级</option>'
            . '<option value="none"' . ($overdueBucket === 'none' ? ' selected' : '') . '>仅无逾期</option>'
            . '<option value="not_due"' . ($overdueBucket === 'not_due' ? ' selected' : '') . '>未逾期</option>'
            . '<option value="1_7"' . ($overdueBucket === '1_7' ? ' selected' : '') . '>逾期1-7天</option>'
            . '<option value="8_30"' . ($overdueBucket === '8_30' ? ' selected' : '') . '>逾期8-30天</option>'
            . '<option value="31_plus"' . ($overdueBucket === '31_plus' ? ' selected' : '') . '>逾期31天以上</option>'
            . '</select></div>'
            . '<div class="col-md-2 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="liteModeFinancial" name="lite" value="1"' . ($liteMode ? ' checked' : '') . '><label class="form-check-label" for="liteModeFinancial">弱网优先</label></div></div>'
            . '<div class="col-md-1 d-grid"><button class="btn btn-primary" type="submit">筛选</button></div>'
            . '<div class="col-md-1 d-grid"><a class="btn btn-outline-secondary" href="' . $basePath . '">重置</a></div>'
            . '</form></div></div>'
            . $mobileSummaryCards
            . $liteHintHtml
            . '<div class="row g-3 mb-3">'
            . '<div class="col-md-2"><div class="card stat-card"><div class="card-body"><div class="muted-label">账单总数</div><div class="h4 mb-0">' . number_format((int) ($summary['bill_count'] ?? 0)) . '</div></div></div></div>'
            . '<div class="col-md-2"><div class="card stat-card"><div class="card-body"><div class="muted-label">应收总额</div><div class="h4 mb-0 text-primary">¥' . number_format((float) ($summary['total_due'] ?? 0), 2) . '</div></div></div></div>'
            . '<div class="col-md-2"><div class="card stat-card"><div class="card-body"><div class="muted-label">实收总额</div><div class="h4 mb-0 text-success">¥' . number_format((float) ($summary['total_paid'] ?? 0), 2) . '</div></div></div></div>'
            . '<div class="col-md-2"><div class="card stat-card"><div class="card-body"><div class="muted-label">未收总额 / 收款率</div><div class="h5 mb-1 text-danger">¥' . number_format((float) ($summary['total_unpaid'] ?? 0), 2) . '</div><div class="small text-muted">收款率 ' . number_format((float) ($summary['paid_rate'] ?? 0), 2) . '%</div></div></div></div>'
            . '<div class="col-md-2"><div class="card stat-card"><div class="card-body"><div class="muted-label">支出总额</div><div class="h4 mb-0 text-warning">¥' . number_format((float) ($summary['expense_total'] ?? 0), 2) . '</div></div></div></div>'
            . '<div class="col-md-2"><div class="card stat-card"><div class="card-body"><div class="muted-label">实际盈利</div><div class="h4 mb-0 ' . (((float) ($summary['net_profit'] ?? 0)) >= 0 ? 'text-success' : 'text-danger') . '">¥' . number_format((float) ($summary['net_profit'] ?? 0), 2) . '</div></div></div></div>'
            . '</div>'
            . '<div class="row g-3">'
            . '<div class="col-lg-8"><div class="card"><div class="card-header bg-white fw-semibold">月度收入汇总</div><div class="card-body table-responsive"><table class="table table-sm align-middle mobile-table"><thead><tr><th>账期</th><th>账单数</th><th>应收总额</th><th>实收总额</th><th>未收总额</th><th>收款率</th></tr></thead><tbody>' . $monthlyTbody . '</tbody></table></div></div></div>'
            . '<div class="col-lg-4"><div class="card"><div class="card-header bg-white fw-semibold">费用构成</div><div class="card-body"><ul class="list-group breakdown-list">'
            . '<li class="list-group-item"><span>固定租金</span><strong>¥' . number_format((float) ($summary['rent_total'] ?? 0), 2) . '</strong></li>'
            . '<li class="list-group-item"><span>水费</span><strong>¥' . number_format((float) ($summary['water_total'] ?? 0), 2) . '</strong></li>'
            . '<li class="list-group-item"><span>电费</span><strong>¥' . number_format((float) ($summary['electric_total'] ?? 0), 2) . '</strong></li>'
            . '<li class="list-group-item"><span>其他/历史数据</span><strong>¥' . number_format((float) ($summary['other_total'] ?? 0), 2) . '</strong></li>'
            . '<li class="list-group-item"><span>支出总额</span><strong class="text-warning">¥' . number_format((float) ($summary['expense_total'] ?? 0), 2) . '</strong></li>'
            . '<li class="list-group-item"><span>实际盈利</span><strong class="' . (((float) ($summary['net_profit'] ?? 0)) >= 0 ? 'text-success' : 'text-danger') . '">¥' . number_format((float) ($summary['net_profit'] ?? 0), 2) . '</strong></li>'
            . '<li class="list-group-item"><span>逾期账单数</span><strong>' . number_format((int) ($summary['overdue_count'] ?? 0)) . '</strong></li>'
            . '</ul></div></div></div>'
            . '</div>'
            . $trendCardHtml
            . '<div class="card mt-3"><div class="card-header bg-white fw-semibold">分类汇总（按金额降序）</div><div class="card-body table-responsive"><table class="table table-sm align-middle mobile-table"><thead><tr><th>分类</th><th>金额</th><th>占比</th></tr></thead><tbody>' . $categoryTbody . '</tbody></table></div></div>'
            . $extendedBlocksHtml
            . '</div></body></html>';
    }

    private function buildFinancialTrendSvg(array $rows): string
    {
        if (count($rows) === 0) {
            return '<div class="text-muted">暂无趋势数据</div>';
        }

        $width = 900;
        $height = 240;
        $paddingTop = 16;
        $paddingRight = 16;
        $paddingBottom = 40;
        $paddingLeft = 52;

        $innerWidth = $width - $paddingLeft - $paddingRight;
        $innerHeight = $height - $paddingTop - $paddingBottom;

        $maxValue = 0.0;
        foreach ($rows as $row) {
            $maxValue = max(
                $maxValue,
                (float) ($row['total_due'] ?? 0),
                (float) ($row['total_paid'] ?? 0),
                (float) ($row['total_unpaid'] ?? 0)
            );
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }

        $count = count($rows);
        $stepX = $count > 1 ? $innerWidth / ($count - 1) : 0;

        $dueLine = [];
        $paidLine = [];
        $unpaidLine = [];
        $labels = '';

        foreach ($rows as $i => $row) {
            $x = $paddingLeft + ($stepX * $i);
            $due = (float) ($row['total_due'] ?? 0);
            $paid = (float) ($row['total_paid'] ?? 0);
            $unpaid = (float) ($row['total_unpaid'] ?? 0);

            $yDue = $paddingTop + ($innerHeight * (1 - ($due / $maxValue)));
            $yPaid = $paddingTop + ($innerHeight * (1 - ($paid / $maxValue)));
            $yUnpaid = $paddingTop + ($innerHeight * (1 - ($unpaid / $maxValue)));

            $dueLine[] = number_format($x, 2, '.', '') . ',' . number_format($yDue, 2, '.', '');
            $paidLine[] = number_format($x, 2, '.', '') . ',' . number_format($yPaid, 2, '.', '');
            $unpaidLine[] = number_format($x, 2, '.', '') . ',' . number_format($yUnpaid, 2, '.', '');

            $labels .= '<text x="' . number_format($x, 2, '.', '') . '" y="' . ($height - 12) . '" text-anchor="middle" font-size="10" fill="#6c757d">' . htmlspecialchars((string) ($row['period'] ?? '')) . '</text>';
        }

        $yGrid = '';
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $ratio) {
            $value = $maxValue * $ratio;
            $y = $paddingTop + ($innerHeight * (1 - $ratio));
            $yGrid .= '<line x1="' . $paddingLeft . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . ($paddingLeft + $innerWidth) . '" y2="' . number_format($y, 2, '.', '') . '" stroke="#e9ecef" stroke-width="1" />';
            $yGrid .= '<text x="' . ($paddingLeft - 8) . '" y="' . number_format($y + 4, 2, '.', '') . '" text-anchor="end" font-size="11" fill="#6c757d">' . htmlspecialchars(number_format($value, 0)) . '</text>';
        }

        return '<div class="mb-2 small text-muted">蓝线=应收，绿线=实收，红线=未收</div>'
            . '<svg id="financialTrendChart" viewBox="0 0 ' . $width . ' ' . $height . '" class="w-100" role="img" aria-label="财务收支趋势图">'
            . '<line x1="' . $paddingLeft . '" y1="' . ($paddingTop + $innerHeight) . '" x2="' . ($paddingLeft + $innerWidth) . '" y2="' . ($paddingTop + $innerHeight) . '" stroke="#adb5bd" stroke-width="1" />'
            . '<line x1="' . $paddingLeft . '" y1="' . $paddingTop . '" x2="' . $paddingLeft . '" y2="' . ($paddingTop + $innerHeight) . '" stroke="#adb5bd" stroke-width="1" />'
            . $yGrid
            . '<polyline fill="none" stroke="#0d6efd" stroke-width="2.5" points="' . implode(' ', $dueLine) . '" />'
            . '<polyline fill="none" stroke="#198754" stroke-width="2.5" points="' . implode(' ', $paidLine) . '" />'
            . '<polyline fill="none" stroke="#dc3545" stroke-width="2.5" points="' . implode(' ', $unpaidLine) . '" />'
            . $labels
            . '</svg>';
    }

    private function occupancyReportTemplate(array $dataset, array $filters): string
    {
        $summary = is_array($dataset['summary'] ?? null) ? $dataset['summary'] : [];
        $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];

        $keyword = (string) ($filters['keyword'] ?? '');
        $city = (string) ($filters['city'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $bom = (string) ($filters['bom'] ?? '');

        $tbody = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>'
                . '<td>' . htmlspecialchars((string) ($row['property_name'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['property_code'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['city'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['district'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['owner_name'] ?? '')) . '</td>'
                . '<td>' . (int) ($row['total_rooms'] ?? 0) . '</td>'
                . '<td>' . (int) ($row['occupied_rooms'] ?? 0) . '</td>'
                . '<td>' . (int) ($row['vacant_rooms'] ?? 0) . '</td>'
                . '<td>' . number_format((float) ($row['occupancy_rate'] ?? 0), 2) . '%</td>'
                . '<td>' . htmlspecialchars((string) ($row['property_status'] ?? '')) . '</td>'
                . '</tr>';
        }

        if ($tbody === '') {
            $tbody = '<tr><td colspan="10" class="text-center text-muted">当前筛选条件下暂无房产数据</td></tr>';
        }

        $queryString = http_build_query([
            'keyword' => $keyword,
            'city' => $city,
            'status' => $status,
            'bom' => $bom,
        ]);

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>出租率分析</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"></head><body>'
            . '<div class="container mt-4">'
            . '<div class="d-flex justify-content-between align-items-center mb-3"><h3>出租率分析</h3><div class="d-flex gap-2"><a href="/reports/occupancy/export?' . htmlspecialchars($queryString, ENT_QUOTES) . '" class="btn btn-outline-success">导出CSV</a><a href="/reports/financial" class="btn btn-outline-dark">财务报表</a><a href="/dashboard" class="btn btn-secondary">返回仪表板</a></div></div>'
            . '<form class="row g-2 mb-3" method="GET" action="/reports/occupancy">'
            . '<input type="hidden" name="bom" value="' . htmlspecialchars($bom) . '">'
            . '<div class="col-md-4"><input class="form-control" type="search" name="keyword" placeholder="房产名/房号/地址关键字" value="' . htmlspecialchars($keyword) . '"></div>'
            . '<div class="col-md-3"><input class="form-control" type="text" name="city" placeholder="城市" value="' . htmlspecialchars($city) . '"></div>'
            . '<div class="col-md-3"><select class="form-select" name="status">'
            . '<option value="">全部状态</option>'
            . '<option value="occupied"' . ($status === 'occupied' ? ' selected' : '') . '>已出租</option>'
            . '<option value="vacant"' . ($status === 'vacant' ? ' selected' : '') . '>空置</option>'
            . '<option value="under_maintenance"' . ($status === 'under_maintenance' ? ' selected' : '') . '>维护中</option>'
            . '<option value="unavailable"' . ($status === 'unavailable' ? ' selected' : '') . '>不可用</option>'
            . '</select></div>'
            . '<div class="col-md-1 d-grid"><button class="btn btn-primary" type="submit">筛选</button></div>'
            . '<div class="col-md-1 d-grid"><a class="btn btn-outline-secondary" href="/reports/occupancy">重置</a></div>'
            . '</form>'
            . '<div class="row g-3 mb-3">'
            . '<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">房产数</div><div class="h4 mb-0">' . number_format((int) ($summary['property_count'] ?? 0)) . '</div></div></div></div>'
            . '<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">总房间数</div><div class="h4 mb-0">' . number_format((int) ($summary['total_rooms'] ?? 0)) . '</div></div></div></div>'
            . '<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">已出租房间数</div><div class="h4 mb-0 text-success">' . number_format((int) ($summary['occupied_rooms'] ?? 0)) . '</div></div></div></div>'
            . '<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">整体出租率</div><div class="h4 mb-0 text-primary">' . number_format((float) ($summary['occupancy_rate'] ?? 0), 2) . '%</div></div></div></div>'
            . '</div>'
            . '<div class="card"><div class="card-body table-responsive"><table class="table table-sm align-middle"><thead><tr><th>房产</th><th>房号</th><th>城市</th><th>区域</th><th>房东</th><th>总房间数</th><th>已出租</th><th>空置</th><th>出租率</th><th>状态</th></tr></thead><tbody>' . $tbody . '</tbody></table></div></div>'
            . '</div></body></html>';
    }

    private function buildReconciliationSortLink(array $filters, string $targetSortBy): string
    {
        $currentSortBy = (string) ($filters['sort_by'] ?? 'payment_period');
        $currentSortDir = (string) ($filters['sort_dir'] ?? 'desc');
        $nextDir = 'desc';
        if ($currentSortBy === $targetSortBy) {
            $nextDir = $currentSortDir === 'asc' ? 'desc' : 'asc';
        }

        $query = [
            'keyword' => (string) ($filters['keyword'] ?? ''),
            'period_from' => (string) ($filters['period_from'] ?? ''),
            'period_to' => (string) ($filters['period_to'] ?? ''),
            'meter_type' => (string) ($filters['meter_type'] ?? ''),
            'meter_code' => (string) ($filters['meter_code'] ?? ''),
            'unpaid_only' => (string) ($filters['unpaid_only'] ?? ''),
            'lite' => (string) ($filters['lite'] ?? ''),
            'sort_by' => $targetSortBy,
            'sort_dir' => $nextDir,
        ];
        $query = array_filter($query, static function ($value): bool {
            return (string) $value !== '';
        });

        return '/payments/reconciliation?' . http_build_query($query);
    }

    private function getSortDirectionIndicator(string $sortDir): string
    {
        return $sortDir === 'asc' ? ' ↑' : ' ↓';
    }

    private function getReconciliationSortLabel(string $sortBy, string $sortDir): string
    {
        $sortByLabelMap = [
            'payment_period' => '账期',
            'unpaid_amount' => '未收差额',
            'paid_rate' => '收款率',
        ];

        $fieldLabel = $sortByLabelMap[$sortBy] ?? '账期';
        $directionLabel = $sortDir === 'asc' ? '升序' : '降序';

        return $fieldLabel . '（' . $directionLabel . '）';
    }

    private function buildReconciliationTrendSvg(array $rows, array $filters = []): string
    {
        if (count($rows) === 0) {
            return '<div class="text-muted">暂无可视化数据</div>';
        }

        $rows = array_slice(array_reverse($rows), -12);
        $width = 860;
        $height = 220;
        $paddingTop = 16;
        $paddingRight = 16;
        $paddingBottom = 38;
        $paddingLeft = 52;

        $innerWidth = $width - $paddingLeft - $paddingRight;
        $innerHeight = $height - $paddingTop - $paddingBottom;

        $maxValue = 0.0;
        foreach ($rows as $row) {
            $maxValue = max($maxValue, (float) ($row['receivable'] ?? 0), (float) ($row['received'] ?? 0), (float) ($row['unpaid'] ?? 0));
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }

        $count = count($rows);
        $stepX = $count > 1 ? $innerWidth / ($count - 1) : 0;

        $lineReceivable = [];
        $lineReceived = [];
        $lineUnpaid = [];
        $labels = [];
        $receivablePointsSvg = '';
        $receivedPointsSvg = '';
        $unpaidPointsSvg = '';

        foreach ($rows as $i => $row) {
            $x = $paddingLeft + ($stepX * $i);
            $receivable = (float) ($row['receivable'] ?? 0);
            $received = (float) ($row['received'] ?? 0);
            $unpaid = (float) ($row['unpaid'] ?? 0);

            $yReceivable = $paddingTop + ($innerHeight * (1 - ($receivable / $maxValue)));
            $yReceived = $paddingTop + ($innerHeight * (1 - ($received / $maxValue)));
            $yUnpaid = $paddingTop + ($innerHeight * (1 - ($unpaid / $maxValue)));

            $lineReceivable[] = number_format($x, 2, '.', '') . ',' . number_format($yReceivable, 2, '.', '');
            $lineReceived[] = number_format($x, 2, '.', '') . ',' . number_format($yReceived, 2, '.', '');
            $lineUnpaid[] = number_format($x, 2, '.', '') . ',' . number_format($yUnpaid, 2, '.', '');

            $receivablePointsSvg .= '<circle cx="' . number_format($x, 2, '.', '') . '" cy="' . number_format($yReceivable, 2, '.', '') . '" r="2.6" fill="#0d6efd"><title>' . htmlspecialchars((string) ($row['period'] ?? '') . ' 应收 ¥' . number_format($receivable, 2), ENT_QUOTES) . '</title></circle>';
            $receivedPointsSvg .= '<circle cx="' . number_format($x, 2, '.', '') . '" cy="' . number_format($yReceived, 2, '.', '') . '" r="2.6" fill="#198754"><title>' . htmlspecialchars((string) ($row['period'] ?? '') . ' 实收 ¥' . number_format($received, 2), ENT_QUOTES) . '</title></circle>';
            $unpaidPointsSvg .= '<circle cx="' . number_format($x, 2, '.', '') . '" cy="' . number_format($yUnpaid, 2, '.', '') . '" r="2.6" fill="#dc3545"><title>' . htmlspecialchars((string) ($row['period'] ?? '') . ' 未收 ¥' . number_format($unpaid, 2), ENT_QUOTES) . '</title></circle>';

            $labels[] = [
                'x' => $x,
                'period' => (string) ($row['period'] ?? ''),
            ];
        }

        $yAxisMarks = [0.0, $maxValue * 0.25, $maxValue * 0.5, $maxValue * 0.75, $maxValue];
        $yAxisSvg = '';
        foreach ($yAxisMarks as $value) {
            $y = $paddingTop + ($innerHeight * (1 - ($value / $maxValue)));
            $yAxisSvg .= '<line x1="' . $paddingLeft . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . ($paddingLeft + $innerWidth) . '" y2="' . number_format($y, 2, '.', '') . '" stroke="#e9ecef" stroke-width="1" />';
            $yAxisSvg .= '<text x="' . ($paddingLeft - 8) . '" y="' . number_format($y + 4, 2, '.', '') . '" text-anchor="end" font-size="11" fill="#6c757d">' . htmlspecialchars(number_format($value, 0)) . '</text>';
        }

        $xAxisSvg = '';
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $unpaidOnly = (string) ($filters['unpaid_only'] ?? '') === '1';
        $sortBy = trim((string) ($filters['sort_by'] ?? 'payment_period'));
        $sortDir = trim((string) ($filters['sort_dir'] ?? 'desc'));
        $periodFrom = trim((string) ($filters['period_from'] ?? ''));
        $periodTo = trim((string) ($filters['period_to'] ?? ''));

        foreach ($labels as $label) {
            $periodLinkQuery = [
                'period' => $label['period'],
                'status' => 'unpaid',
                'keyword' => $keyword,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'unpaid_only' => $unpaidOnly ? '1' : '',
                'sort_by' => $sortBy !== 'payment_period' ? $sortBy : '',
                'sort_dir' => $sortDir !== 'desc' ? $sortDir : '',
                'source' => 'reconciliation',
                'source_period' => $label['period'],
            ];
            $periodLinkQuery = array_filter($periodLinkQuery, static function ($v): bool {
                return (string) $v !== '';
            });

            $periodLink = '/payments?' . http_build_query($periodLinkQuery);
            $xAxisSvg .= '<a class="trend-period-link" href="' . htmlspecialchars($periodLink, ENT_QUOTES) . '"><text x="' . number_format($label['x'], 2, '.', '') . '" y="' . ($height - 12) . '" text-anchor="middle" font-size="10" fill="#6c757d">' . htmlspecialchars($label['period']) . '</text></a>';
        }

        return '<svg id="reconciliationTrendChart" viewBox="0 0 ' . $width . ' ' . $height . '" class="w-100" role="img" aria-label="月度应收实收趋势图">'
            . '<line x1="' . $paddingLeft . '" y1="' . ($paddingTop + $innerHeight) . '" x2="' . ($paddingLeft + $innerWidth) . '" y2="' . ($paddingTop + $innerHeight) . '" stroke="#adb5bd" stroke-width="1" />'
            . '<line x1="' . $paddingLeft . '" y1="' . $paddingTop . '" x2="' . $paddingLeft . '" y2="' . ($paddingTop + $innerHeight) . '" stroke="#adb5bd" stroke-width="1" />'
            . $yAxisSvg
            . '<polyline fill="none" stroke="#0d6efd" stroke-width="2.5" points="' . implode(' ', $lineReceivable) . '" />'
            . '<polyline fill="none" stroke="#198754" stroke-width="2.5" points="' . implode(' ', $lineReceived) . '" />'
            . '<polyline fill="none" stroke="#dc3545" stroke-width="2.5" points="' . implode(' ', $lineUnpaid) . '" />'
                . $receivablePointsSvg
                . $receivedPointsSvg
                . $unpaidPointsSvg
            . $xAxisSvg
            . '</svg>';
    }

    private function collectPaymentFiltersFromQuery(): array
    {
        $status = trim((string) ($_GET['status'] ?? ''));
        $period = trim((string) ($_GET['period'] ?? ''));
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $periodFrom = trim((string) ($_GET['period_from'] ?? ''));
        $periodTo = trim((string) ($_GET['period_to'] ?? ''));
        $amountMinRaw = trim((string) ($_GET['amount_min'] ?? ''));
        $amountMaxRaw = trim((string) ($_GET['amount_max'] ?? ''));
        $bom = trim((string) ($_GET['bom'] ?? ''));
        $source = trim((string) ($_GET['source'] ?? ''));
        $sourcePeriod = trim((string) ($_GET['source_period'] ?? ''));
        $meterDetail = trim((string) ($_GET['meter_detail'] ?? ''));

        if ($period !== '' && !preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = '';
        }

        if ($periodFrom !== '' && !preg_match('/^\d{4}-\d{2}$/', $periodFrom)) {
            $periodFrom = '';
        }

        if ($periodTo !== '' && !preg_match('/^\d{4}-\d{2}$/', $periodTo)) {
            $periodTo = '';
        }

        if ($periodFrom !== '' && $periodTo !== '' && $periodFrom > $periodTo) {
            [$periodFrom, $periodTo] = [$periodTo, $periodFrom];
        }

        $amountMin = $amountMinRaw;
        if ($amountMinRaw !== '' && $this->parseOptionalNonNegativeFloat($amountMinRaw) === null) {
            $amountMin = '';
        }

        $amountMax = $amountMaxRaw;
        if ($amountMaxRaw !== '' && $this->parseOptionalNonNegativeFloat($amountMaxRaw) === null) {
            $amountMax = '';
        }

        $amountMinValue = $this->parseOptionalNonNegativeFloat($amountMin);
        $amountMaxValue = $this->parseOptionalNonNegativeFloat($amountMax);
        if ($amountMinValue !== null && $amountMaxValue !== null && $amountMinValue > $amountMaxValue) {
            [$amountMin, $amountMax] = [$amountMax, $amountMin];
        }

        if ($source !== 'reconciliation') {
            $source = '';
        }

        if ($sourcePeriod !== '' && !preg_match('/^\d{4}-\d{2}$/', $sourcePeriod)) {
            $sourcePeriod = '';
        }

        if ($source === '') {
            $sourcePeriod = '';
        }

        if ($meterDetail !== '1') {
            $meterDetail = '';
        }

        if ($bom !== '0') {
            $bom = '';
        }

        return [
            'status' => $status,
            'period' => $period,
            'keyword' => $keyword,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'amount_min' => $amountMin,
            'amount_max' => $amountMax,
            'bom' => $bom,
            'source' => $source,
            'source_period' => $sourcePeriod,
            'meter_detail' => $meterDetail,
        ];
    }

    private function collectReconciliationFiltersFromQuery(): array
    {
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $periodFrom = trim((string) ($_GET['period_from'] ?? ''));
        $periodTo = trim((string) ($_GET['period_to'] ?? ''));
        $meterType = trim((string) ($_GET['meter_type'] ?? ''));
        $meterCode = trim((string) ($_GET['meter_code'] ?? ''));
        $unpaidOnly = trim((string) ($_GET['unpaid_only'] ?? ''));
        $bom = trim((string) ($_GET['bom'] ?? ''));
        $sortBy = trim((string) ($_GET['sort_by'] ?? 'payment_period'));
        $sortDir = trim((string) ($_GET['sort_dir'] ?? 'desc'));
        $summaryRef = trim((string) ($_GET['summary_ref'] ?? ''));
        $lite = trim((string) ($_GET['lite'] ?? ''));

        if ($periodFrom !== '' && !preg_match('/^\d{4}-\d{2}$/', $periodFrom)) {
            $periodFrom = '';
        }

        if ($periodTo !== '' && !preg_match('/^\d{4}-\d{2}$/', $periodTo)) {
            $periodTo = '';
        }

        if ($periodFrom !== '' && $periodTo !== '' && $periodFrom > $periodTo) {
            [$periodFrom, $periodTo] = [$periodTo, $periodFrom];
        }

        if ($summaryRef !== '' && !preg_match('/^REC-\d{12}-[A-F0-9]{6}$/', $summaryRef)) {
            $summaryRef = '';
        }

        if ($unpaidOnly !== '1') {
            $unpaidOnly = '';
        }

        if ($bom !== '0') {
            $bom = '';
        }

        if (!in_array($sortBy, ['payment_period', 'unpaid_amount', 'paid_rate'], true)) {
            $sortBy = 'payment_period';
        }

        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        if ($lite !== '1') {
            $lite = '';
        }

        if ($meterType !== '' && !$this->isAllowedMeterType($meterType)) {
            $meterType = '';
        }

        if (strlen($meterCode) > 60) {
            $meterCode = substr($meterCode, 0, 60);
        }

        return [
            'keyword' => $keyword,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'meter_type' => $meterType,
            'meter_code' => $meterCode,
            'unpaid_only' => $unpaidOnly,
            'lite' => $lite,
            'bom' => $bom,
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
            'summary_ref' => $summaryRef,
        ];
    }

    private function collectFinancialReportFiltersFromQuery(): array
    {
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $periodFrom = trim((string) ($_GET['period_from'] ?? ''));
        $periodTo = trim((string) ($_GET['period_to'] ?? ''));
        $method = trim((string) ($_GET['method'] ?? ''));
        $overdueBucket = trim((string) ($_GET['overdue_bucket'] ?? ''));
        $lite = trim((string) ($_GET['lite'] ?? ''));
        $bom = trim((string) ($_GET['bom'] ?? ''));

        if ($periodFrom !== '' && !preg_match('/^\d{4}-\d{2}$/', $periodFrom)) {
            $periodFrom = '';
        }

        if ($periodTo !== '' && !preg_match('/^\d{4}-\d{2}$/', $periodTo)) {
            $periodTo = '';
        }

        if ($periodFrom !== '' && $periodTo !== '' && $periodFrom > $periodTo) {
            [$periodFrom, $periodTo] = [$periodTo, $periodFrom];
        }

        if (!in_array($method, ['', 'bank_transfer', 'cash', 'alipay', 'wechat_pay', 'other'], true)) {
            $method = '';
        }

        if (!in_array($overdueBucket, ['', 'none', 'not_due', '1_7', '8_30', '31_plus'], true)) {
            $overdueBucket = '';
        }

        if ($lite !== '1') {
            $lite = '';
        }

        if ($bom !== '0') {
            $bom = '';
        }

        return [
            'keyword' => $keyword,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'method' => $method,
            'overdue_bucket' => $overdueBucket,
            'lite' => $lite,
            'bom' => $bom,
        ];
    }

    private function collectOccupancyFiltersFromQuery(): array
    {
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $city = trim((string) ($_GET['city'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $bom = trim((string) ($_GET['bom'] ?? ''));

        if (!in_array($status, ['', 'occupied', 'vacant', 'under_maintenance', 'unavailable'], true)) {
            $status = '';
        }

        if ($bom !== '0') {
            $bom = '';
        }

        return [
            'keyword' => $keyword,
            'city' => $city,
            'status' => $status,
            'bom' => $bom,
        ];
    }

    private function isCsvBomEnabled(array $filters): bool
    {
        return (string) ($filters['bom'] ?? '') !== '0';
    }

    private function buildReconciliationSummaryRef(string $keyword, string $periodFrom, string $periodTo, bool $unpaidOnly, string $sortBy, string $sortDir, int $sumBills, float $sumReceivable, float $sumReceived, float $sumUnpaid): string
    {
        $summarySeed = implode('|', [
            $keyword,
            $periodFrom,
            $periodTo,
            $unpaidOnly ? '1' : '0',
            $sortBy,
            $sortDir,
            (string) $sumBills,
            number_format($sumReceivable, 2, '.', ''),
            number_format($sumReceived, 2, '.', ''),
            number_format($sumUnpaid, 2, '.', ''),
        ]);

        return 'REC-' . date('YmdHi') . '-' . strtoupper(substr(md5($summarySeed), 0, 6));
    }

    private function parseOptionalNonNegativeFloat($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);
        if ($str === '' || !is_numeric($str)) {
            return null;
        }

        $number = (float) $str;
        if ($number < 0) {
            return null;
        }

        return round($number, 2);
    }

    private function buildRecentPeriodLink(int $months, string $keyword = '', bool $unpaidOnly = false, string $sortBy = 'payment_period', string $sortDir = 'desc', bool $lite = false, string $meterType = '', string $meterCode = ''): string
    {
        $months = max(1, $months);
        $now = new \DateTimeImmutable(date('Y-m-01'));
        $from = $now->modify('-' . ($months - 1) . ' months');

        $query = [
            'period_from' => $from->format('Y-m'),
            'period_to' => $now->format('Y-m'),
        ];

        if ($keyword !== '') {
            $query['keyword'] = $keyword;
        }

        if ($meterType !== '') {
            $query['meter_type'] = $meterType;
        }

        if ($meterCode !== '') {
            $query['meter_code'] = $meterCode;
        }

        if ($unpaidOnly) {
            $query['unpaid_only'] = '1';
        }

        if ($sortBy !== 'payment_period') {
            $query['sort_by'] = $sortBy;
        }

        if ($sortDir !== 'desc') {
            $query['sort_dir'] = $sortDir;
        }

        if ($lite) {
            $query['lite'] = '1';
        }

        return '/payments/reconciliation?' . http_build_query($query);
    }

    private function buildCurrentYearLink(string $keyword = '', bool $unpaidOnly = false, string $sortBy = 'payment_period', string $sortDir = 'desc', bool $lite = false, string $meterType = '', string $meterCode = ''): string
    {
        $year = date('Y');

        $query = [
            'period_from' => $year . '-01',
            'period_to' => $year . '-12',
        ];

        if ($keyword !== '') {
            $query['keyword'] = $keyword;
        }

        if ($meterType !== '') {
            $query['meter_type'] = $meterType;
        }

        if ($meterCode !== '') {
            $query['meter_code'] = $meterCode;
        }

        if ($unpaidOnly) {
            $query['unpaid_only'] = '1';
        }

        if ($sortBy !== 'payment_period') {
            $query['sort_by'] = $sortBy;
        }

        if ($sortDir !== 'desc') {
            $query['sort_dir'] = $sortDir;
        }

        if ($lite) {
            $query['lite'] = '1';
        }

        return '/payments/reconciliation?' . http_build_query($query);
    }

    private function applyReconciliationSorting(array $rows, string $sortBy, string $sortDir): array
    {
        if ($rows === []) {
            return $rows;
        }

        $direction = $sortDir === 'asc' ? 1 : -1;

        usort($rows, static function (array $a, array $b) use ($sortBy, $direction): int {
            $primary = 0;

            if ($sortBy === 'unpaid_amount') {
                $left = (float) ($a['receivable_amount'] ?? 0) - (float) ($a['received_amount'] ?? 0);
                $right = (float) ($b['receivable_amount'] ?? 0) - (float) ($b['received_amount'] ?? 0);
                $primary = $left <=> $right;
            } elseif ($sortBy === 'paid_rate') {
                $leftCount = (int) ($a['bill_count'] ?? 0);
                $rightCount = (int) ($b['bill_count'] ?? 0);
                $leftPaid = (int) ($a['paid_count'] ?? 0);
                $rightPaid = (int) ($b['paid_count'] ?? 0);
                $left = $leftCount > 0 ? $leftPaid / $leftCount : 0;
                $right = $rightCount > 0 ? $rightPaid / $rightCount : 0;
                $primary = $left <=> $right;
            } else {
                $primary = strcmp((string) ($a['payment_period'] ?? ''), (string) ($b['payment_period'] ?? ''));
            }

            if ($primary !== 0) {
                return $primary * $direction;
            }

            // Stable tie-break to reduce list jumpiness when primary values are equal.
            return strcmp((string) ($b['payment_period'] ?? ''), (string) ($a['payment_period'] ?? ''));
        });

        return $rows;
    }

    private function isRecentPeriodActive(string $periodFrom, string $periodTo, int $months): bool
    {
        if ($periodFrom === '' || $periodTo === '') {
            return false;
        }

        $months = max(1, $months);
        $now = new \DateTimeImmutable(date('Y-m-01'));
        $expectedFrom = $now->modify('-' . ($months - 1) . ' months')->format('Y-m');
        $expectedTo = $now->format('Y-m');

        return $periodFrom === $expectedFrom && $periodTo === $expectedTo;
    }

    private function isCurrentYearActive(string $periodFrom, string $periodTo): bool
    {
        if ($periodFrom === '' || $periodTo === '') {
            return false;
        }

        $year = date('Y');
        return $periodFrom === $year . '-01' && $periodTo === $year . '-12';
    }

    private function billCreateTemplate(array $contracts, ?array $selectedContract, string $period, array $meterRows): string
    {
        $alerts = $this->renderFlashAlerts();
        $contractOptions = '';
        foreach ($contracts as $contract) {
            $selected = $selectedContract !== null && (int) $selectedContract['id'] === (int) $contract['id'] ? ' selected' : '';
            $contractOptions .= '<option value="' . (int) $contract['id'] . '"' . $selected . '>'
                . htmlspecialchars((string) $contract['contract_number']) . ' - '
                . htmlspecialchars((string) $contract['tenant_name']) . ' - '
                . htmlspecialchars((string) $contract['property_name'])
                . '</option>';
        }

        if ($contractOptions === '') {
            $contractOptions = '<option value="">暂无可用合同</option>';
        }

        $rentAmount = (float) ($selectedContract['rent_amount'] ?? 0);
        $defaultMeterTypeOptions = $this->buildMeterTypeSelectOptionsHtml('water');
        $meterRowsHtml = '';
        foreach ($meterRows as $index => $row) {
            $meterRowsHtml .= '<tr data-meter-row>'
                . '<td>'
                . '<input type="hidden" name="meter_id[]" value="' . (int) ($row['meter_id'] ?? 0) . '">'
                . '<select class="form-select form-select-sm" name="meter_type[]" required>'
            . $this->buildMeterTypeSelectOptionsHtml((string) ($row['meter_type'] ?? ''))
                . '</select>'
                . '</td>'
                . '<td><input class="form-control form-control-sm" type="text" name="meter_code[]" value="' . htmlspecialchars((string) ($row['meter_code'] ?? ''), ENT_QUOTES) . '" placeholder="如 WATER-1" required></td>'
                . '<td><input class="form-control form-control-sm" type="text" name="meter_name[]" value="' . htmlspecialchars((string) ($row['meter_name'] ?? ''), ENT_QUOTES) . '" placeholder="可选名称"></td>'
                . '<td><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="previous_reading[]" value="' . number_format((float) ($row['previous_reading'] ?? 0), 2, '.', '') . '" required></td>'
                . '<td><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="current_reading[]" value="' . number_format((float) ($row['current_reading'] ?? 0), 2, '.', '') . '" required></td>'
                . '<td><input class="form-control form-control-sm" type="number" step="0.0001" min="0" name="unit_price[]" value="' . number_format((float) ($row['unit_price'] ?? 0), 4, '.', '') . '" required></td>'
                . '<td class="text-end text-nowrap usage-cell">0.00</td>'
                . '<td class="text-end text-nowrap fee-cell">¥0.00</td>'
                . '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-row-btn">删除</button></td>'
                . '</tr>';
        }

        if ($meterRowsHtml === '') {
            $meterRowsHtml = '<tr data-meter-row>'
                . '<td><input type="hidden" name="meter_id[]" value="0"><select class="form-select form-select-sm" name="meter_type[]" required>' . $defaultMeterTypeOptions . '</select></td>'
                . '<td><input class="form-control form-control-sm" type="text" name="meter_code[]" placeholder="如 WATER-1" required></td>'
                . '<td><input class="form-control form-control-sm" type="text" name="meter_name[]" placeholder="可选名称"></td>'
                . '<td><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="previous_reading[]" value="0.00" required></td>'
                . '<td><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="current_reading[]" value="0.00" required></td>'
                . '<td><input class="form-control form-control-sm" type="number" step="0.0001" min="0" name="unit_price[]" value="0.0000" required></td>'
                . '<td class="text-end text-nowrap usage-cell">0.00</td>'
                . '<td class="text-end text-nowrap fee-cell">¥0.00</td>'
                . '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-row-btn">删除</button></td>'
                . '</tr>';
        }

        $contractSwitchScript = '';
        if (count($contracts) > 0) {
            $contractSwitchScript = 'onchange="const p=document.querySelector(\'[name=period]\');const period=p?p.value:\'' . htmlspecialchars($period, ENT_QUOTES) . '\';window.location=\'/payments/create?contract_id=\'+this.value+\'&period=\'+encodeURIComponent(period);"';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>新建月度账单</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"></head><body>'
            . '<div class="container mt-4"><div class="d-flex justify-content-between align-items-center mb-3"><h3>新建月度账单</h3><a href="/payments" class="btn btn-secondary">返回账单列表</a></div>'
            . $alerts
            . '<div class="card"><div class="card-body">'
            . '<form method="POST" action="/payments">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<div class="row g-3">'
            . '<div class="col-md-6"><label class="form-label">合同</label><select class="form-select" name="contract_id" ' . $contractSwitchScript . ' required>' . $contractOptions . '</select></div>'
            . '<div class="col-md-3"><label class="form-label">账单周期</label><input class="form-control" type="month" name="period" value="' . htmlspecialchars($period) . '" required></div>'
            . '<div class="col-md-3"><label class="form-label">固定月租金</label><input class="form-control" type="number" step="0.01" id="rentAmount" value="' . number_format($rentAmount, 2, '.', '') . '" disabled></div>'
            . '<div class="col-12"><hr class="my-1"></div>'
            . '<div class="col-12">'
            . '<div class="d-flex justify-content-between align-items-center mb-2"><label class="form-label mb-0">计量表明细（支持一套房多个表计，如水/电/气）</label><button type="button" class="btn btn-sm btn-outline-primary" id="addMeterRowBtn">新增表计</button></div>'
            . '<div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead><tr><th>类型</th><th>表计编号</th><th>表计名称</th><th>上月读数</th><th>本月读数</th><th>单价</th><th class="text-end">用量</th><th class="text-end">费用</th><th class="text-center">操作</th></tr></thead><tbody id="meterRowsBody">' . $meterRowsHtml . '</tbody></table></div>'
            . '</div>'
            . '<div class="col-12"><div id="formulaPreview" class="alert alert-info mb-0">系统将逐行独立计算：用量 = 本月读数 - 上月读数；费用 = 用量 × 单价；应收总额 = 月租金 + 全部计量费用。</div></div>'
            . '<div class="col-12 d-grid"><button class="btn btn-primary" type="submit"' . ($selectedContract === null ? ' disabled' : '') . '>计算并生成账单</button></div>'
            . '</div></form></div></div></div>'
            . '<script>'
            . 'const byId=(id)=>document.getElementById(id);'
            . 'const toNum=(value)=>{const v=parseFloat(String(value??"0"));return Number.isFinite(v)?v:0;};'
            . 'const money=(v)=>"¥"+v.toFixed(2);'
            . 'const body=byId("meterRowsBody");'
            . 'const createRow=()=>{const tr=document.createElement("tr");tr.setAttribute("data-meter-row","");tr.innerHTML="<td><input type=\"hidden\" name=\"meter_id[]\" value=\"0\"><select class=\"form-select form-select-sm\" name=\"meter_type[]\" required>' . str_replace('"', '\\"', $defaultMeterTypeOptions) . '</select></td><td><input class=\"form-control form-control-sm\" type=\"text\" name=\"meter_code[]\" placeholder=\"如 WATER-2\" required></td><td><input class=\"form-control form-control-sm\" type=\"text\" name=\"meter_name[]\" placeholder=\"可选名称\"></td><td><input class=\"form-control form-control-sm\" type=\"number\" step=\"0.01\" min=\"0\" name=\"previous_reading[]\" value=\"0.00\" required></td><td><input class=\"form-control form-control-sm\" type=\"number\" step=\"0.01\" min=\"0\" name=\"current_reading[]\" value=\"0.00\" required></td><td><input class=\"form-control form-control-sm\" type=\"number\" step=\"0.0001\" min=\"0\" name=\"unit_price[]\" value=\"0.0000\" required></td><td class=\"text-end text-nowrap usage-cell\">0.00</td><td class=\"text-end text-nowrap fee-cell\">¥0.00</td><td class=\"text-center\"><button type=\"button\" class=\"btn btn-sm btn-outline-danger remove-row-btn\">删除</button></td>";return tr;};'
            . 'const recalc=()=>{const rent=toNum(byId("rentAmount")?.value);let meterFee=0,warns=[];const rows=body.querySelectorAll("tr[data-meter-row]");rows.forEach((row)=>{const prev=toNum(row.querySelector("[name=\"previous_reading[]\"]")?.value);const cur=toNum(row.querySelector("[name=\"current_reading[]\"]")?.value);const price=toNum(row.querySelector("[name=\"unit_price[]\"]")?.value);const usage=cur-prev;const safeUsage=Math.max(0,usage);const fee=safeUsage*price;const usageCell=row.querySelector(".usage-cell");const feeCell=row.querySelector(".fee-cell");if(usageCell){usageCell.textContent=safeUsage.toFixed(2);}if(feeCell){feeCell.textContent=money(fee);}if(usage<0){warns.push("存在本月读数小于上月读数的表计");}meterFee+=fee;});const total=rent+meterFee;const warnHtml=warns.length>0?"<div class=\"text-danger fw-bold mb-2\">"+warns[0]+"</div>":"";byId("formulaPreview").innerHTML=warnHtml+"<div><strong>预估计量费合计</strong>："+money(meterFee)+"</div><div><strong>预估总额</strong>："+money(rent)+" + "+money(meterFee)+" = "+money(total)+"</div>";};'
            . 'byId("addMeterRowBtn")?.addEventListener("click",()=>{body.appendChild(createRow());recalc();});'
            . 'body.addEventListener("input",(e)=>{if(e.target instanceof HTMLElement){recalc();}});'
            . 'body.addEventListener("click",(e)=>{const target=e.target;if(!(target instanceof HTMLElement)){return;}if(target.classList.contains("remove-row-btn")){const rows=body.querySelectorAll("tr[data-meter-row]");if(rows.length<=1){return;}const tr=target.closest("tr[data-meter-row]");if(tr){tr.remove();recalc();}}});'
            . 'recalc();'
            . '</script>'
            . '</body></html>';
    }

    private function receiptTemplate(array $payment): string
    {
        $grossDue = (float) $payment['amount_due'] + (float) $payment['late_fee'];
        $discount = (float) $payment['discount'];
        $totalDue = max(0.0, $grossDue - $discount);
        $amountPaid = (float) ($payment['amount_paid'] ?? 0);
        $unpaidAmount = max(0.0, $totalDue - $amountPaid);
        $billDetails = $this->extractBillDetails($payment['notes'] ?? null);
        $meterDetails = $this->getPaymentMeterDetails((int) ($payment['id'] ?? 0));
        $paymentNote = $billDetails !== null
            ? trim((string) ($billDetails['payment_note'] ?? ''))
            : trim((string) ($payment['notes'] ?? ''));
        $discountSource = $billDetails !== null
            ? trim((string) ($billDetails['discount_source'] ?? ''))
            : '';
        $paymentMethod = (string) ($payment['payment_method'] ?? 'bank_transfer');

        $formulaBlock = '';
        if ($meterDetails !== []) {
            $waterFee = 0.0;
            $electricFee = 0.0;
            $meterFeeTotal = 0.0;
            $typeUsageMap = [];
            $typeFeeMap = [];
            $rowsHtml = '';
            foreach ($meterDetails as $row) {
                $type = (string) ($row['meter_type'] ?? 'water');
                $lineAmount = (float) ($row['line_amount'] ?? 0);
                $usageAmount = (float) ($row['usage_amount'] ?? 0);
                $unitPrice = (float) ($row['unit_price'] ?? 0);
                $meterFeeTotal += $lineAmount;

                if (!isset($typeUsageMap[$type])) {
                    $typeUsageMap[$type] = 0.0;
                }
                if (!isset($typeFeeMap[$type])) {
                    $typeFeeMap[$type] = 0.0;
                }
                $typeUsageMap[$type] += $usageAmount;
                $typeFeeMap[$type] += $lineAmount;

                if ($type === 'water') {
                    $waterFee += $lineAmount;
                } else {
                    $electricFee += $lineAmount;
                }

                $rowsHtml .= '<tr>'
                    . '<td>' . htmlspecialchars($this->meterTypeLabel($type), ENT_QUOTES) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($row['meter_code_snapshot'] ?? ''), ENT_QUOTES) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($row['meter_name_snapshot'] ?? '-'), ENT_QUOTES) . '</td>'
                    . '<td class="text-end">' . number_format((float) ($row['previous_reading'] ?? 0), 2) . '</td>'
                    . '<td class="text-end">' . number_format((float) ($row['current_reading'] ?? 0), 2) . '</td>'
                    . '<td class="text-end">' . number_format($usageAmount, 2) . '</td>'
                    . '<td class="text-end">' . number_format($unitPrice, 4) . '</td>'
                    . '<td class="text-end">¥' . number_format($lineAmount, 2) . '</td>'
                    . '<td class="text-end">' . number_format($usageAmount, 2) . ' × ¥' . number_format($unitPrice, 4) . ' = ¥' . number_format($lineAmount, 2) . '</td>'
                    . '</tr>';
            }

            $rentAmount = max(0.0, (float) $payment['amount_due'] - $waterFee - $electricFee);
            if ($billDetails !== null && isset($billDetails['formula']) && is_array($billDetails['formula'])) {
                $rentAmount = (float) (($billDetails['formula']['rent_amount'] ?? $rentAmount));
            }

            $payableRows = '<li><strong>固定月租金：</strong>¥' . number_format($rentAmount, 2) . '</li>';
            foreach ($typeFeeMap as $meterType => $fee) {
                $label = $this->meterTypeLabel((string) $meterType);
                $usage = (float) ($typeUsageMap[$meterType] ?? 0);
                $payableRows .= '<li><strong>' . htmlspecialchars($label, ENT_QUOTES) . '费用：</strong>¥' . number_format($fee, 2)
                    . '（累计用量 ' . number_format($usage, 2) . '）</li>';
            }
            $payableRows .= '<li><strong>滞纳金：</strong>¥' . number_format((float) $payment['late_fee'], 2) . '</li>';
            $payableRows .= '<li><strong>折扣抵扣：</strong>-¥' . number_format((float) $payment['discount'], 2) . '</li>';

            $formulaBlock = '<hr>'
                . '<h5 class="mb-3">本期账单明细（多表计）</h5>'
                . '<p><strong>固定月租金：</strong>¥' . number_format($rentAmount, 2) . '</p>'
                . '<div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead><tr><th>类型</th><th>表计编号</th><th>表计名称</th><th class="text-end">上月读数</th><th class="text-end">本月读数</th><th class="text-end">用量</th><th class="text-end">单价</th><th class="text-end">费用</th><th class="text-end">计算公式</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>'
                . '<p><strong>水费合计：</strong>¥' . number_format($waterFee, 2) . '</p>'
                . '<p><strong>电费合计：</strong>¥' . number_format($electricFee, 2) . '</p>'
                . '<hr>'
                . '<h6 class="mb-2">应付构成</h6>'
                . '<ul class="mb-2">' . $payableRows . '</ul>'
                . '<p class="mb-0"><strong>应收计算公式：</strong>¥' . number_format($rentAmount, 2) . ' + ¥' . number_format($meterFeeTotal, 2) . ' + ¥' . number_format((float) $payment['late_fee'], 2) . ' - ¥' . number_format((float) $payment['discount'], 2) . ' = <strong>¥' . number_format($totalDue, 2) . '</strong></p>';
        } elseif ($billDetails !== null && isset($billDetails['formula']) && is_array($billDetails['formula'])) {
            $f = $billDetails['formula'];
            $waterPrevious = (float) ($f['water_previous'] ?? 0);
            $waterCurrent = (float) ($f['water_current'] ?? 0);
            $waterUsage = (float) ($f['water_usage'] ?? 0);
            $waterUnitPrice = (float) ($f['water_unit_price'] ?? 0);
            $waterFee = (float) ($f['water_fee'] ?? 0);

            $electricPrevious = (float) ($f['electric_previous'] ?? 0);
            $electricCurrent = (float) ($f['electric_current'] ?? 0);
            $electricUsage = (float) ($f['electric_usage'] ?? 0);
            $electricUnitPrice = (float) ($f['electric_unit_price'] ?? 0);
            $electricFee = (float) ($f['electric_fee'] ?? 0);
            $rentAmount = (float) ($f['rent_amount'] ?? $payment['amount_due'] ?? 0);

            $formulaBlock = '<hr>'
                . '<h5 class="mb-3">本期账单明细</h5>'
                . '<p><strong>固定月租金：</strong>¥' . number_format($rentAmount, 2) . '</p>'
                . '<p><strong>总用水量：</strong>' . number_format($waterCurrent, 2) . '（上月：' . number_format($waterPrevious, 2) . '，本月：' . number_format($waterCurrent, 2) . '）</p>'
                . '<p><strong>当月水量：</strong>' . number_format($waterUsage, 2) . ' × 单价 ¥' . number_format($waterUnitPrice, 2) . ' = 水费 ¥' . number_format($waterFee, 2) . '</p>'
                . '<p><strong>总用电量：</strong>' . number_format($electricCurrent, 2) . '（上月：' . number_format($electricPrevious, 2) . '，本月：' . number_format($electricCurrent, 2) . '）</p>'
                . '<p><strong>当月电量：</strong>' . number_format($electricUsage, 2) . ' × 单价 ¥' . number_format($electricUnitPrice, 2) . ' = 电费 ¥' . number_format($electricFee, 2) . '</p>'
                . '<hr>'
                . '<h6 class="mb-2">应付构成</h6>'
                . '<ul class="mb-2"><li><strong>固定月租金：</strong>¥' . number_format($rentAmount, 2) . '</li><li><strong>水费：</strong>¥' . number_format($waterFee, 2) . '</li><li><strong>电费：</strong>¥' . number_format($electricFee, 2) . '</li><li><strong>滞纳金：</strong>¥' . number_format((float) $payment['late_fee'], 2) . '</li><li><strong>折扣抵扣：</strong>-¥' . number_format((float) $payment['discount'], 2) . '</li></ul>'
                . '<p class="mb-0"><strong>应收计算公式：</strong>¥' . number_format($rentAmount, 2) . ' + ¥' . number_format($waterFee, 2) . ' + ¥' . number_format($electricFee, 2) . ' + ¥' . number_format((float) $payment['late_fee'], 2) . ' - ¥' . number_format((float) $payment['discount'], 2) . ' = <strong>¥' . number_format($totalDue, 2) . '</strong></p>';
        }

        $recordingForm = '';
        if ($unpaidAmount > 0.0001 || (string) ($payment['payment_status'] ?? '') !== 'paid') {
            $recordingForm = '<hr>'
                . '<h5 class="mb-3">登记收款（支持折扣抵扣）</h5>'
                . '<form method="POST" action="/payments/' . (int) ($payment['id'] ?? 0) . '/record">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<div class="row g-2">'
                . '<div class="col-md-3"><label class="form-label">实收金额</label><input class="form-control" type="number" step="0.01" min="0" name="amount_paid" value="' . number_format($amountPaid, 2, '.', '') . '" required></div>'
                . '<div class="col-md-3"><label class="form-label">折扣/抵扣金额</label><input class="form-control" type="number" step="0.01" min="0" max="' . number_format($grossDue, 2, '.', '') . '" name="discount" value="' . number_format($discount, 2, '.', '') . '" required></div>'
                . '<div class="col-md-3"><label class="form-label">支付方式</label><select class="form-select" name="payment_method">'
                . '<option value="bank_transfer"' . ($paymentMethod === 'bank_transfer' ? ' selected' : '') . '>银行转账</option>'
                . '<option value="cash"' . ($paymentMethod === 'cash' ? ' selected' : '') . '>现金</option>'
                . '<option value="alipay"' . ($paymentMethod === 'alipay' ? ' selected' : '') . '>支付宝</option>'
                . '<option value="wechat_pay"' . ($paymentMethod === 'wechat_pay' ? ' selected' : '') . '>微信支付</option>'
                . '<option value="other"' . ($paymentMethod === 'other' ? ' selected' : '') . '>其他</option>'
                . '</select></div>'
                . '<div class="col-md-3"><label class="form-label">抵扣来源</label><select class="form-select" name="discount_source"><option value=""' . ($discountSource === '' ? ' selected' : '') . '>无</option><option value="deposit_offset"' . ($discountSource === 'deposit_offset' ? ' selected' : '') . '>押金冲抵</option><option value="promotion"' . ($discountSource === 'promotion' ? ' selected' : '') . '>优惠减免</option><option value="bad_debt"' . ($discountSource === 'bad_debt' ? ' selected' : '') . '>坏账核销</option><option value="other"' . ($discountSource === 'other' ? ' selected' : '') . '>其他</option></select></div>'
                . '<div class="col-md-3"><label class="form-label">本次结余参考</label><input class="form-control" value="¥' . number_format($unpaidAmount, 2) . '" disabled></div>'
                . '<div class="col-12"><label class="form-label">备注</label><textarea class="form-control" name="notes" rows="2" placeholder="例如：押金冲抵、维修补贴抵扣等">' . htmlspecialchars($paymentNote, ENT_QUOTES) . '</textarea></div>'
                . '<div class="col-12"><div class="small text-muted">结清规则：若 实收金额 + 折扣/抵扣金额 >= 应收合计，则账单状态更新为“已支付”。</div></div>'
                . '<div class="col-12 d-grid"><button class="btn btn-primary" type="submit">保存收款记录</button></div>'
                . '</div></form>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>支付收据</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"></head><body>'
            . '<div class="container mt-4"><div class="card"><div class="card-body">'
            . '<h3 class="mb-3">租金支付收据</h3>'
            . '<p><strong>支付编号：</strong>' . htmlspecialchars((string) $payment['payment_number']) . '</p>'
            . '<p><strong>合同编号：</strong>' . htmlspecialchars((string) $payment['contract_number']) . '</p>'
            . '<p><strong>租客姓名：</strong>' . htmlspecialchars((string) $payment['tenant_name']) . '</p>'
            . '<p><strong>房产名称：</strong>' . htmlspecialchars((string) $payment['property_name']) . '</p>'
            . '<p><strong>账单周期：</strong>' . htmlspecialchars((string) $payment['payment_period']) . '</p>'
            . '<hr>'
            . '<p><strong>应付金额：</strong>¥' . number_format((float) $payment['amount_due'], 2) . '</p>'
            . '<p><strong>滞纳金：</strong>¥' . number_format((float) $payment['late_fee'], 2) . '</p>'
            . '<p><strong>折扣：</strong>¥' . number_format((float) $payment['discount'], 2) . '</p>'
            . (((float) $payment['discount'] > 0 && $discountSource !== '') ? '<p><strong>抵扣来源：</strong>' . htmlspecialchars($this->discountSourceLabel($discountSource), ENT_QUOTES) . '</p>' : '')
            . '<p><strong>应收合计：</strong>¥' . number_format($totalDue, 2) . '</p>'
            . '<p><strong>实收金额：</strong>¥' . number_format($amountPaid, 2) . '</p>'
            . '<p><strong>未收余额：</strong>¥' . number_format($unpaidAmount, 2) . '</p>'
            . '<p><strong>支付日期：</strong>' . htmlspecialchars((string) ($payment['paid_date'] ?? '-')) . '</p>'
            . '<p><strong>状态：</strong>' . $this->paymentStatusBadge((string) $payment['payment_status']) . '</p>'
            . $formulaBlock
            . $recordingForm
            . '<div class="mt-3 d-flex gap-2"><a class="btn btn-secondary" href="/payments">返回账单列表</a><button class="btn btn-outline-primary" onclick="window.print()">打印收据</button></div>'
            . '</div></div></div></body></html>';
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

    private function isAllowedMeterType(string $meterType): bool
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

    private function buildMeterTypeSelectOptionsHtml(string $selected = ''): string
    {
        $html = '';
        foreach ($this->getMeterTypeDefinitions() as $definition) {
            $typeKey = (string) ($definition['type_key'] ?? '');
            if ($typeKey === '') {
                continue;
            }

            $typeName = (string) ($definition['type_name'] ?? $typeKey);
            $html .= '<option value="' . htmlspecialchars($typeKey, ENT_QUOTES) . '"' . ($selected === $typeKey ? ' selected' : '') . '>'
                . htmlspecialchars($typeName, ENT_QUOTES)
                . '</option>';
        }

        return $html;
    }

    private function getDefaultUnitPriceByMeterType(string $meterType): float
    {
        if ($meterType === 'water') {
            return (float) $this->getSetting('rent.water_unit_price', '0');
        }

        if ($meterType === 'electric') {
            return (float) $this->getSetting('rent.electric_unit_price', '0');
        }

        if ($meterType === 'gas') {
            return (float) $this->getSetting('rent.gas_unit_price', '0');
        }

        return 0.0;
    }

    private function escapeCsv(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
