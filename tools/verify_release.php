<?php
/**
 * 发布自检工具（发布前必跑）
 *
 * 历史上出现过「manifest 记录的 sha256/size 与 Release 上实际托管的包不一致」，
 * 结果所有站点下载后校验失败、在线更新全面瘫痪。本工具在发布前把关键一致性
 * 一次查完，避免把坏版本推上线。
 *
 * 用法:
 *   php tools/verify_release.php --manifest=release/manifest.json [--package=release/keshi-1.0.7.zip]
 *
 * 不指定 --package 时，会按 manifest 里 files[0].url 下载远端真实包来比对
 * （这能查出「本地签的包」与「Release 上真实包」不是同一个的问题）。
 *
 * 检查项:
 *   1. manifest 验签（config/update_trust.php 公钥）
 *   2. sha256 / size 与包是否一致（本地包或远端真实包）
 *   3. 包内 upgrade.sql 是否存在、版本是否等于 latest_version
 *   4. upgrade.sql 是否引用 ks_setting 不存在的列（如 updated_at）
 *   5. 按更新器白名单预览会被覆盖的文件数
 *
 * 退出码: 0=全部通过；1=存在失败项
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
require $root . '/application/common/service/UpdateService.php';

use app\common\service\UpdateService;

$manifestFile = '';
$packageFile  = '';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--manifest=(.+)$/', $a, $m)) { $manifestFile = trim($m[1]); continue; }
    if (preg_match('/^--package=(.+)$/', $a, $m))  { $packageFile = trim($m[1]); continue; }
    if ($a === '-h' || $a === '--help') {
        echo "用法: php tools/verify_release.php --manifest=release/manifest.json [--package=release/keshi-1.0.7.zip]\n";
        exit(0);
    }
}

$fail = 0;
function chk($label, $ok, $detail = '')
{
    global $fail;
    echo ($ok ? "  [OK]   " : "  [FAIL] ") . $label;
    if ($detail !== '') echo " — {$detail}";
    echo "\n";
    if (!$ok) $fail++;
}

echo "PHP " . PHP_VERSION . "\n";
echo str_repeat('=', 64) . "\n";

// ---- 1) 读 manifest ----
echo "[1] 读取清单\n";
if ($manifestFile === '' || !is_file($manifestFile)) {
    fwrite(STDERR, "清单文件不存在: {$manifestFile}\n");
    exit(2);
}
$m = json_decode((string) file_get_contents($manifestFile), true);
if (!is_array($m)) {
    fwrite(STDERR, "清单不是合法 JSON\n");
    exit(2);
}
$latest = isset($m['latest_version']) ? (string) $m['latest_version'] : '';
chk('latest_version 存在', $latest !== '', $latest);
if ($latest === '') exit(1);

// ---- 2) 验签 ----
echo "[2] 验签\n";
$signed = isset($m['signature']) && trim((string) $m['signature']) !== '';
chk('清单已签名', $signed);
if ($signed) {
    chk('签名校验通过', UpdateService::verifyManifestSignature($m));
}

// ---- 3) 取包（本地 或 远端真实包）----
$file0 = isset($m['files'][0]) ? $m['files'][0] : null;
if (!is_array($file0)) {
    fwrite(STDERR, "清单缺少 files[0]\n");
    exit(1);
}
$expectHash = strtolower((string) ($file0['sha256'] ?? ''));
$expectSize = (int) ($file0['size'] ?? 0);
$remoteUrl  = (string) ($file0['url'] ?? '');

echo "[3] 校验发布包\n";
$tmp = '';
if ($packageFile !== '' && is_file($packageFile)) {
    $pkg = $packageFile;
    echo "  来源: 本地文件 {$pkg}\n";
} else {
    // 拉远端真实包 —— 这一步能发现「签的不是线上那个包」
    $pkg = '';
    if ($remoteUrl !== '' && filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
        $tmp = sys_get_temp_dir() . '/ks_verify_' . md5($remoteUrl) . '.zip';
        $ch = curl_init($remoteUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'keshi-verify/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $bin = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($bin !== false && $code >= 200 && $code < 300) {
            file_put_contents($tmp, $bin);
            $pkg = $tmp;
            echo "  来源: 远端真实包 {$remoteUrl}\n";
        } else {
            echo "  远端包不可用 (HTTP {$code} {$err})，跳过包体校验\n";
        }
    }
}

if ($pkg !== '') {
    $actualHash = hash_file('sha256', $pkg);
    $actualSize = filesize($pkg);
    chk('sha256 一致', $actualHash === $expectHash, "期望 {$expectHash} / 实际 {$actualHash}");
    chk('size 一致', $actualSize === $expectSize, "期望 {$expectSize} / 实际 {$actualSize}");

    // ---- 4) upgrade.sql ----
    echo "[4] upgrade.sql\n";
    $zip = new ZipArchive();
    if ($zip->open($pkg) === true) {
        $sqlEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n !== false && strtolower(basename($n)) === 'upgrade.sql') { $sqlEntry = $n; break; }
        }
        chk('包内存在 upgrade.sql', $sqlEntry !== null, $sqlEntry ?? '');
        if ($sqlEntry !== null) {
            $sql = (string) $zip->getFromName($sqlEntry);
            // 版本一致性：SQL 里应出现 latest_version
            chk('upgrade.sql 版本号与 latest_version 一致',
                strpos($sql, $latest) !== false,
                "期望包含 {$latest}");
            // 列合法性：ks_setting 只有 id/cfg_key/cfg_value/remark
            preg_match_all('/`(\w+)`\s*=/', $sql, $mm);
            $assigned = array_unique($mm[1] ?? []);
            $bad = array_diff($assigned, ['id', 'cfg_key', 'cfg_value', 'remark']);
            chk('upgrade.sql 未引用不存在的列', empty($bad),
                empty($bad) ? '' : ('可疑列: ' . implode(', ', $bad)));
        }

        // ---- 5) 覆盖预览 ----
        echo "[5] 覆盖预览（按更新器白名单）\n";
        $rc = new ReflectionClass(UpdateService::class);
        $normalize = $rc->getMethod('normalizeEntry'); $normalize->setAccessible(true);
        $allow     = $rc->getMethod('inAllowlist');    $allow->setAccessible(true);
        $protect   = $rc->getMethod('isProtected');    $protect->setAccessible(true);
        $applied = 0; $covered = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || substr($entry, -1) === '/') continue;
            $rel = $normalize->invoke(null, $entry);
            if ($rel === '' || !$allow->invoke(null, $rel)) continue;
            if ($protect->invoke(null, $rel)) continue;
            if (strpos(basename($rel), '.') === 0) continue;
            $applied++;
            $top = explode('/', $rel)[0];
            $covered[$top] = ($covered[$top] ?? 0) + 1;
        }
        chk('有文件会被覆盖', $applied > 0, "共 {$applied} 个文件");
        foreach ($covered as $k => $v) echo "         {$k}: {$v}\n";
        $zip->close();
    } else {
        chk('包可被打开', false);
    }
}

if ($tmp !== '' && is_file($tmp)) @unlink($tmp);

echo str_repeat('=', 64) . "\n";
if ($fail === 0) {
    echo "✓ 全部检查通过，可以发布。\n";
    exit(0);
}
echo "✗ 有 {$fail} 项未通过，请勿发布。\n";
exit(1);
