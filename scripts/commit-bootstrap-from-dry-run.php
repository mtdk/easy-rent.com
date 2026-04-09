<?php

declare(strict_types=1);

/**
 * 基于 dry-run 报告创建房源/合同（默认预演，不写库）
 *
 * 用法:
 *   php scripts/commit-bootstrap-from-dry-run.php [report_json_path] [db_user] [db_password] [db_name] [db_host] [db_port] [--commit]
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$reportPath = $argv[1] ?? __DIR__ . '/../storage/import-preview/a-xlsx-dry-run-report.json';
$dbUser = $argv[2] ?? getenv('DB_USERNAME') ?: 'xmtdk';
$dbPassword = $argv[3] ?? getenv('DB_PASSWORD') ?: '';
$dbName = $argv[4] ?? getenv('DB_DATABASE') ?: 'easy_rent';
$dbHost = $argv[5] ?? getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = (int) ($argv[6] ?? getenv('DB_PORT') ?: 3306);
$commit = in_array('--commit', $argv, true);

if (!is_file($reportPath)) {
    fwrite(STDERR, "报告文件不存在: {$reportPath}\n");
    exit(1);
}

$raw = file_get_contents($reportPath);
if ($raw === false || $raw === '') {
    fwrite(STDERR, "无法读取报告文件: {$reportPath}\n");
    exit(1);
}

$report = json_decode($raw, true);
if (!is_array($report)) {
    fwrite(STDERR, "报告 JSON 无效: {$reportPath}\n");
    exit(1);
}

$bootstrap = $report['bootstrap_candidates'] ?? null;
if (!is_array($bootstrap)) {
    fwrite(STDERR, "报告缺少 bootstrap_candidates\n");
    exit(1);
}

$propertyCandidates = $bootstrap['properties'] ?? [];
$contractCandidates = $bootstrap['contracts'] ?? [];

if (!is_array($propertyCandidates) || !is_array($contractCandidates)) {
    fwrite(STDERR, "bootstrap_candidates 结构无效\n");
    exit(1);
}

$pdo = connectDb($dbHost, $dbPort, $dbName, $dbUser, $dbPassword);
$ownerId = findPreferredOwnerId($pdo);
$creatorId = findAdminId($pdo);

$result = [
    'mode' => $commit ? 'commit' : 'preview',
    'source_report' => realpath($reportPath) ?: $reportPath,
    'generated_at' => date('c'),
    'counts' => [
        'property_candidates' => count($propertyCandidates),
        'contract_candidates' => count($contractCandidates),
        'properties_inserted' => 0,
        'properties_skipped_existing' => 0,
        'contracts_inserted' => 0,
        'contracts_skipped_existing' => 0,
        'contract_skipped_missing_property' => 0,
    ],
    'samples' => [
        'properties_inserted' => [],
        'properties_skipped_existing' => [],
        'contracts_inserted' => [],
        'contracts_skipped_existing' => [],
        'contracts_skipped_missing_property' => [],
    ],
];

$propertyIdByCode = fetchPropertyIdMap($pdo);
$nextPreviewPropertyId = -1;

try {
    if ($commit) {
        $pdo->beginTransaction();
    }

    foreach ($propertyCandidates as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = trim((string) ($row['property_code'] ?? ''));
        if ($code === '') {
            continue;
        }

        if (isset($propertyIdByCode[$code])) {
            $result['counts']['properties_skipped_existing']++;
            appendSample($result['samples']['properties_skipped_existing'], [
                'property_code' => $code,
                'property_id' => (int) $propertyIdByCode[$code],
            ]);
            continue;
        }

        if ($commit) {
            $stmt = $pdo->prepare(
                'INSERT INTO properties (owner_id, property_code, property_name, property_type, address, city, total_area, total_rooms, available_rooms, monthly_rent, deposit_amount, property_status, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );

            $stmt->execute([
                $ownerId,
                $code,
                trim((string) ($row['property_name'] ?? ('房源 ' . $code))),
                'apartment',
                trim((string) ($row['address'] ?? '待补充地址')),
                trim((string) ($row['city'] ?? '未填写')),
                0,
                1,
                0,
                normalizeMoney($row['monthly_rent'] ?? 0),
                normalizeMoney($row['deposit_amount'] ?? 0),
                'occupied',
                '由 A.xlsx 导入脚本生成的初始房源',
            ]);

            $propertyIdByCode[$code] = (int) $pdo->lastInsertId();
        } else {
            // 预演模式使用临时负数ID，便于后续合同阶段继续联动推演
            $propertyIdByCode[$code] = $nextPreviewPropertyId;
            $nextPreviewPropertyId--;
        }

        $result['counts']['properties_inserted']++;
        appendSample($result['samples']['properties_inserted'], [
            'property_code' => $code,
            'property_name' => $row['property_name'] ?? null,
        ]);
    }

    foreach ($contractCandidates as $row) {
        if (!is_array($row)) {
            continue;
        }

        $propertyCode = trim((string) ($row['property_code'] ?? ''));
        $tenantName = trim((string) ($row['tenant_name'] ?? ''));
        $startDate = normalizeDate($row['start_date'] ?? null) ?? date('Y-m-01');
        $endDate = normalizeDate($row['end_date'] ?? null) ?? date('Y-m-t');

        if ($propertyCode === '' || $tenantName === '') {
            continue;
        }

        $propertyId = $propertyIdByCode[$propertyCode] ?? null;
        if ($propertyId === null) {
            $result['counts']['contract_skipped_missing_property']++;
            appendSample($result['samples']['contracts_skipped_missing_property'], [
                'property_code' => $propertyCode,
                'tenant_name' => $tenantName,
            ]);
            continue;
        }

        $exists = findContractByPropertyAndTenant($pdo, (int) $propertyId, $tenantName, $startDate, $endDate);
        if ($exists !== null) {
            $result['counts']['contracts_skipped_existing']++;
            appendSample($result['samples']['contracts_skipped_existing'], [
                'contract_id' => (int) $exists['id'],
                'contract_number' => (string) $exists['contract_number'],
                'property_code' => $propertyCode,
                'tenant_name' => $tenantName,
            ]);
            continue;
        }

        $contractNumber = buildContractNumber($propertyCode, $tenantName, $startDate);

        if ($commit) {
            $stmt = $pdo->prepare(
                'INSERT INTO contracts (property_id, contract_number, tenant_name, tenant_phone, start_date, end_date, rent_amount, deposit_amount, payment_day, payment_method, contract_status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );

            $stmt->execute([
                (int) $propertyId,
                $contractNumber,
                $tenantName,
                null,
                $startDate,
                $endDate,
                normalizeMoney($row['rent_amount'] ?? 0),
                0,
                inferPaymentDay($row['payment_day'] ?? 1),
                'cash',
                'active',
                $creatorId,
            ]);
        }

        $result['counts']['contracts_inserted']++;
        appendSample($result['samples']['contracts_inserted'], [
            'contract_number' => $contractNumber,
            'property_code' => $propertyCode,
            'tenant_name' => $tenantName,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    if ($commit) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($commit && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, '执行失败: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "bootstrap 导入处理完成\n";
echo "- 模式: " . $result['mode'] . "\n";
echo "- 房源候选: " . $result['counts']['property_candidates'] . "\n";
echo "- 合同候选: " . $result['counts']['contract_candidates'] . "\n";
echo "- 新增房源: " . $result['counts']['properties_inserted'] . "\n";
echo "- 跳过已有房源: " . $result['counts']['properties_skipped_existing'] . "\n";
echo "- 新增合同: " . $result['counts']['contracts_inserted'] . "\n";
echo "- 跳过已有合同: " . $result['counts']['contracts_skipped_existing'] . "\n";
echo "- 因缺少房源跳过合同: " . $result['counts']['contract_skipped_missing_property'] . "\n";

autoWriteResult($reportPath, $result);

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

function findPreferredOwnerId(PDO $pdo): int
{
    $row = $pdo->query("SELECT id FROM users WHERE role = 'landlord' AND status = 'active' ORDER BY id ASC LIMIT 1")->fetch();
    if (is_array($row) && isset($row['id'])) {
        return (int) $row['id'];
    }

    $fallback = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetch();
    if (is_array($fallback) && isset($fallback['id'])) {
        return (int) $fallback['id'];
    }

    throw new RuntimeException('未找到可用 owner_id，请先准备 users 数据');
}

function findAdminId(PDO $pdo): int
{
    $row = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC LIMIT 1")->fetch();
    if (is_array($row) && isset($row['id'])) {
        return (int) $row['id'];
    }

    $fallback = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetch();
    if (is_array($fallback) && isset($fallback['id'])) {
        return (int) $fallback['id'];
    }

    throw new RuntimeException('未找到可用 created_by，请先准备 users 数据');
}

function fetchPropertyIdMap(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, property_code FROM properties')->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = trim((string) ($row['property_code'] ?? ''));
        if ($code === '') {
            continue;
        }

        $map[$code] = (int) ($row['id'] ?? 0);
    }

    return $map;
}

function findContractByPropertyAndTenant(PDO $pdo, int $propertyId, string $tenantName, string $startDate, string $endDate): ?array
{
    $stmt = $pdo->prepare('SELECT id, contract_number FROM contracts WHERE property_id = ? AND tenant_name = ? AND start_date = ? AND end_date = ? LIMIT 1');
    $stmt->execute([$propertyId, $tenantName, $startDate, $endDate]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function buildContractNumber(string $propertyCode, string $tenantName, string $startDate): string
{
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($propertyCode));
    if ($prefix === '') {
        $prefix = 'ROOM';
    }

    $tenantPart = mb_substr(preg_replace('/\s+/', '', $tenantName), 0, 2);
    $datePart = str_replace('-', '', $startDate);
    $rand = strtoupper(substr(md5($propertyCode . '|' . $tenantName . '|' . $startDate), 0, 4));

    return 'A-' . $prefix . '-' . $datePart . '-' . $tenantPart . '-' . $rand;
}

function inferPaymentDay($value): int
{
    $n = (int) $value;
    if ($n < 1) {
        return 1;
    }

    if ($n > 31) {
        return 31;
    }

    return $n;
}

function normalizeMoney($value): float
{
    $str = trim((string) $value);
    if ($str === '') {
        return 0.0;
    }

    $str = str_replace([',', '，', '元', '￥', ' '], '', $str);
    if (!is_numeric($str)) {
        return 0.0;
    }

    return round((float) $str, 2);
}

function normalizeDate($value): ?string
{
    if ($value === null) {
        return null;
    }

    $s = trim((string) $value);
    if ($s === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }

    $ts = strtotime($s);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d', $ts);
}

function appendSample(array &$target, array $row): void
{
    if (count($target) >= 50) {
        return;
    }

    $target[] = $row;
}

function autoWriteResult(string $sourceReportPath, array $result): void
{
    $outPath = dirname($sourceReportPath) . '/a-xlsx-bootstrap-commit-result.json';
    file_put_contents($outPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "- 结果输出: {$outPath}\n";
}
