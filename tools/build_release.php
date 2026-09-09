<?php
/**
 * 发布包构建工具（与 CI 规则保持一致）
 *
 * 目的：历史上出现过「手工只打包改动过的 8 个文件」导致发布包残缺、
 *      以及 manifest 与实际 zip 不一致导致在线更新校验失败的问题。
 *      本工具把打包规则固化下来，本地与 CI 使用同一套白/黑名单。
 *
 * 用法:
 *   php tools/build_release.php --version=1.0.7 [--out=release/keshi-1.0.7.zip]
 *
 * 行为:
 *   1) 按版本号挑选 upgrade.sql（优先 *_v{107}_version_sync.sql，退路取最大版本）
 *   2) 复制到项目根（更新器通过 basename 识别）
 *   3) 按 CI 同样的排除规则打包整个项目
 *   4) 输出 zip，并打印 sha256 供签名使用
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);

$version = '';
$out     = '';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--version=(.+)$/', $a, $m)) { $version = trim($m[1]); continue; }
    if (preg_match('/^--out=(.+)$/', $a, $m))     { $out = trim($m[1]); continue; }
    if ($a === '-h' || $a === '--help') {
        echo "用法: php tools/build_release.php --version=1.0.7 [--out=release/keshi-1.0.7.zip]\n";
        exit(0);
    }
}

if ($version === '') {
    fwrite(STDERR, "缺少 --version=参数。\n");
    exit(2);
}

// ---------- 1) 挑选 upgrade.sql ----------
$plain   = str_replace('.', '', $version);
$migDir  = $root . '/database/migrations';
$candidates = [
    $migDir . "/20260909_v{$plain}_version_sync.sql",
    $migDir . "/20260909_{$plain}_version_sync.sql",
];
$mig = '';
foreach ($candidates as $c) {
    if (is_file($c)) { $mig = $c; break; }
}
if ($mig === '') {
    // 退路：取版本号最大的 *_version_sync.sql
    $all = glob($migDir . '/*_version_sync.sql') ?: [];
    sort($all);
    $mig = $all ? end($all) : '';
}
if ($mig !== '' && is_file($mig)) {
    copy($mig, $root . '/upgrade.sql');
    echo "upgrade.sql <- " . basename($mig) . "\n";
} else {
    echo "未找到 *_version_sync.sql，本次发布包不含 upgrade.sql\n";
}

// ---------- 2) 打包 ----------
// 与 .github/workflows/release.yml 及 .cnb.yml 保持一致的排除规则
$excludePrefixes = [
    'runtime/',
    'config/installed.php',
    'config/database.php',
    'public/install/',
    '.git/',
    'release/',
    '.github/',
    '_env/',
    '.codebuddy/',
    '.workbuddy/',
    'tools/sign_manifest.php',
];

$outZip = $out !== '' ? $out : ($root . "/release/keshi-{$version}.zip");
if (!is_dir(dirname($outZip))) {
    @mkdir(dirname($outZip), 0755, true);
}
if (is_file($outZip)) {
    @unlink($outZip);
}

$zip = new ZipArchive();
if ($zip->open($outZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "无法创建 zip: {$outZip}\n");
    exit(2);
}

$files = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $path => $info) {
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if ($rel === '') continue;

    // 排除规则（前缀匹配，目录则带 /）
    $skip = false;
    foreach ($excludePrefixes as $ex) {
        if ($rel === rtrim($ex, '/') || strpos($rel, $ex) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    if ($info->isDir()) {
        // 目录项：保持与 Linux zip 一致的尾斜杠
        $zip->addEmptyDir($rel);
        continue;
    }
    if (!$info->isFile()) continue;

    $zip->addFile($path, $rel);
    $files++;
}

$zip->close();

// 清理临时 upgrade.sql
if (is_file($root . '/upgrade.sql')) {
    @unlink($root . '/upgrade.sql');
}

$size = filesize($outZip);
$hash = hash_file('sha256', $outZip);
echo "已生成: {$outZip}\n";
echo "  文件数 = {$files}\n";
echo "  大小   = {$size} 字节\n";
echo "  sha256 = {$hash}\n";
echo "\n下一步（签名）：\n";
echo "  php tools/sign_manifest.php --version={$version} --tag=v{$version} \\\n";
echo "    --package={$outZip} --private-key=_env/ks_update_priv.pem \\\n";
echo "    --changelog-file=PROJECT_CHANGELOG.md --from-version=<最低可升级版本> \\\n";
echo "    --out=release/manifest.json\n";
