<?php
/**
 * 课时统计系统 Web 安装向导
 *
 * 安全边界：安装向导只使用用户填写的业务数据库账号，不接收 MySQL root 密码，
 * 不创建或删除数据库用户，不覆盖已存在的应用表。
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

$projectRoot = dirname(__DIR__, 2);
$configDir = $projectRoot . '/config';
$runtimeDir = $projectRoot . '/runtime';
$lockPath = $runtimeDir . '/install.lock';
$installedConfigPath = $configDir . '/installed.php';

if (is_file($lockPath) || is_file($installedConfigPath)) {
    http_response_code(409);
    echo renderPage('系统已安装', '<div class="notice success">系统已完成安装。为安全起见，安装入口已锁定。</div><p>如需重新安装，请先在服务器上备份数据并移除安装锁与安装配置文件。</p>');
    exit;
}

if (!isset($_SESSION['ks_install_csrf'])) {
    $_SESSION['ks_install_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['ks_install_csrf'];

$requirements = checkRequirements($projectRoot, $runtimeDir, $configDir);
$message = '';
$messageType = 'error';
$values = array(
    'hostname' => isset($_POST['hostname']) ? trim((string)$_POST['hostname']) : '127.0.0.1',
    'hostport' => isset($_POST['hostport']) ? trim((string)$_POST['hostport']) : '3306',
    'database' => isset($_POST['database']) ? trim((string)$_POST['database']) : '',
    'username' => isset($_POST['username']) ? trim((string)$_POST['username']) : '',
    'school_name' => isset($_POST['school_name']) ? trim((string)$_POST['school_name']) : '中等职业学校',
    'admin_username' => isset($_POST['admin_username']) ? trim((string)$_POST['admin_username']) : 'admin',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, isset($_POST['csrf']) ? (string)$_POST['csrf'] : '')) {
        $message = '页面已过期，请刷新后重试。';
    } elseif (!$requirements['all_ok']) {
        $message = '服务器环境检测未通过，请先处理标记为“不通过”的项目。';
    } elseif (!isset($_POST['action'])) {
        $message = '未识别安装操作。';
    } elseif ($_POST['action'] === 'test_connection') {
        try {
            $pdo = connectDatabase($values, true);
            $message = '数据库连接成功，可以继续安装。';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = safeInstallError($e);
        }
    } elseif ($_POST['action'] === 'install') {
        $password = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
        $passwordConfirm = isset($_POST['admin_password_confirm']) ? (string)$_POST['admin_password_confirm'] : '';
        try {
            validateInstallInput($values, $password, $passwordConfirm);
            $pdo = connectDatabase($values, true);
            refuseExistingApplicationTables($pdo);
            installDatabase($pdo, $projectRoot . '/database');
            configureAdmin($pdo, $values['admin_username'], $password, $values['school_name']);
            writeInstalledConfig($installedConfigPath, $values);
            writeInstallLock($runtimeDir, $lockPath);
            $message = '安装完成。请使用刚刚设置的管理员账号登录。';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = safeInstallError($e);
        }
    }
}

$body = renderInstaller($requirements, $values, $csrf, $message, $messageType);
echo renderPage('课时统计系统安装向导', $body);

function checkRequirements($projectRoot, $runtimeDir, $configDir)
{
    // 硬性扩展：缺少任何一个都无法运行
    $required = array('pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json');
    // 可选扩展：业务代码未强制使用（fileinfo 无引用），仅作提示，不阻断安装
    $optional = array('fileinfo');
    $checks = array();
    $checks[] = array('name' => 'PHP 版本 >= 7.4', 'ok' => version_compare(PHP_VERSION, '7.4.0', '>='), 'detail' => PHP_VERSION, 'optional' => false);
    foreach ($required as $extension) {
        $checks[] = array('name' => 'PHP 扩展 ' . $extension, 'ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? '已启用' : '未启用', 'optional' => false);
    }
    foreach ($optional as $extension) {
        $checks[] = array('name' => 'PHP 扩展 ' . $extension . '（建议）', 'ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? '已启用' : '未启用（不影响本系统运行）', 'optional' => true);
    }
    foreach (array($runtimeDir, $configDir) as $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $checks[] = array('name' => '目录可写：' . relativePath($projectRoot, $path), 'ok' => is_writable($path), 'detail' => is_writable($path) ? '可写' : '不可写', 'optional' => false);
    }
    $allOk = !in_array(false, array_map(function ($item) {
        return $item['optional'] ? true : $item['ok'];
    }, $checks), true);
    return array('checks' => $checks, 'all_ok' => $allOk);
}

function connectDatabase($values, $withDatabase)
{
    $host = validateHost($values['hostname']);
    $port = validatePort($values['hostport']);
    $database = validateIdentifier($values['database'], '数据库名');
    $username = validateIdentifier($values['username'], '数据库用户名');
    $password = isset($values['password']) ? (string)$values['password'] : (isset($_POST['password']) ? (string)$_POST['password'] : '');
    if ($password === '') {
        throw new RuntimeException('请输入数据库密码。');
    }
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    if ($withDatabase) {
        $dsn .= ';dbname=' . $database;
    }
    return new PDO($dsn, $username, $password, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
}

function validateInstallInput(&$values, $password, $passwordConfirm)
{
    $values['password'] = isset($_POST['password']) ? (string)$_POST['password'] : '';
    validateHost($values['hostname']);
    validatePort($values['hostport']);
    validateIdentifier($values['database'], '数据库名');
    validateIdentifier($values['username'], '数据库用户名');
    validateIdentifier($values['admin_username'], '管理员账号');
    // seed.sql 内置演示教师账号（唯一键冲突会导致建表后中断的半装状态），友好拦截
    if (in_array(strtolower($values['admin_username']), array('wanglaoshi', 'lilaoshi', 'zhaolaoshi'), true)) {
        throw new RuntimeException('管理员账号与内置演示教师账号冲突，请更换账号名。');
    }
    if ($values['school_name'] === '' || strlen($values['school_name']) > 100) {
        throw new RuntimeException('学校名称不能为空且不能超过 100 个字符。');
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        throw new RuntimeException('管理员密码长度须为 8-72 个字符。');
    }
    if ($password !== $passwordConfirm) {
        throw new RuntimeException('两次输入的管理员密码不一致。');
    }
}

function validateHost($host)
{
    if ($host === '' || !preg_match('/^[a-zA-Z0-9.:-]+$/', $host)) {
        throw new RuntimeException('数据库地址格式不正确。');
    }
    return $host;
}

function validatePort($port)
{
    if (!ctype_digit((string)$port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('数据库端口须为 1-65535 之间的数字。');
    }
    return (int)$port;
}

function validateIdentifier($value, $label)
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $value)) {
        throw new RuntimeException($label . '只能包含字母、数字和下划线。');
    }
    return $value;
}

function refuseExistingApplicationTables(PDO $pdo)
{
    $statement = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ks\\_%'");
    $tables = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($tables)) {
        throw new RuntimeException('检测到已有应用表（' . implode('、', array_slice($tables, 0, 5)) . '）。为避免覆盖数据，安装已停止，请使用空数据库。');
    }
}

function installDatabase(PDO $pdo, $databaseDir)
{
    $sqlFiles = array($databaseDir . '/install.sql', $databaseDir . '/seed.sql');
    foreach ($sqlFiles as $path) {
        if (!is_file($path)) {
            throw new RuntimeException('缺少数据库文件：' . basename($path));
        }
        executeSqlFile($pdo, $path);
    }
    $migrationDir = $databaseDir . '/migrations';
    if (is_dir($migrationDir)) {
        $migrations = glob($migrationDir . '/*.sql');
        sort($migrations, SORT_STRING);
        foreach ($migrations as $path) {
            executeSqlFile($pdo, $path);
        }
    }
}

function executeSqlFile(PDO $pdo, $path)
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('无法读取数据库文件：' . basename($path));
    }
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    // 先剥离行注释（-- 到行尾）。注意 SQL 文件的 INSERT 值可能被注释行夹在中间
    // （如 VALUES 列表里插 -- 注释），或 INSERT 前紧跟多行 -- 注释；若不剥离，
    // split 后该语句仍以 -- 开头，会被下方 strpos 误判为纯注释而整条跳过。
    $sql = stripSqlLineComments($sql);
    $statements = splitSqlStatements($sql);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '' || strpos($statement, '--') === 0) {
            continue;
        }
        $pdo->exec($statement);
    }
}

/**
 * 引号感知地剥离 SQL 中的行注释（-- 至行尾），字符串字面量内的 -- 不会被误删。
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
            // 跳到行尾，丢弃注释内容
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            if ($i < $length) {
                $out .= "\n"; // 保留换行，维持行号对齐
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
    $length = strlen($sql);
    $quote = '';
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

function configureAdmin(PDO $pdo, $username, $password, $schoolName)
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = time();
    $statement = $pdo->prepare("UPDATE ks_teacher SET username = ?, password = ?, name = ?, updated_at = ? WHERE id = 1 AND role = 'admin'");
    $statement->execute(array($username, $hash, '系统管理员', $now));
    if ($statement->rowCount() < 1) {
        throw new RuntimeException('种子数据中未找到管理员记录，无法完成管理员初始化。');
    }
    // ks_setting 表无 updated_at 列（列：id/cfg_key/cfg_value/remark），
    // 不要引用该列，否则全新安装时会抛 Unknown column 而中断向导。
    $setting = $pdo->prepare('INSERT INTO ks_setting (cfg_key, cfg_value, remark) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)');
    $setting->execute(array('school_name', $schoolName, '学校名称'));
}

function writeInstalledConfig($path, $values)
{
    $config = array(
        'hostname' => $values['hostname'],
        'database' => $values['database'],
        'username' => $values['username'],
        'password' => $values['password'],
        'hostport' => (int)$values['hostport'],
        'charset' => 'utf8mb4',
        'ai_config_key' => bin2hex(random_bytes(32)),
        'installed_at' => date('c'),
    );
    $content = "<?php\n// 此文件由 Web 安装向导生成，请勿提交到代码仓库。\nreturn " . var_export($config, true) . ";\n";
    $temporaryPath = $path . '.tmp';
    if (file_put_contents($temporaryPath, $content, LOCK_EX) === false || !rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('无法写入安装配置文件，请检查 config 目录权限。');
    }
    @chmod($path, 0600);
}

function writeInstallLock($runtimeDir, $lockPath)
{
    if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0755, true)) {
        throw new RuntimeException('无法创建 runtime 目录。');
    }
    if (file_put_contents($lockPath, date('c') . "\n", LOCK_EX) === false) {
        throw new RuntimeException('无法写入安装锁，请检查 runtime 目录权限。');
    }
    @chmod($lockPath, 0600);
}

function safeInstallError(Exception $exception)
{
    $message = $exception->getMessage();
    if ($exception instanceof PDOException) {
        return '数据库操作失败，请检查地址、端口、库名、账号密码，以及宝塔数据库是否已创建。';
    }
    return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
}

function relativePath($root, $path)
{
    return ltrim(str_replace($root, '', $path), '/\\');
}

function renderInstaller($requirements, $values, $csrf, $message, $messageType)
{
    $html = '<div class="card"><h1>课时统计系统</h1><p class="muted">宝塔环境 Web 安装向导</p>';
    $html .= '<h2>1. 环境检测</h2><div class="checks">';
    foreach ($requirements['checks'] as $check) {
        $dotClass = $check['ok'] ? 'ok' : ($check['optional'] ? 'warn' : 'bad');
        $html .= '<div class="check"><span class="dot ' . $dotClass . '"></span><span>' . htmlspecialchars($check['name'], ENT_QUOTES, 'UTF-8') . '</span><small>' . htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8') . '</small></div>';
    }
    $html .= '</div><h2>2. 数据库与管理员设置</h2>';
    if ($message !== '') {
        $html .= '<div class="notice ' . ($messageType === 'success' ? 'success' : 'error') . '">' . $message . '</div>';
    }
    $html .= '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<div class="grid">';
    $html .= inputField('数据库地址', 'hostname', $values['hostname'], '127.0.0.1');
    $html .= inputField('数据库端口', 'hostport', $values['hostport'], '宝塔 MySQL 常见为 3306');
    $html .= inputField('数据库名', 'database', $values['database'], '请先在宝塔创建空数据库');
    $html .= inputField('数据库用户名', 'username', $values['username'], '使用宝塔创建的专用账号');
    $html .= passwordField('数据库密码', 'password', '不会写入页面或日志');
    $html .= inputField('学校名称', 'school_name', $values['school_name'], '用于系统标题和报表');
    $html .= inputField('管理员账号', 'admin_username', $values['admin_username'], '建议使用新的账号名');
    $html .= passwordField('管理员密码', 'admin_password', '至少 8 个字符');
    $html .= passwordField('确认管理员密码', 'admin_password_confirm', '再次输入管理员密码');
    $html .= '</div><div class="actions"><button name="action" value="test_connection" type="submit" class="secondary">测试数据库连接</button><button name="action" value="install" type="submit" class="primary">开始安装</button></div></form>';
    $html .= '<div class="tips"><strong>安装说明</strong><br>1. 在宝塔创建站点，运行目录指向项目的 <code>public</code> 目录。<br>2. 在宝塔创建一个空 MySQL 数据库和专用账号。<br>3. 填写本页信息并先测试连接，再开始安装。<br>4. 安装完成后入口会自动锁定，不会覆盖已有应用表。</div></div>';
    return $html;
}

function inputField($label, $name, $value, $placeholder)
{
    return '<label><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><input type="text" name="' . $name . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '" required></label>';
}

function passwordField($label, $name, $placeholder)
{
    return '<label><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><input type="password" name="' . $name . '" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '" required></label>';
}

function renderPage($title, $body)
{
    return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>
:root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;color:#172033;background:#f4f7fb}*{box-sizing:border-box}body{margin:0;padding:32px 16px}.card{max-width:860px;margin:auto;background:#fff;border:1px solid #e3e9f2;border-radius:18px;padding:32px;box-shadow:0 12px 35px rgba(24,45,80,.08)}h1{margin:0 0 6px;font-size:30px}h2{margin:28px 0 14px;font-size:18px}.muted,small{color:#69768a}.checks{display:grid;grid-template-columns:1fr 1fr;gap:10px}.check{display:flex;align-items:center;gap:9px;border:1px solid #e5eaf2;border-radius:10px;padding:11px 12px}.check small{margin-left:auto}.dot{width:9px;height:9px;border-radius:50%;display:inline-block}.dot.ok{background:#16a36a}.dot.bad{background:#d94a4a}.dot.warn{background:#eab308}.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}label{display:flex;flex-direction:column;gap:7px;font-size:14px;font-weight:600}input{width:100%;min-height:44px;border:1px solid #cbd5e1;border-radius:9px;padding:10px 12px;font-size:16px;color:#172033}input:focus{outline:3px solid rgba(38,111,255,.15);border-color:#266fff}.actions{display:flex;gap:12px;justify-content:flex-end;margin-top:22px}button{min-height:44px;border:0;border-radius:9px;padding:0 18px;font-size:15px;font-weight:700;cursor:pointer}.primary{background:#266fff;color:#fff}.secondary{background:#eaf0fb;color:#2453a6}.notice{margin:12px 0;padding:12px 14px;border-radius:9px}.notice.error{background:#fff1f1;color:#a52b2b}.notice.success{background:#ecfbf4;color:#147448}.tips{margin-top:24px;background:#f7f9fc;border-radius:10px;padding:14px;line-height:1.8;color:#56647a;font-size:14px}code{background:#edf2f7;border-radius:4px;padding:2px 5px}@media(max-width:700px){body{padding:14px}.card{padding:22px 16px;border-radius:12px}.checks,.grid{grid-template-columns:1fr}.actions{flex-direction:column}.actions button{width:100%}}
</style></head><body>' . $body . '</body></html>';
}
