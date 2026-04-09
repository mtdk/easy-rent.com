<?php

declare(strict_types=1);

/**
 * A.xlsx 导入 dry-run（只预演，不写库）
 *
 * 用法:
 *   php scripts/dry-run-import-rent-xlsx.php [preview_json_path] [report_json_path] [db_user] [db_password] [db_name] [db_host] [db_port]
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$previewPath = $argv[1] ?? __DIR__ . '/../storage/import-preview/a-xlsx-preview.json';
$reportPath = $argv[2] ?? __DIR__ . '/../storage/import-preview/a-xlsx-dry-run-report.json';
$dbUser = $argv[3] ?? getenv('DB_USERNAME') ?: 'xmtdk';
$dbPassword = $argv[4] ?? getenv('DB_PASSWORD') ?: '';
$dbName = $argv[5] ?? getenv('DB_DATABASE') ?: 'easy_rent';
$dbHost = $argv[6] ?? getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = (int) ($argv[7] ?? getenv('DB_PORT') ?: 3306);

if (!is_file($previewPath)) {
    fwrite(STDERR, "预览文件不存在: {$previewPath}\n");
    exit(1);
}

$previewRaw = file_get_contents($previewPath);
if ($previewRaw === false || $previewRaw === '') {
    fwrite(STDERR, "无法读取预览文件: {$previewPath}\n");
    exit(1);
}

$preview = json_decode($previewRaw, true);
if (!is_array($preview)) {
    fwrite(STDERR, "预览文件 JSON 无效: {$previewPath}\n");
    exit(1);
}

$records = $preview['normalized_records'] ?? [];
if (!is_array($records)) {
    fwrite(STDERR, "预览文件缺少 normalized_records 数组\n");
    exit(1);
}

$pdo = connectDb($dbHost, $dbPort, $dbName, $dbUser, $dbPassword);
$contracts = fetchContracts($pdo);
$contractIndex = buildContractIndex($contracts);

$eligible = [];
$skipped = [];

foreach ($records as $record) {
    if (!is_array($record)) {
        continue;
    }

    $date = normalizeDate($record['date'] ?? null);
    $roomNo = trim((string) ($record['room_no'] ?? ''));
    $tenantName = trim((string) ($record['tenant_name'] ?? ''));
    $period = $date !== null ? substr($date, 0, 7) : null;
    $amountDue = normalizeAmount($record['total_amount'] ?? null);

    if ($date === null || $period === null || $roomNo === '' || $tenantName === '' || $amountDue === null || $amountDue <= 0) {
        $skipped[] = [
            'sheet_name' => $record['sheet_name'] ?? null,
            'row_index' => $record['row_index'] ?? null,
            'reason' => '缺少导入关键字段(date/room_no/tenant_name/total_amount)',
            'raw' => [
                'date' => $record['date'] ?? null,
                'room_no' => $roomNo,
                'tenant_name' => $tenantName,
                'total_amount' => $record['total_amount'] ?? null,
            ],
        ];
        continue;
    }

    $eligible[] = [
        'sheet_name' => $record['sheet_name'] ?? null,
        'row_index' => (int) ($record['row_index'] ?? 0),
        'date' => $date,
        'period' => $period,
        'room_no' => $roomNo,
        'tenant_name' => $tenantName,
        'amount_due' => $amountDue,
        'remark' => trim((string) ($record['remark'] ?? '')),
        'formula' => [
            'water_previous' => normalizeAmount($record['water_previous'] ?? null),
            'water_current' => normalizeAmount($record['water_current'] ?? null),
            'water_usage' => normalizeAmount($record['water_usage'] ?? null),
            'water_fee' => normalizeAmount($record['water_fee'] ?? null),
            'electric_previous' => normalizeAmount($record['electric_previous'] ?? null),
            'electric_current' => normalizeAmount($record['electric_current'] ?? null),
            'electric_usage' => normalizeAmount($record['electric_usage'] ?? null),
            'electric_fee' => normalizeAmount($record['electric_fee'] ?? null),
            'rent_amount' => normalizeAmount($record['rent_amount'] ?? null),
        ],
    ];
}

$deduped = dedupeByContractKey($eligible);

$matched = [];
$unmatched = [];
$ambiguous = [];
$conflicts = [];
$importable = [];

foreach ($deduped as $candidate) {
    $matches = matchContracts($candidate, $contractIndex);

    if (count($matches) === 0) {
        $unmatched[] = [
            'reason' => '未匹配到合同',
            'record' => $candidate,
        ];
        continue;
    }

    if (count($matches) > 1) {
        $ambiguous[] = [
            'reason' => '匹配到多个合同',
            'record' => $candidate,
            'contract_candidates' => array_map(static function (array $c): array {
                return [
                    'contract_id' => (int) $c['contract_id'],
                    'contract_number' => (string) $c['contract_number'],
                    'tenant_name' => (string) $c['tenant_name'],
                    'property_code' => (string) $c['property_code'],
                    'property_name' => (string) $c['property_name'],
                    'start_date' => (string) $c['start_date'],
                    'end_date' => (string) $c['end_date'],
                    'status' => (string) $c['contract_status'],
                ];
            }, $matches),
        ];
        continue;
    }

    $contract = $matches[0];
    $candidate['matched_contract'] = [
        'contract_id' => (int) $contract['contract_id'],
        'contract_number' => (string) $contract['contract_number'],
        'property_code' => (string) $contract['property_code'],
        'property_name' => (string) $contract['property_name'],
        'tenant_name' => (string) $contract['tenant_name'],
        'start_date' => (string) $contract['start_date'],
        'end_date' => (string) $contract['end_date'],
        'status' => (string) $contract['contract_status'],
    ];

    $matched[] = $candidate;

    $existing = findExistingPayment($pdo, (int) $contract['contract_id'], (string) $candidate['period']);
    if ($existing !== null) {
        $conflicts[] = [
            'reason' => '账单已存在',
            'record' => $candidate,
            'existing_payment' => $existing,
        ];
        continue;
    }

    $importable[] = [
        'contract_id' => (int) $contract['contract_id'],
        'contract_number' => (string) $contract['contract_number'],
        'period' => (string) $candidate['period'],
        'due_date' => buildDueDate((string) $candidate['period'], (int) $contract['payment_day']),
        'amount_due' => (float) $candidate['amount_due'],
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_date' => (string) $candidate['date'],
        'notes_preview' => buildNotesPreview($candidate),
        'source' => [
            'sheet_name' => $candidate['sheet_name'] ?? null,
            'row_index' => (int) ($candidate['row_index'] ?? 0),
        ],
    ];
}

$bootstrap = buildBootstrapCandidates($deduped, $unmatched);

$report = [
    'source_preview_file' => realpath($previewPath) ?: $previewPath,
    'generated_at' => date('c'),
    'mode' => 'dry-run',
    'database' => [
        'host' => $dbHost,
        'port' => $dbPort,
        'database' => $dbName,
        'username' => $dbUser,
    ],
    'summary' => [
        'source_record_count' => count($records),
        'eligible_record_count' => count($eligible),
        'skipped_record_count' => count($skipped),
        'deduped_record_count' => count($deduped),
        'matched_record_count' => count($matched),
        'unmatched_record_count' => count($unmatched),
        'ambiguous_record_count' => count($ambiguous),
        'existing_payment_conflict_count' => count($conflicts),
        'importable_record_count' => count($importable),
        'bootstrap_property_candidate_count' => count($bootstrap['properties']),
        'bootstrap_contract_candidate_count' => count($bootstrap['contracts']),
    ],
    'samples' => [
        'skipped' => array_slice($skipped, 0, 30),
        'unmatched' => array_slice($unmatched, 0, 30),
        'ambiguous' => array_slice($ambiguous, 0, 30),
        'existing_conflicts' => array_slice($conflicts, 0, 30),
        'importable' => array_slice($importable, 0, 50),
        'bootstrap_properties' => array_slice($bootstrap['properties'], 0, 50),
        'bootstrap_contracts' => array_slice($bootstrap['contracts'], 0, 50),
    ],
    'skipped_records' => $skipped,
    'unmatched_records' => $unmatched,
    'ambiguous_records' => $ambiguous,
    'existing_conflict_records' => $conflicts,
    'importable_records' => $importable,
    'bootstrap_candidates' => $bootstrap,
];

$reportDir = dirname($reportPath);
if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "无法创建目录: {$reportDir}\n");
    exit(1);
}

file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "dry-run 完成\n";
echo "- 预览输入: {$previewPath}\n";
echo "- 报告输出: {$reportPath}\n";
echo "- 源记录: " . count($records) . "\n";
echo "- 可用记录: " . count($eligible) . "\n";
echo "- 去重后: " . count($deduped) . "\n";
echo "- 匹配合同: " . count($matched) . "\n";
echo "- 未匹配: " . count($unmatched) . "\n";
echo "- 多匹配: " . count($ambiguous) . "\n";
echo "- 已有账单冲突: " . count($conflicts) . "\n";
echo "- 可导入(若执行 commit): " . count($importable) . "\n";
echo "- 待创建房源候选: " . count($bootstrap['properties']) . "\n";
echo "- 待创建合同候选: " . count($bootstrap['contracts']) . "\n";

function connectDb(string $host, int $port, string $dbName, string $user, string $password): PDO
{
    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

    try {
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        fwrite(STDERR, '数据库连接失败: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

function fetchContracts(PDO $pdo): array
{
    $sql = "
        SELECT
            c.id AS contract_id,
            c.contract_number,
            c.tenant_name,
            c.start_date,
            c.end_date,
            c.rent_amount,
            c.payment_day,
            c.contract_status,
            p.property_code,
            p.property_name
        FROM contracts c
        JOIN properties p ON p.id = c.property_id
    ";

    $rows = $pdo->query($sql)->fetchAll();
    return is_array($rows) ? $rows : [];
}

function buildContractIndex(array $contracts): array
{
    $index = [
        'all' => [],
        'by_tenant' => [],
        'by_room' => [],
        'by_tenant_room' => [],
    ];

    foreach ($contracts as $contract) {
        if (!is_array($contract)) {
            continue;
        }

        $tenantKey = normalizeText((string) ($contract['tenant_name'] ?? ''));
        $roomKeys = contractRoomKeys($contract);

        $index['all'][] = $contract;

        if ($tenantKey !== '') {
            $index['by_tenant'][$tenantKey][] = $contract;
        }

        foreach ($roomKeys as $roomKey) {
            $index['by_room'][$roomKey][] = $contract;
            if ($tenantKey !== '') {
                $index['by_tenant_room'][$tenantKey . '|' . $roomKey][] = $contract;
            }
        }
    }

    return $index;
}

function dedupeByContractKey(array $eligible): array
{
    $bucket = [];

    foreach ($eligible as $record) {
        $k = normalizeText((string) ($record['room_no'] ?? ''))
            . '|' . normalizeText((string) ($record['tenant_name'] ?? ''))
            . '|' . (string) ($record['period'] ?? '');

        if (!isset($bucket[$k])) {
            $bucket[$k] = $record;
            continue;
        }

        $oldDate = (string) ($bucket[$k]['date'] ?? '');
        $newDate = (string) ($record['date'] ?? '');
        $oldRow = (int) ($bucket[$k]['row_index'] ?? 0);
        $newRow = (int) ($record['row_index'] ?? 0);

        if ($newDate > $oldDate || ($newDate === $oldDate && $newRow > $oldRow)) {
            $bucket[$k] = $record;
        }
    }

    return array_values($bucket);
}

function matchContracts(array $record, array $index): array
{
    $tenantKey = normalizeText((string) ($record['tenant_name'] ?? ''));
    $roomKey = normalizeRoom((string) ($record['room_no'] ?? ''));
    $date = (string) ($record['date'] ?? '');

    $exactCandidates = $index['by_tenant_room'][$tenantKey . '|' . $roomKey] ?? [];
    $tenantCandidates = $index['by_tenant'][$tenantKey] ?? [];
    $roomCandidates = $index['by_room'][$roomKey] ?? [];

    $ordered = [];
    foreach ([$exactCandidates, $tenantCandidates, $roomCandidates] as $pool) {
        foreach ($pool as $c) {
            $ordered[(string) $c['contract_id']] = $c;
        }
    }

    $candidates = array_values($ordered);
    if (count($candidates) === 0) {
        return [];
    }

    $dateMatched = [];
    foreach ($candidates as $contract) {
        $inRange = $date >= (string) $contract['start_date'] && $date <= (string) $contract['end_date'];
        if ($inRange) {
            $dateMatched[] = $contract;
        }
    }

    if (count($dateMatched) === 1) {
        return $dateMatched;
    }

    if (count($dateMatched) > 1) {
        $active = array_values(array_filter($dateMatched, static function (array $c): bool {
            return (string) ($c['contract_status'] ?? '') === 'active';
        }));

        if (count($active) === 1) {
            return $active;
        }

        return $dateMatched;
    }

    if (count($exactCandidates) === 1) {
        return $exactCandidates;
    }

    return $candidates;
}

function findExistingPayment(PDO $pdo, int $contractId, string $period): ?array
{
    $stmt = $pdo->prepare('SELECT id, payment_number, payment_status, amount_due, paid_date FROM rent_payments WHERE contract_id = ? AND payment_period = ? LIMIT 1');
    $stmt->execute([$contractId, $period]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function buildDueDate(string $period, int $paymentDay): string
{
    $paymentDay = max(1, min(31, $paymentDay));
    $base = DateTimeImmutable::createFromFormat('Y-m-d', $period . '-01');
    if (!$base) {
        return $period . '-01';
    }

    $lastDay = (int) $base->format('t');
    $day = min($paymentDay, $lastDay);

    return $base->setDate((int) $base->format('Y'), (int) $base->format('m'), $day)->format('Y-m-d');
}

function buildNotesPreview(array $record): array
{
    return [
        'bill_type' => 'metered_rent',
        'formula' => [
            'water_previous' => round((float) ($record['formula']['water_previous'] ?? 0), 2),
            'water_current' => round((float) ($record['formula']['water_current'] ?? 0), 2),
            'water_usage' => round((float) ($record['formula']['water_usage'] ?? 0), 2),
            'water_unit_price' => 0,
            'water_fee' => round((float) ($record['formula']['water_fee'] ?? 0), 2),
            'electric_previous' => round((float) ($record['formula']['electric_previous'] ?? 0), 2),
            'electric_current' => round((float) ($record['formula']['electric_current'] ?? 0), 2),
            'electric_usage' => round((float) ($record['formula']['electric_usage'] ?? 0), 2),
            'electric_unit_price' => 0,
            'electric_fee' => round((float) ($record['formula']['electric_fee'] ?? 0), 2),
            'rent_amount' => round((float) ($record['formula']['rent_amount'] ?? 0), 2),
            'total_amount_due' => round((float) ($record['amount_due'] ?? 0), 2),
        ],
        'payment_note' => (string) ($record['remark'] ?? ''),
        'import_meta' => [
            'sheet_name' => $record['sheet_name'] ?? null,
            'row_index' => (int) ($record['row_index'] ?? 0),
            'imported_from' => 'A.xlsx',
            'mode' => 'dry-run-preview',
        ],
    ];
}

function contractRoomKeys(array $contract): array
{
    $keys = [];

    $propertyCode = normalizeRoom((string) ($contract['property_code'] ?? ''));
    if ($propertyCode !== '') {
        $keys[] = $propertyCode;
    }

    $propertyNameKey = normalizeRoom((string) ($contract['property_name'] ?? ''));
    if ($propertyNameKey !== '') {
        $keys[] = $propertyNameKey;
    }

    return array_values(array_unique($keys));
}

function normalizeDate($value): ?string
{
    if ($value === null) {
        return null;
    }

    $str = trim((string) $value);
    if ($str === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
        return $str;
    }

    $ts = strtotime($str);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d', $ts);
}

function normalizeAmount($value): ?float
{
    if ($value === null) {
        return null;
    }

    $str = trim((string) $value);
    if ($str === '') {
        return null;
    }

    $str = str_replace([',', '，', '元', '￥', ' '], '', $str);
    if ($str === '' || !is_numeric($str)) {
        return null;
    }

    return round((float) $str, 2);
}

function normalizeText(string $text): string
{
    $normalized = mb_strtolower(trim($text));
    $normalized = str_replace(['　', ' '], '', $normalized);
    return $normalized;
}

function normalizeRoom(string $room): string
{
    $s = normalizeText($room);
    $s = str_replace(['#', '室', '房', '栋', '单元'], '', $s);
    return preg_replace('/[^0-9a-z\-]/u', '', $s) ?? $s;
}

function buildBootstrapCandidates(array $deduped, array $unmatched): array
{
    $unmatchedKeys = [];
    foreach ($unmatched as $u) {
        $record = $u['record'] ?? null;
        if (!is_array($record)) {
            continue;
        }

        $key = normalizeRoom((string) ($record['room_no'] ?? ''))
            . '|' . normalizeText((string) ($record['tenant_name'] ?? ''));

        $unmatchedKeys[$key] = true;
    }

    $grouped = [];
    foreach ($deduped as $record) {
        $roomNo = (string) ($record['room_no'] ?? '');
        $tenantName = (string) ($record['tenant_name'] ?? '');
        $groupKey = normalizeRoom($roomNo) . '|' . normalizeText($tenantName);

        if (!isset($unmatchedKeys[$groupKey])) {
            continue;
        }

        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'room_no' => $roomNo,
                'tenant_name' => $tenantName,
                'dates' => [],
                'rent_values' => [],
                'source_rows' => [],
            ];
        }

        $date = (string) ($record['date'] ?? '');
        if ($date !== '') {
            $grouped[$groupKey]['dates'][] = $date;
        }

        $rent = normalizeAmount($record['formula']['rent_amount'] ?? null);
        if ($rent !== null && $rent > 0) {
            $grouped[$groupKey]['rent_values'][] = $rent;
        }

        $grouped[$groupKey]['source_rows'][] = [
            'sheet_name' => $record['sheet_name'] ?? null,
            'row_index' => (int) ($record['row_index'] ?? 0),
            'date' => $record['date'] ?? null,
            'period' => $record['period'] ?? null,
            'amount_due' => $record['amount_due'] ?? null,
        ];
    }

    $properties = [];
    $contracts = [];

    foreach ($grouped as $group) {
        $roomNo = (string) ($group['room_no'] ?? '');
        $tenantName = (string) ($group['tenant_name'] ?? '');
        $dates = $group['dates'];
        sort($dates);

        $startDate = $dates[0] ?? null;
        $endDate = $dates[count($dates) - 1] ?? null;
        $rentAmount = inferRentAmount($group['rent_values']);

        $propertyCode = $roomNo;
        $propertyName = $roomNo !== '' ? ('房源 ' . $roomNo) : ('房源-' . $tenantName);

        $properties[] = [
            'property_code' => $propertyCode,
            'property_name' => $propertyName,
            'property_type' => 'apartment',
            'city' => '未填写',
            'address' => '待补充地址',
            'monthly_rent' => $rentAmount,
            'deposit_amount' => 0,
            'property_status' => 'occupied',
            'source_record_count' => count($group['source_rows']),
            'source_rows_sample' => array_slice($group['source_rows'], 0, 20),
        ];

        $contracts[] = [
            'property_code' => $propertyCode,
            'tenant_name' => $tenantName,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rent_amount' => $rentAmount,
            'payment_day' => inferPaymentDay($dates),
            'contract_status' => 'active',
            'source_record_count' => count($group['source_rows']),
            'source_rows_sample' => array_slice($group['source_rows'], 0, 20),
        ];
    }

    return [
        'properties' => $properties,
        'contracts' => $contracts,
    ];
}

function inferRentAmount(array $rentValues): float
{
    if (count($rentValues) === 0) {
        return 0.0;
    }

    sort($rentValues);
    $middle = (int) floor(count($rentValues) / 2);

    if (count($rentValues) % 2 === 1) {
        return (float) $rentValues[$middle];
    }

    return round(((float) $rentValues[$middle - 1] + (float) $rentValues[$middle]) / 2, 2);
}

function inferPaymentDay(array $dates): int
{
    if (count($dates) === 0) {
        return 1;
    }

    $days = [];
    foreach ($dates as $date) {
        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            continue;
        }

        $day = (int) $parts[2];
        if ($day >= 1 && $day <= 31) {
            $days[] = $day;
        }
    }

    if (count($days) === 0) {
        return 1;
    }

    sort($days);
    return (int) $days[(int) floor(count($days) / 2)];
}
