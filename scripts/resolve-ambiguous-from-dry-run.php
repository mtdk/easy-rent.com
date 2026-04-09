<?php

declare(strict_types=1);

/**
 * 解析 dry-run 中的 ambiguous_records，按规则自动决策并产出可补导入列表
 *
 * 规则：同房号优先 -> 日期在合同区间内 -> active 优先 -> 起始日最接近记录日期
 *
 * 用法:
 *   php scripts/resolve-ambiguous-from-dry-run.php [dry_run_report_path] [output_report_path] [db_user] [db_password] [db_name] [db_host] [db_port]
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dryRunPath = $argv[1] ?? __DIR__ . '/../storage/import-preview/a-xlsx-dry-run-report.json';
$outPath = $argv[2] ?? __DIR__ . '/../storage/import-preview/a-xlsx-ambiguous-resolved-report.json';
$dbUser = $argv[3] ?? getenv('DB_USERNAME') ?: 'xmtdk';
$dbPassword = $argv[4] ?? getenv('DB_PASSWORD') ?: '';
$dbName = $argv[5] ?? getenv('DB_DATABASE') ?: 'easy_rent';
$dbHost = $argv[6] ?? getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = (int) ($argv[7] ?? getenv('DB_PORT') ?: 3306);

if (!is_file($dryRunPath)) {
    fwrite(STDERR, "dry-run 报告不存在: {$dryRunPath}\n");
    exit(1);
}

$raw = file_get_contents($dryRunPath);
if ($raw === false || $raw === '') {
    fwrite(STDERR, "无法读取 dry-run 报告: {$dryRunPath}\n");
    exit(1);
}

$report = json_decode($raw, true);
if (!is_array($report)) {
    fwrite(STDERR, "dry-run 报告 JSON 无效\n");
    exit(1);
}

$ambiguous = $report['ambiguous_records'] ?? [];
if (!is_array($ambiguous)) {
    fwrite(STDERR, "dry-run 报告缺少 ambiguous_records 数组\n");
    exit(1);
}

$pdo = connectDb($dbHost, $dbPort, $dbName, $dbUser, $dbPassword);

$resolved = [];
$stillAmbiguous = [];
$unresolvable = [];
$existingConflicts = [];

foreach ($ambiguous as $item) {
    if (!is_array($item)) {
        continue;
    }

    $record = $item['record'] ?? null;
    $candidates = $item['contract_candidates'] ?? null;

    if (!is_array($record) || !is_array($candidates) || count($candidates) === 0) {
        $unresolvable[] = [
            'reason' => '记录或候选合同缺失',
            'item' => $item,
        ];
        continue;
    }

    $pick = chooseCandidate($record, $candidates);
    if ($pick === null) {
        $stillAmbiguous[] = [
            'reason' => '自动规则无法唯一决策',
            'record' => $record,
            'contract_candidates' => $candidates,
        ];
        continue;
    }

    $period = trim((string) ($record['period'] ?? ''));
    $contractId = (int) ($pick['contract_id'] ?? 0);

    if ($contractId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $period)) {
        $unresolvable[] = [
            'reason' => '决策后关键字段无效(contract_id/period)',
            'record' => $record,
            'selected_contract' => $pick,
        ];
        continue;
    }

    $exists = findExistingPayment($pdo, $contractId, $period);
    if ($exists !== null) {
        $existingConflicts[] = [
            'reason' => '账单已存在',
            'record' => $record,
            'selected_contract' => $pick,
            'existing_payment' => $exists,
        ];
        continue;
    }

    $resolved[] = buildImportableRecord($record, $pick);
}

$out = [
    'source_dry_run_report' => realpath($dryRunPath) ?: $dryRunPath,
    'generated_at' => date('c'),
    'resolution_rule' => '同房号优先 -> 日期落区间 -> active优先 -> 起始日最接近',
    'summary' => [
        'ambiguous_input_count' => count($ambiguous),
        'resolved_count' => count($resolved),
        'still_ambiguous_count' => count($stillAmbiguous),
        'unresolvable_count' => count($unresolvable),
        'existing_conflict_count' => count($existingConflicts),
    ],
    'samples' => [
        'resolved' => array_slice($resolved, 0, 60),
        'still_ambiguous' => array_slice($stillAmbiguous, 0, 30),
        'unresolvable' => array_slice($unresolvable, 0, 30),
        'existing_conflicts' => array_slice($existingConflicts, 0, 30),
    ],
    'importable_records' => $resolved,
    'still_ambiguous_records' => $stillAmbiguous,
    'unresolvable_records' => $unresolvable,
    'existing_conflict_records' => $existingConflicts,
];

$dir = dirname($outPath);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "无法创建目录: {$dir}\n");
    exit(1);
}

file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "歧义解析完成\n";
echo "- 输入歧义: " . count($ambiguous) . "\n";
echo "- 可自动决策并可导入: " . count($resolved) . "\n";
echo "- 仍歧义: " . count($stillAmbiguous) . "\n";
echo "- 不可解析: " . count($unresolvable) . "\n";
echo "- 已存在冲突: " . count($existingConflicts) . "\n";
echo "- 输出: {$outPath}\n";

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

function chooseCandidate(array $record, array $candidates): ?array
{
    $room = normalizeRoom((string) ($record['room_no'] ?? ''));
    $date = normalizeDate((string) ($record['date'] ?? ''));
    $tenant = normalizeText((string) ($record['tenant_name'] ?? ''));

    $scored = [];
    foreach ($candidates as $c) {
        if (!is_array($c)) {
            continue;
        }

        $score = 0;

        $cRoom = normalizeRoom((string) ($c['property_code'] ?? ''));
        $cTenant = normalizeText((string) ($c['tenant_name'] ?? ''));

        if ($room !== '' && $room === $cRoom) {
            $score += 1000;
        }

        if ($tenant !== '' && $tenant === $cTenant) {
            $score += 200;
        }

        $start = normalizeDate((string) ($c['start_date'] ?? ''));
        $end = normalizeDate((string) ($c['end_date'] ?? ''));

        if ($date !== null && $start !== null && $end !== null && $date >= $start && $date <= $end) {
            $score += 500;
        }

        if ((string) ($c['status'] ?? '') === 'active') {
            $score += 100;
        }

        if ($date !== null && $start !== null) {
            $distDays = abs((int) ((strtotime($date) - strtotime($start)) / 86400));
            $score += max(0, 50 - min(50, (int) floor($distDays / 30)));
        }

        $scored[] = [
            'score' => $score,
            'candidate' => $c,
        ];
    }

    if (count($scored) === 0) {
        return null;
    }

    usort($scored, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    $best = $scored[0]['candidate'];
    $bestScore = (int) $scored[0]['score'];

    if (count($scored) > 1) {
        $secondScore = (int) $scored[1]['score'];
        if ($bestScore === $secondScore) {
            return null;
        }
    }

    return $best;
}

function buildImportableRecord(array $record, array $contract): array
{
    $period = (string) ($record['period'] ?? '');
    $date = normalizeDate((string) ($record['date'] ?? '')) ?? ($period . '-01');
    $paymentDay = 1;

    return [
        'contract_id' => (int) ($contract['contract_id'] ?? 0),
        'contract_number' => (string) ($contract['contract_number'] ?? ''),
        'period' => $period,
        'due_date' => buildDueDate($period, $paymentDay),
        'amount_due' => normalizeMoney($record['amount_due'] ?? 0),
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'paid_date' => $date,
        'notes_preview' => [
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
                'resolution' => 'auto-ambiguous-rule',
            ],
        ],
        'source' => [
            'sheet_name' => $record['sheet_name'] ?? null,
            'row_index' => (int) ($record['row_index'] ?? 0),
        ],
    ];
}

function findExistingPayment(PDO $pdo, int $contractId, string $period): ?array
{
    $stmt = $pdo->prepare('SELECT id, payment_number, payment_status, amount_due FROM rent_payments WHERE contract_id = ? AND payment_period = ? LIMIT 1');
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

function normalizeDate(string $value): ?string
{
    $s = trim($value);
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
