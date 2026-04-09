<?php
/**
 * 收租管理系统 - 数据库初始化脚本
 * 
 * 用于创建数据库和表结构，插入初始数据
 */

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 配置
$config = [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci'
    ],
    'app' => [
        'name' => 'easy_rent',
        'force' => false // 是否强制重新创建数据库
    ]
];

// 命令行参数解析
$options = getopt('h:u:p:d:f', ['host:', 'user:', 'password:', 'database:', 'force']);
if ($options) {
    if (isset($options['h']) || isset($options['host'])) {
        $config['database']['host'] = $options['h'] ?? $options['host'];
    }
    if (isset($options['u']) || isset($options['user'])) {
        $config['database']['username'] = $options['u'] ?? $options['user'];
    }
    if (isset($options['p']) || isset($options['password'])) {
        $config['database']['password'] = $options['p'] ?? $options['password'];
    }
    if (isset($options['d']) || isset($options['database'])) {
        $config['app']['name'] = $options['d'] ?? $options['database'];
    }
    if (isset($options['f']) || isset($options['force'])) {
        $config['app']['force'] = true;
    }
}

// 显示标题
echo "========================================\n";
echo "收租管理系统 - 数据库初始化脚本\n";
echo "版本: 1.0.0\n";
echo "========================================\n\n";

// 显示配置
echo "配置信息:\n";
echo "  数据库主机: " . $config['database']['host'] . "\n";
echo "  数据库端口: " . $config['database']['port'] . "\n";
echo "  数据库名称: " . $config['app']['name'] . "\n";
echo "  用户名: " . $config['database']['username'] . "\n";
echo "  强制模式: " . ($config['app']['force'] ? '是' : '否') . "\n\n";

// 确认操作
if (!$config['app']['force']) {
    echo "警告: 此操作将创建/重置数据库 '" . $config['app']['name'] . "'\n";
    echo "是否继续? (y/N): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) !== 'y') {
        echo "操作已取消。\n";
        exit(0);
    }
}

// 连接到MySQL服务器
try {
    echo "正在连接到MySQL服务器... ";
    $dsn = "mysql:host={$config['database']['host']};port={$config['database']['port']};charset={$config['database']['charset']}";
    $pdo = new PDO($dsn, $config['database']['username'], $config['database']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    echo "成功！\n";
} catch (PDOException $e) {
    echo "失败！\n";
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}

// 检查数据库是否存在
echo "检查数据库是否存在... ";
$stmt = $pdo->query("SHOW DATABASES LIKE '{$config['app']['name']}'");
$databaseExists = $stmt->fetch() !== false;

if ($databaseExists) {
    echo "存在。\n";
    
    if ($config['app']['force']) {
        echo "强制模式启用，删除现有数据库... ";
        try {
            $pdo->exec("DROP DATABASE IF EXISTS `{$config['app']['name']}`");
            echo "成功！\n";
            $databaseExists = false;
        } catch (PDOException $e) {
            echo "失败！\n";
            echo "错误: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        echo "数据库已存在。使用 --force 参数强制重新创建。\n";
        exit(1);
    }
} else {
    echo "不存在。\n";
}

// 创建数据库
echo "创建数据库 '{$config['app']['name']}'... ";
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['app']['name']}` 
                DEFAULT CHARACTER SET {$config['database']['charset']} 
                DEFAULT COLLATE {$config['database']['collation']}");
    echo "成功！\n";
} catch (PDOException $e) {
    echo "失败！\n";
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}

// 选择数据库
echo "选择数据库... ";
try {
    $pdo->exec("USE `{$config['app']['name']}`");
    echo "成功！\n";
} catch (PDOException $e) {
    echo "失败！\n";
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}

// 读取SQL文件
$sqlFile = __DIR__ . '/../storage/database/schema.sql';
echo "读取SQL文件: " . $sqlFile . "\n";

if (!file_exists($sqlFile)) {
    echo "错误: SQL文件不存在！\n";
    exit(1);
}

$sqlContent = file_get_contents($sqlFile);
if ($sqlContent === false) {
    echo "错误: 无法读取SQL文件！\n";
    exit(1);
}

// 分割SQL语句
$sqlStatements = array_filter(array_map('trim', explode(';', $sqlContent)), function($stmt) {
    return !empty($stmt) && substr($stmt, 0, 2) !== '--';
});

$totalStatements = count($sqlStatements);
echo "找到 {$totalStatements} 条SQL语句。\n\n";

// 执行SQL语句
echo "开始执行SQL语句...\n";
$successCount = 0;
$errorCount = 0;

foreach ($sqlStatements as $index => $sql) {
    $statementNumber = $index + 1;
    $progress = round(($statementNumber / $totalStatements) * 100, 1);
    
    echo "  [{$statementNumber}/{$totalStatements}, {$progress}%] ";
    
    // 提取前50个字符作为描述
    $description = substr(trim($sql), 0, 50);
    if (strlen($sql) > 50) {
        $description .= '...';
    }
    
    echo $description . "\n";
    
    try {
        $pdo->exec($sql);
        $successCount++;
    } catch (PDOException $e) {
        $errorCount++;
        echo "    错误: " . $e->getMessage() . "\n";
        
        // 如果是外键约束错误，可能是执行顺序问题，继续执行
        if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
            echo "    (外键约束错误，可能由于执行顺序，继续执行...)\n";
        }
    }
}

echo "\nSQL执行完成:\n";
echo "  成功: {$successCount} 条\n";
echo "  失败: {$errorCount} 条\n\n";

if ($errorCount > 0) {
    echo "警告: 有 {$errorCount} 条SQL语句执行失败。\n";
    echo "可能是由于表依赖关系，建议检查SQL文件。\n\n";
}

// 验证表创建
echo "验证表创建...\n";
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $expectedTables = ['users', 'properties', 'contracts', 'rent_payments', 'financial_records', 'notifications', 'system_settings', 'audit_logs'];
    $createdTables = [];
    
    foreach ($tables as $table) {
        $createdTables[] = $table;
    }
    
    echo "已创建的表 (" . count($createdTables) . " 个):\n";
    foreach ($createdTables as $table) {
        echo "  - {$table}\n";
    }
    
    // 检查缺失的表
    $missingTables = array_diff($expectedTables, $createdTables);
    if (!empty($missingTables)) {
        echo "\n警告: 以下表未创建:\n";
        foreach ($missingTables as $table) {
            echo "  - {$table}\n";
        }
    }
    
} catch (PDOException $e) {
    echo "验证失败: " . $e->getMessage() . "\n";
}

// 插入测试数据（可选）
echo "\n是否插入测试数据? (y/N): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) === 'y') {
    echo "插入测试数据...\n";
    insertTestData($pdo);
}

// 显示总结
echo "\n========================================\n";
echo "数据库初始化完成！\n";
echo "========================================\n\n";

echo "下一步:\n";
echo "1. 修改配置文件中的数据库连接信息\n";
echo "2. 运行应用测试: php -S localhost:8000 -t public\n";
echo "3. 访问 http://localhost:8000 查看应用\n\n";

echo "默认登录账户:\n";
echo "  管理员: admin / admin123\n";
echo "  房东1: landlord1 / landlord123\n";
echo "  房东2: landlord2 / landlord123\n\n";

/**
 * 插入测试数据
 */
function insertTestData(PDO $pdo): void
{
    try {
        // 插入示例房产
        echo "  插入示例房产... ";
        $pdo->exec("INSERT INTO `properties` (`owner_id`, `property_code`, `property_name`, `property_type`, `address`, `city`, `total_area`, `total_rooms`, `available_rooms`, `monthly_rent`, `deposit_amount`, `property_status`, `description`) VALUES
            (2, 'PROP001', '阳光小区A栋101', 'apartment', '北京市朝阳区阳光路1号', '北京', 85.5, 3, 1, 4500.00, 9000.00, 'vacant', '精装修，南北通透，采光好'),
            (2, 'PROP002', '幸福家园B座202', 'apartment', '上海市浦东新区幸福路2号', '上海', 92.0, 2, 0, 5200.00, 10400.00, 'occupied', '简装修，交通便利'),
            (3, 'PROP003', '商业中心商铺', 'commercial', '广州市天河区商业大道3号', '广州', 120.0, 1, 1, 8000.00, 16000.00, 'vacant', '临街商铺，人流量大')");
        echo "成功！\n";
        
        // 插入示例合同
        echo "  插入示例合同... ";
        $pdo->exec("INSERT INTO `contracts` (`property_id`, `contract_number`, `tenant_name`, `tenant_phone`, `start_date`, `end_date`, `rent_amount`, `deposit_amount`, `payment_day`, `contract_status`, `created_by`) VALUES
            (2, 'CONTRACT001', '王五', '13900139003', '2026-01-01', '2026-12-31', 5200.00, 10400.00, 5, 'active', 1),
            (3, 'CONTRACT002', '赵六', '13900139004', '2026-02-01', '2027-01-31', 8000.00, 16000.00, 10, 'active', 1)");
        echo "成功！\n";
        
        // 插入示例租金支付记录
        echo "  插入示例租金支付记录... ";
        $pdo->exec("INSERT INTO `rent_payments` (`contract_id`, `payment_number`, `payment_period`, `due_date`, `paid_date`, `amount_due`, `amount_paid`, `payment_status`) VALUES
            (1, 'PAY202601', '2026-01', '2026-01-05', '2026-01-04', 5200.00, 5200.00, 'paid'),
            (1, 'PAY202602', '2026-02', '2026-02-05', '2026-02-03', 5200.00, 5200.00, 'paid'),
            (1, 'PAY202603', '2026-03', '2026-03-05', '2026-03-06', 5200.00, 5200.00, 'paid'),
            (2, 'PAY202602', '2026-02', '2026-02-10', '2026-02-09', 8000.00, 8000.00, 'paid')");
        echo "成功！\n";
        
        // 插入示例通知
        echo "  插入示例通知... ";
        $pdo->exec("INSERT INTO `notifications` (`user_id`, `type`, `title`, `content`, `priority`) VALUES
            (2, 'reminder', '租金提醒', '您的房产「幸福家园B座202」2026年4月租金5200元将于2026-04-05到期，请及时收取。', 'normal'),
            (1, 'system', '系统更新', '收租管理系统已更新至版本1.0.0，新增了合同管理功能。', 'low')");
        echo "成功！\n";
        
        echo "测试数据插入完成！\n";
        
    } catch (PDOException $e) {
        echo "失败: " . $e->getMessage() . "\n";
    }
}