<?php
/**
 * 收租管理系统 - 数据库管理类
 * 
 * 负责数据库连接、查询构建和事务管理
 */

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    /**
     * @var PDO PDO实例
     */
    private $pdo;
    
    /**
     * @var array 数据库配置
     */
    private $config;
    
    /**
     * @var bool 是否已连接
     */
    private $connected = false;
    
    /**
     * @var Database 单例实例
     */
    private static $instance;
    
    /**
     * 构造函数
     * 
     * @param array $config 数据库配置
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => []
        ], $config);
    }
    
    /**
     * 获取数据库单例实例
     * 
     * @param array $config 数据库配置
     * @return Database
     */
    public static function getInstance(array $config = []): Database
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        
        return self::$instance;
    }
    
    /**
     * 连接到数据库
     * 
     * @return void
     * @throws PDOException 连接失败时抛出异常
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }
        
        try {
            $dsn = $this->buildDsn();
            $options = $this->buildOptions();
            
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );
            
            $this->connected = true;
            
            // 设置连接属性
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
            // 设置字符集
            $this->pdo->exec("SET NAMES {$this->config['charset']} COLLATE {$this->config['collation']}");
            
        } catch (PDOException $e) {
            throw new PDOException(
                "数据库连接失败: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }
    
    /**
     * 构建DSN字符串
     * 
     * @return string DSN字符串
     */
    private function buildDsn(): string
    {
        $driver = $this->config['driver'];
        $host = $this->config['host'];
        $port = $this->config['port'];
        $database = $this->config['database'];
        $charset = $this->config['charset'];
        
        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
                
            case 'pgsql':
                return "pgsql:host={$host};port={$port};dbname={$database}";
                
            case 'sqlite':
                return "sqlite:{$database}";
                
            case 'sqlsrv':
                return "sqlsrv:Server={$host},{$port};Database={$database}";
                
            default:
                throw new PDOException("不支持的数据库驱动: {$driver}");
        }
    }
    
    /**
     * 构建PDO选项
     * 
     * @return array PDO选项数组
     */
    private function buildOptions(): array
    {
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 30
        ];
        
        return array_merge($defaultOptions, $this->config['options']);
    }
    
    /**
     * 获取PDO实例
     * 
     * @return PDO PDO实例
     * @throws PDOException 未连接时抛出异常
     */
    public function getPdo(): PDO
    {
        if (!$this->connected) {
            $this->connect();
        }
        
        return $this->pdo;
    }
    
    /**
     * 执行SQL查询
     * 
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return PDOStatement PDOStatement对象
     * @throws PDOException 查询失败时抛出异常
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->getPdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new PDOException(
                "SQL查询失败: " . $e->getMessage() . "\nSQL: {$sql}",
                (int) $e->getCode(),
                $e
            );
        }
    }
    
    /**
     * 执行SQL语句（不返回结果）
     * 
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return int 受影响的行数
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * 查询单行记录
     * 
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return array|null 单行记录或null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        
        return $result !== false ? $result : null;
    }
    
    /**
     * 查询所有记录
     * 
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return array 所有记录
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * 查询单列值
     * 
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return mixed 单列值
     */
    public function fetchColumn(string $sql, array $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * 插入记录
     * 
     * @param string $table 表名
     * @param array $data 数据数组
     * @return int 插入的ID
     */
    public function insert(string $table, array $data): int
    {
        $table = $this->prefixTable($table);
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($data);
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $this->query($sql, $values);
        
        return (int) $this->getPdo()->lastInsertId();
    }
    
    /**
     * 更新记录
     * 
     * @param string $table 表名
     * @param array $data 数据数组
     * @param array $where 条件数组
     * @return int 受影响的行数
     */
    public function update(string $table, array $data, array $where): int
    {
        $table = $this->prefixTable($table);
        
        // 构建SET子句
        $setParts = [];
        $setValues = [];
        foreach ($data as $column => $value) {
            $setParts[] = "{$column} = ?";
            $setValues[] = $value;
        }
        
        // 构建WHERE子句
        $whereParts = [];
        $whereValues = [];
        foreach ($where as $column => $value) {
            $whereParts[] = "{$column} = ?";
            $whereValues[] = $value;
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);
        
        if (!empty($whereParts)) {
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        $params = array_merge($setValues, $whereValues);
        
        return $this->execute($sql, $params);
    }
    
    /**
     * 删除记录
     * 
     * @param string $table 表名
     * @param array $where 条件数组
     * @return int 受影响的行数
     */
    public function delete(string $table, array $where): int
    {
        $table = $this->prefixTable($table);
        
        // 构建WHERE子句
        $whereParts = [];
        $whereValues = [];
        foreach ($where as $column => $value) {
            $whereParts[] = "{$column} = ?";
            $whereValues[] = $value;
        }
        
        $sql = "DELETE FROM {$table}";
        
        if (!empty($whereParts)) {
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        return $this->execute($sql, $whereValues);
    }
    
    /**
     * 开始事务
     * 
     * @return bool 是否成功
     */
    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }
    
    /**
     * 提交事务
     * 
     * @return bool 是否成功
     */
    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }
    
    /**
     * 回滚事务
     * 
     * @return bool 是否成功
     */
    public function rollback(): bool
    {
        return $this->getPdo()->rollBack();
    }
    
    /**
     * 在事务中执行回调
     * 
     * @param callable $callback 回调函数
     * @return mixed 回调函数的返回值
     * @throws \Exception 执行失败时抛出异常
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * 检查表是否存在
     * 
     * @param string $table 表名
     * @return bool 是否存在
     */
    public function tableExists(string $table): bool
    {
        $table = $this->prefixTable($table);
        
        try {
            $sql = "SELECT 1 FROM {$table} LIMIT 1";
            $this->query($sql);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * 获取表结构
     * 
     * @param string $table 表名
     * @return array 表结构信息
     */
    public function getTableStructure(string $table): array
    {
        $table = $this->prefixTable($table);
        
        $sql = "DESCRIBE {$table}";
        return $this->fetchAll($sql);
    }
    
    /**
     * 获取所有表名
     * 
     * @return array 所有表名
     */
    public function getTables(): array
    {
        $sql = "SHOW TABLES";
        $tables = $this->fetchAll($sql);
        
        return array_map(function($table) {
            return array_values($table)[0];
        }, $tables);
    }
    
    /**
     * 添加表前缀
     * 
     * @param string $table 表名
     * @return string 带前缀的表名
     */
    private function prefixTable(string $table): string
    {
        $prefix = $this->config['prefix'] ?? '';
        
        if ($prefix && strpos($table, $prefix) !== 0) {
            return $prefix . $table;
        }
        
        return $table;
    }
    
    /**
     * 转义标识符
     * 
     * @param string $identifier 标识符
     * @return string 转义后的标识符
     */
    public function quoteIdentifier(string $identifier): string
    {
        return "`{$identifier}`";
    }
    
    /**
     * 转义值
     * 
     * @param mixed $value 值
     * @return string 转义后的值
     */
    public function quote($value): string
    {
        return $this->getPdo()->quote($value);
    }
    
    /**
     * 获取最后执行的SQL
     * 
     * @return string|null 最后执行的SQL
     */
    public function getLastSql(): ?string
    {
        // 注意：PDO不直接提供此功能
        // 在实际应用中，可以通过记录查询日志来实现
        return null;
    }
    
    /**
     * 关闭数据库连接
     * 
     * @return void
     */
    public function close(): void
    {
        $this->pdo = null;
        $this->connected = false;
    }
    
    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->close();
    }
}