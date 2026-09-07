<?php
/**
 * 数据库初始化脚本（高级运维入口）
 *
 * 仅连接宝塔中已经创建好的空数据库，不创建/删除数据库用户，不接收 root 密码。
 * 用法示例：
 *   KS_DB_PASSWORD='数据库密码' php setup_db.php --host=127.0.0.1 --port=3306 --database=keshi --user=keshi
 *   需要覆盖已有 ks_ 表时，必须显式追加 --force，并先完成数据库备份。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

$options = getopt('', array('host:', 'port:', 'database:', 'user:', 'password-env::', 'force'));
$host = isset($options['host']) ? (string)$options['host'] : '127.0.0.1';
$port = isset($options['port']) ? (int)$options['port'] : 3306;
$dbName = isset($options['database']) ? (string)$options['database'] : '';
$dbUser = isset($options['user']) ? (string)$options['user'] : '';
$passwordEnv = isset($options['password-env']) && $options['password-env'] !== false ? (string)$options['password-env'] : 'KS_DB_PASSWORD';
$dbPass = getenv($passwordEnv);
$force = array_key_exists('force', $options);

try {
    validateHost($host);
    validatePort($port);
    validateIdentifier($dbName, 'database');
    validateIdentifier($dbUser, 'user');
    if ($dbPass === false || $dbPass === '') {
        throw new RuntimeException('请通过环境变量 ' . $passwordEnv . ' 提供数据库密码。');
    }

    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser,
        $dbPass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    out('[OK] 已连接目标数据库。');

    if (!$force && hasApplicationTables($pdo)) {
        throw new RuntimeException('检测到已有 ks_ 应用表。为避免覆盖数据，已停止；确认已有备份后再追加 --force。');
    }

    $base = dirname(__DIR__);
    $sqlDir = $base . '/database';
    foreach (array($sqlDir . '/install.sql', $sqlDir . '/seed.sql') as $path) {
        if (!is_file($path)) {
            throw new RuntimeException('缺少数据库文件：' . basename($path));
        }
        executeSqlFile($pdo, $path);
        out('[OK] ' . basename($path) . ' 导入完成。');
    }

    $migrationDir = $sqlDir . '/migrations';
    $migrations = is_dir($migrationDir) ? glob($migrationDir . '/*.sql') : array();
    sort($migrations, SORT_STRING);
    foreach ($migrations as $path) {
        executeSqlFile($pdo, $path);
        out('[OK] migration/' . basename($path) . ' 执行完成。');
    }

    out('[OK] 初始化完成。默认管理员密码仍来自 seed.sql，请登录后立即修改。');
} catch (PDOException $e) {
    out('[ERROR] 数据库操作失败，请检查数据库地址、端口、库名、账号密码和权限。');
    exit(1);
} catch (Exception $e) {
    out('[ERROR] ' . $e->getMessage());
    exit(1);
}

function validateHost($host)
{
    if ($host === '' || !preg_match('/^[a-zA-Z0-9.:-]+$/', $host)) {
        throw new RuntimeException('host 参数格式不正确。');
    }
}

function validatePort($port)
{
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('port 参数须在 1-65535 之间。');
    }
}

function validateIdentifier($value, $name)
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $value)) {
        throw new RuntimeException($name . ' 参数只能包含字母、数字和下划线。');
    }
}

function hasApplicationTables(PDO $pdo)
{
    $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ks\\_%'");
    return (int)$statement->fetchColumn() > 0;
}

function executeSqlFile(PDO $pdo, $path)
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('无法读取数据库文件：' . basename($path));
    }
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    // 先剥离行注释：INSERT 前可能紧跟多行 -- 注释，或 VALUES 列表中间夹 -- 注释；
    // 若不剥离，split 后该语句仍以 -- 开头，会被 strpos 误判为纯注释而整条跳过。
    $sql = stripSqlLineComments($sql);
    foreach (splitSqlStatements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '' || strpos($statement, '--') === 0) {
            continue;
        }
        $pdo->exec($statement);
    }
}

/**
 * 引号感知地剥离 SQL 行注释（-- 至行尾），字符串字面量内的 -- 不会被误删。
 */
function stripSqlLineComments($sql)
{
    $length = strlen($sql);
    $out = '';
    $quote = '';
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = ($i + 1 < $length) ? $sql[$i + 1] : '';
        if ($quote !== '') {
            $out .= $char;
            if ($char === '\\') {
                if ($i + 1 < $length) {
                    $out .= $sql[++$i];
                }
            } elseif ($char === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $out .= $char;
            continue;
        }
        if ($char === '-' && $next === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            if ($i < $length) {
                $out .= "\n";
            }
            continue;
        }
        $out .= $char;
    }
    return $out;
}

function splitSqlStatements($sql)
{
    $statements = array();
    $buffer = '';
    $quote = '';
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $buffer .= $char;
        if ($quote !== '') {
            if ($char === '\\' && $i + 1 < $length) {
                $buffer .= $sql[++$i];
            } elseif ($char === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
        } elseif ($char === ';') {
            $statements[] = substr($buffer, 0, -1);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }
    return $statements;
}

function out($message)
{
    echo $message . PHP_EOL;
}
