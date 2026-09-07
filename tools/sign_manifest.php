<?php
/**
 * manifest.json 签名工具（发布端运行）
 *
 * 用你的私钥对更新清单的“规范化 JSON”做 RSA-SHA256 签名，
 * 并把 base64 签名写回 manifest 的 signature 字段。
 * keshi 端用内置公钥（config/update_trust.php）验签后才会使用该清单。
 *
 * 用法（3 种写法等价）：
 *   1) KS_UPDATE_KEY=/path/priv.pem php sign_manifest.php /path/manifest.json
 *   2) KS_UPDATE_KEY_B64=<base64(priv.pem)> php sign_manifest.php /path/manifest.json
 *   3) 私钥放本项目根：php sign_manifest.php /path/manifest.json  （自动找 tools/../_update_priv.pem）
 *
 * 默认原地写回 manifest.json；加 --out=/other.json 可写到别处。
 * 加 --check 只验证现有 signature 是否有效，不写文件。
 *
 * 提示：私钥绝不要提交到仓库/上传到站点。公钥放入 config/update_trust.php。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "缺少 vendor/autoload.php，请先执行 composer install。\n");
    exit(2);
}
require $autoload;

use app\common\service\UpdateService;

// ---- 解析参数 ----
$args = array_slice($argv, 1);
$manifestFile = null;
$outFile = null;
$checkOnly = false;
foreach ($args as $a) {
    if ($a === '--check') { $checkOnly = true; continue; }
    if (strpos($a, '--out=') === 0) { $outFile = substr($a, 6); continue; }
    $manifestFile = $a;
}
if (!$manifestFile) {
    fwrite(STDERR, "用法: php sign_manifest.php <manifest.json> [--out=other.json] [--check]\n");
    exit(2);
}
if (!is_file($manifestFile)) {
    fwrite(STDERR, "清单文件不存在: $manifestFile\n");
    exit(2);
}

$raw = file_get_contents($manifestFile);
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "清单不是合法 JSON: $manifestFile\n");
    exit(2);
}

// ---- 定位私钥 ----
$privPem = '';
$keyFromEnv = getenv('KS_UPDATE_KEY');
if ($keyFromEnv && is_file($keyFromEnv)) { $privPem = file_get_contents($keyFromEnv); }
elseif (getenv('KS_UPDATE_KEY_B64')) { $privPem = base64_decode(getenv('KS_UPDATE_KEY_B64')); }
elseif (is_file(__DIR__ . '/../_update_priv.pem')) { $privPem = file_get_contents(__DIR__ . '/../_update_priv.pem'); }
elseif (is_file(__DIR__ . '/../_env/ks_update_priv.pem')) { $privPem = file_get_contents(__DIR__ . '/../_env/ks_update_priv.pem'); }

// ---- --check 模式：只验签 ----
if ($checkOnly) {
    $ok = UpdateService::verifyManifestSignature($data);
    echo $ok ? "签名有效 (RSA-SHA256 通过)\n" : "签名无效/缺失/公钥不符\n";
    exit($ok ? 0 : 1);
}

if (trim($privPem) === '') {
    fwrite(STDERR, "未找到私钥。请设置 KS_UPDATE_KEY / KS_UPDATE_KEY_B64，或将私钥放到 _update_priv.pem。\n");
    exit(2);
}

$priv = openssl_pkey_get_private($privPem);
if (!$priv) {
    fwrite(STDERR, "私钥解析失败（不是合法 PEM？）。\n");
    exit(2);
}

$canon = UpdateService::manifestCanonicalJson($data);
if ($canon === '') {
    fwrite(STDERR, "清单规范化失败。\n");
    exit(2);
}
openssl_sign($canon, $signature, $priv, OPENSSL_ALGO_SHA256);
openssl_free_key($priv);
if ($signature === '') {
    fwrite(STDERR, "签名失败。\n");
    exit(2);
}
$data['signature'] = base64_encode($signature);

// 写回（保留易读的缩进，供人工检查；验签基于规范化 JSON，与排版无关）
$pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$target = $outFile ?: $manifestFile;
if (file_put_contents($target, $pretty) === false) {
    fwrite(STDERR, "写入失败: $target\n");
    exit(2);
}

echo "已签名清单: $target\n";
echo "规范化JSON长度: " . strlen($canon) . "，签名长度(base64): " . strlen($data['signature']) . "\n";
echo "请同步把对应公钥放入 config/update_trust.php，并把清单部署到受控 HTTPS 地址。\n";
