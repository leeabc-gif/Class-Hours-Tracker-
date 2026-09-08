<?php
namespace app\common\service;

use think\Db;

/**
 * 数据库整库备份（管理员专用）
 *
 * 生成标准 SQL 文本：DROP TABLE + CREATE TABLE + INSERT，
 * 直接用 phpMyAdmin / 命令行 mysql 导入即可还原。
 *
 * 只备份 ks_ 前缀的业务表，避免把 MySQL 系统库一起拖进来。
 */
class Backup
{
    /** 备份文件表前缀（只备份业务表） */
    const TABLE_PREFIX = 'ks_';

    /**
     * 生成整库 SQL
     * @return array ['sql'=>string, 'tables'=>int, 'rows'=>int, 'size'=>int]
     */
    public static function dump()
    {
        $pdo       = self::rawPdo();
        $dbName    = config('database.database');
        $ver       = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        $time      = date('Y-m-d H:i:s');

        $lines   = [];
        $lines[] = '-- ============================================================';
        $lines[] = '-- 多教师课时统计与课酬核算系统 —— 数据库备份';
        $lines[] = "-- 备份时间：{$time}";
        $lines[] = "-- 数据库：{$dbName}";
        $lines[] = "-- 服务器：MySQL {$ver}";
        $lines[] = '';
        $lines[] = '-- ============================================================';
        $lines[] = '';
        $lines[] = 'SET NAMES utf8mb4;';
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $lines[] = '';

        $tableRows = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_ASSOC);
        $tables    = [];
        foreach ($tableRows as $tr) {
            $name = current($tr);
            if (strpos($name, self::TABLE_PREFIX) === 0) {
                $tables[] = $name;
            }
        }
        sort($tables);

        $totalRows = 0;
        foreach ($tables as $t) {
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$t}`");
            $createRow  = $createStmt ? $createStmt->fetch(\PDO::FETCH_ASSOC) : false;
            $createSql  = (is_array($createRow) && isset($createRow['Create Table']))
                ? $createRow['Create Table'] : '';

            // 必须-4：SHOW CREATE 失败/被权限拒绝/目标是视图时，$createSql 仍可能为非空但不含合法 CREATE TABLE
            // 之前会拼出"无 CREATE TABLE 却有 INSERT"的半成品 SQL，导入必失败。
            // 这里强制校验：若为空，或不是 CREATE TABLE 开头（视图会拿到 Create View），
            // 一律抛错停止整库备份，避免静默数据丢失。
            if (!is_string($createSql) || $createSql === '' || stripos(ltrim($createSql), 'CREATE TABLE') !== 0) {
                throw new \RuntimeException(
                    "无法读取表 {$t} 的 CREATE TABLE 语句（可能是视图或权限不足），已中止整库备份以避免半成品 SQL。"
                );
            }

            $lines[] = '-- ----------------------------';
            $lines[] = "-- 表结构：{$t}";
            $lines[] = '-- ----------------------------';
            $lines[] = "DROP TABLE IF EXISTS `{$t}`;";
            $lines[] = $createSql . ';';
            $lines[] = '';

            $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) {
                $lines[] = "-- 表 {$t} 无数据";
                $lines[] = '';
                continue;
            }

            $lines[] = "-- 表数据：{$t}（" . count($rows) . " 行）";
            $totalRows += count($rows);

            // 每 200 行一个 INSERT，避免单条语句过长被 max_allowed_packet 拒绝
            $cols    = array_keys($rows[0]);
            // M-9：列名加反引号并把 ` 转义成 ``，避免特殊字符污染 SQL
            $colList = '`' . implode('`,`', array_map(
                static function ($c) { return str_replace('`', '``', (string) $c); },
                $cols
            )) . '`';
            $chunks  = array_chunk($rows, 200);
            foreach ($chunks as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    $vals = [];
                    foreach ($cols as $c) {
                        $v = $row[$c];
                        if ($v === null) {
                            $vals[] = 'NULL';
                        } else {
                            // M-5：二进制/非字符串字段用 quote() 强转；如果发现是二进制流（BLOB 痕迹），
                            // 直接报错，避免静默截断。当前 schema 全是文本/数字，未必命中，但保留防线。
                            if (is_resource($v)) {
                                throw new \RuntimeException("表 {$t} 字段 {$c} 出现 BLOB 资源类型，暂不支持自动备份。");
                            }
                            $vals[] = $pdo->quote((string) $v);
                        }
                    }
                    $values[] = '(' . implode(',', $vals) . ')';
                }
                $lines[] = "INSERT INTO `{$t}` ({$colList}) VALUES " . implode(',' . PHP_EOL, $values) . ';';
            }
            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $lines[] = "-- 备份结束，共 {$totalRows} 行数据";

        $sql = implode(PHP_EOL, $lines);
        return [
            'sql'    => $sql,
            'tables' => count($tables),
            'rows'   => $totalRows,
            'size'   => strlen($sql),
        ];
    }

    /**
     * 构造原生 PDO 连接（只读备份专用）
     *
     * 不用 Db::connect()：那在 ThinkPHP 5.1 返回的是 Query 对象，
     * Query::getPdo() 会尝试解析“操作表”，空表名会抛
     * SQLSTATE 1103 (Incorrect table name '')。备份是纯 SQL 读取，
     * 直接用 config 参数建原生 PDO 最稳，不依赖 ORM 表上下文。
     */
    public static function rawPdo()
    {
        // 直接读取 installed.php（避免依赖 config() 在某些上下文中不可用）
        $installedPath = __DIR__ . '/../../../config/installed.php';
        $runtimeConfig = is_file($installedPath) ? (require $installedPath) : [];
        if (!is_array($runtimeConfig)) {
            $runtimeConfig = [];
        }

        $required = ['hostname', 'hostport', 'database', 'username', 'password'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $runtimeConfig) || (string) $runtimeConfig[$key] === '') {
                throw new \RuntimeException('数据库连接配置不完整，缺少 ' . $key . '。');
            }
        }
        $host    = (string) $runtimeConfig['hostname'];
        $port    = (string) $runtimeConfig['hostport'];
        $name    = (string) $runtimeConfig['database'];
        $user    = (string) $runtimeConfig['username'];
        $pass    = (string) $runtimeConfig['password'];
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        try {
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            // M-7：不把 PDO 原始错误（含 host/port/凭据提示）冒泡出去，
            // 统一映射成"数据库连接失败"文案，详细错误走 Log。
            \think\facade\Log::error('[backup] rawPdo connect failed: ' . $e->getMessage(), [
                'host' => $host,
                'port' => $port,
                'db'   => $name,
            ]);
            throw new \RuntimeException('数据库连接失败，请检查 installed.php 配置。');
        }
        return $pdo;
    }
}
