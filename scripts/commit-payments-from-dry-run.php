<?php

declare(strict_types=1);

/**
 * 基于 dry-run 报告导入账单（默认预演，--commit 才写库）
 *
 * 用法:
 *   php scripts/commit-payments-from-dry-run.php [report_json_path] [db_user] [db_password] [db_name] [db_host] [db_port] [--commit]
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

$records = $report['importable_records'] ?? [];
if (!is_array($records)) {
    fwrite(STDERR, "报告缺少 importable_records\n");
    exit(1);
}

$pdo = connectDb($dbHost, $dbPort, $dbName, $dbUser, $dbPassword);
$adminId = findAdminId($pdo);

$result = [
    'mode' => $commit ? 'commit' : 'preview',
    'source_report' => realpath($reportPath) ?: $reportPath,
    'generated_at' => date('c'),
    'counts' => [
        'records_in_report' => count($records),
        'inserted_payments' => 0,
        'inserted_financial_records' => 0,
        'skipped_existing_payment' => 0,
        'skipped_invalid' => 0,
    ],
    'samples' => [
        'inserted_payments' => [],
        'skipped_existing_payment' => [],
        'skipped_invalid' => [],
    ],
];

$now = date('Y-m-d H:i:s');

try {
    if ($commit) {
        $pdo->beginTransaction();
    }

    foreach ($records as $idx => $row) {
        if (!is_array($row)) {
            $result['counts']['skipped_invalid']++;
            appendSample($result['samples']['skipped_invalid'], [
                'reason' => '记录结构无效',
                'index' => $idx,
            ]);
            continue;
        }

        $contractId = (int) ($row['contract_id'] ?? 0);
        $period = trim((string) ($row['period'] ?? ''));
        $dueDate = normalizeDate($row['due_date'] ?? null) ?? ($period . '-01');
        $paidDate = normalizeDate($row['paid_date'] ?? null) ?? $dueDate;
        $amountDue = normalizeMoney($row['amount_due'] ?? 0);
        $method = trim((string) ($row['payment_method'] ?? 'cash'));

        if ($contractId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $period) || $amountDue <= 0) {
            $result['counts']['skipped_invalid']++;
            appendSample($result['samples']['skipped_invalid'], [
                'reason' => '关键字段无效',
                'contract_id' => $contractId,
                'period' => $period,
                'amount_due' => $amountDue,
            ]);
            continue;
        }

        $exists = findExistingPayment($pdo, $contractId, $period);
        if ($exists !== null) {
            $result['counts']['skipped_existing_payment']++;
            appendSample($result['samples']['skipped_existing_payment'], [
                'contract_id' => $contractId,
                'period' => $period,
                'existing_payment_id' => (int) ($exists['id'] ?? 0),
                'existing_payment_number' => (string) ($exists['payment_number'] ?? ''),
            ]);
            continue;
        }

        $paymentNumber = buildPaymentNumber($period, $idx);
        $notesJson = json_encode($row['notes_preview'] ?? null, JSON_UNESCAPED_UNICODE);

        if ($commit) {
            $insertPayment = $pdo->prepare(
                'INSERT INTO rent_payments (contract_id, payment_number, payment_period, due_date, paid_date, amount_due, amount_paid, payment_method, payment_status, late_fee, discount, notes, confirmed_by, confirmed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $insertPayment->execute([
                $contractId,
                $paymentNumber,
                $period,
                $dueDate,
                $paidDate,
                $amountDue,
                $amountDue,
                normalizeMethod($method),
                'paid',
                0,
                0,
                $notesJson,
                $adminId,
                $now,
                $now,
                $now,
            ]);

            $paymentId = (int) $pdo->lastInsertId();

            $insertFinancial = $pdo->prepare(
                'INSERT INTO financial_records (record_type, category, amount, currency, description, reference_type, reference_id, payment_method, transaction_date, recorded_by, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $insertFinancial->execute([
                'income',
                'rent',
                $amountDue,
                'CNY',
                '历史导入到账: ' . $paymentNumber,
                'rent_payment',
                $paymentId,
                normalizeMethod($method),
                $paidDate,
                $adminId,
                'A.xlsx 历史账单导入',
                $now,
                $now,
            ]);

            $result['counts']['inserted_financial_records']++;
        }

        $result['counts']['inserted_payments']++;
        appendSample($result['samples']['inserted_payments'], [
            'contract_id' => $contractId,
            'period' => $period,
            'payment_number' => $paymentNumber,
            'amount_due' => $amountDue,
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

echo "payments 导入处理完成\n";
echo "- 模式: " . $result['mode'] . "\n";
echo "- 报告记录: " . $result['counts']['records_in_report'] . "\n";
echo "- 新增账单: " . $result['counts']['inserted_payments'] . "\n";
echo "- 新增财务流水: " . $result['counts']['inserted_financial_records'] . "\n";
echo "- 跳过已存在账单: " . $result['counts']['skipped_existing_payment'] . "\n";
echo "- 跳过无效记录: " . $result['counts']['skipped_invalid'] . "\n";

writeResultFile($reportPath, $result);

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

    throw new RuntimeException('未找到可用管理员用户');
}

function findExistingPayment(PDO $pdo, int $contractId, string $period): ?array
{
    $stmt = $pdo->prepare('SELECT id, payment_number FROM rent_payments WHERE contract_id = ? AND payment_period = ? LIMIT 1');
    $stmt->execute([$contractId, $period]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function buildPaymentNumber(string $period, int $idx): string
{
    $base = str_replace('-', '', $period);
    $suffix = strtoupper(substr(sha1($period . '|' . $idx . '|' . microtime(true)), 0, 8));

    return 'IMP' . $base . $suffix;
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

function normalizeMethod(string $method): string
{
    $method = strtolower(trim($method));
    $allowed = ['cash', 'bank_transfer', 'alipay', 'wechat_pay', 'other'];
    return in_array($method, $allowed, true) ? $method : 'cash';
}

function appendSample(array &$target, array $row): void
{
    if (count($target) >= 80) {
        return;
    }

    $target[] = $row;
}

function writeResultFile(string $sourceReportPath, array $result): void
{
    $outPath = dirname($sourceReportPath) . '/a-xlsx-payments-commit-result.json';
    file_put_contents($outPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "- 结果输出: {$outPath}\n";
}
