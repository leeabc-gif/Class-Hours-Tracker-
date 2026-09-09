<?php
/**
 * manifest.json 签名工具（发布端运行）
 *
 * 用你的私钥对更新清单的“规范化 JSON”做 RSA-SHA256 签名，
 * 并把 base64 签名写回 manifest 的 signature 字段。
 * keshi 端用内置公钥（config/update_trust.php）验签后才会使用该清单。
 *
 * ===== 用法 =====
 *
 * 1) 现有用法：手动构建好 manifest.json，再用本工具签名
 *   php sign_manifest.php <manifest.json> [--out=other.json] [--check]
 *
 * 2) 新增（v1.0.2+）：CI 友好型，一行命令直接出 manifest.json
 *   php sign_manifest.php \
 *     --version=1.0.2 --tag=v1.0.2 \
 *     --package=release/keshi-1.0.2.zip \
 *     --private-key=/path/priv.pem \
 *     [--changelog-file=CHANGELOG.md] \
 *     [--min-php=7.4] \
 *     [--from-version=1.0.1] \
 *     --out=release/manifest.json
 *
 * 私钥来源（按优先级查找）：
 *   1) --private-key=/path/to/priv.pem
 *   2) env KS_UPDATE_KEY=/path/to/priv.pem
 *   3) env KS_UPDATE_KEY_B64=<base64>
 *   4) tools/../_update_priv.pem
 *   5) tools/../_env/ks_update_priv.pem
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require __DIR__ . '/../application/common/service/UpdateService.php';
}

use app\common\service\UpdateService;

// ---- 解析参数 ----
$args = array_slice($argv, 1);
$manifestFile = null;
$outFile      = null;
$checkOnly    = false;
$opts         = [
    'version'         => '',
    'tag'             => '',
    'package'         => '',
    'private_key'     => '',
    'changelog_file'  => '',
    'min_php'         => '',
    'from_version'    => '',
];
// 统一解析 --key=value（不再手写字符串偏移，避免 "吃掉首字符" 类偏移 bug）
foreach ($args as $a) {
    if ($a === '--check') { $checkOnly = true; continue; }
    if ($a === '-h' || $a === '--help') { printHelp(); exit(0); }
    if (preg_match('/^--([a-z][a-z-]*)=([\s\S]*)$/i', $a, $m)) {
        $key = str_replace('-', '_', strtolower($m[1]));
        if ($key === 'out') { $outFile = $m[2]; continue; }
        if (array_key_exists($key, $opts)) { $opts[$key] = $m[2]; continue; }
        fwrite(STDERR, "未知参数: --{$m[1]}\n");
        exit(2);
    }
    $manifestFile = $a;
}

// CI 模式：用 --version/--package 自动组装 manifest
$ciMode = $opts['version'] !== '' && $opts['package'] !== '';
if ($ciMode) {
    // 指定了 --out 时，中间文件直接落到 --out，避免污染 release/manifest.json
    $manifestFile = $manifestFile ?: ($outFile ?: 'release/manifest.json');
    $version = $opts['version'];
    $tag     = $opts['tag'] !== '' ? $opts['tag'] : ('v' . $version);
    $pkg     = $opts['package'];
    if (!is_file($pkg)) {
        fwrite(STDERR, "更新包不存在: $pkg\n");
        exit(2);
    }
    $size = filesize($pkg);
    $hash = hash_file('sha256', $pkg);
    $assetName = basename($pkg);

    $changelog = '';
    if ($opts['changelog_file'] !== '' && is_file($opts['changelog_file'])) {
        $changelog = extractChangelogSection((string) file_get_contents($opts['changelog_file']), $version);
    }

    $data = [
        'latest_version' => $version,
        'min_php'        => $opts['min_php'] !== '' ? $opts['min_php'] : '7.4',
        'published_at'   => gmdate('c'),
        'changelog'      => $changelog,
        'files'          => [
            [
                'name'    => $assetName,
                'url'     => 'https://github.com/leeabc-gif/Class-Hours-Tracker-/releases/download/' . $tag . '/' . $assetName,
                'sha256'  => $hash,
                'version' => $version,
                'size'    => $size,
            ],
        ],
    ];
    if ($opts['from_version'] !== '') {
        $data['requires'] = ['from_version' => $opts['from_version']];
    }
    @mkdir(dirname($manifestFile), 0755, true);
    file_put_contents($manifestFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "[CI] 已生成 manifest: $manifestFile\n";
    echo "  latest_version = $version\n";
    echo "  package        = $assetName ($size bytes)\n";
    echo "  sha256         = $hash\n";
} else {
    if (!$manifestFile) {
        fwrite(STDERR, "用法: php sign_manifest.php <manifest.json> [--out=other.json] [--check]\n");
        fwrite(STDERR, "或 CI 模式: php sign_manifest.php --version=1.0.2 --package=release/keshi-1.0.2.zip --private-key=... --out=release/manifest.json\n");
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
}

// ---- 定位私钥 ----
$privPem = '';
if ($opts['private_key'] !== '' && is_file($opts['private_key'])) {
    $privPem = (string) file_get_contents($opts['private_key']);
} else {
    $keyFromEnv = getenv('KS_UPDATE_KEY');
    if ($keyFromEnv && is_file($keyFromEnv)) { $privPem = (string) file_get_contents($keyFromEnv); }
    elseif (getenv('KS_UPDATE_KEY_B64'))    { $privPem = (string) base64_decode(getenv('KS_UPDATE_KEY_B64')); }
    elseif (is_file(__DIR__ . '/../_update_priv.pem'))         { $privPem = (string) file_get_contents(__DIR__ . '/../_update_priv.pem'); }
    elseif (is_file(__DIR__ . '/../_env/ks_update_priv.pem'))  { $privPem = (string) file_get_contents(__DIR__ . '/../_env/ks_update_priv.pem'); }
}

// ---- --check 模式：只验签 ----
if ($checkOnly) {
    $ok = UpdateService::verifyManifestSignature($data);
    echo $ok ? "签名有效 (RSA-SHA256 通过)\n" : "签名无效/缺失/公钥不符\n";
    exit($ok ? 0 : 1);
}

if (trim($privPem) === '') {
    fwrite(STDERR, "未找到私钥。请用 --private-key=path 或设置 KS_UPDATE_KEY / KS_UPDATE_KEY_B64，或将私钥放到 _update_priv.pem。\n");
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
// PHP 8+ 会自动释放 OpenSSLKey 对象，无需调用已弃用的 openssl_free_key()
unset($priv);
if ($signature === '') {
    fwrite(STDERR, "签名失败。\n");
    exit(2);
}
$data['signature'] = base64_encode($signature);

$pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$target = $outFile ?: $manifestFile;
if (file_put_contents($target, $pretty) === false) {
    fwrite(STDERR, "写入失败: $target\n");
    exit(2);
}

echo "已签名清单: $target\n";
echo "规范化JSON长度: " . strlen($canon) . "，签名长度(base64): " . strlen($data['signature']) . "\n";
echo "请同步把对应公钥放入 config/update_trust.php，并把清单部署到受控 HTTPS 地址。\n";

/**
 * 从 CHANGELOG.md 中提取指定版本的 changelog 段
 * 期望格式：## v1.0.2 / 2026-09-09
 *           - 新增 xxx
 */
function extractChangelogSection($content, $version)
{
    // 去掉 UTF-8 BOM：否则首行 "## vX.Y.Z" 因前面有 BOM 而匹配不到，
    // 导致「最新版本正好写在文件第一行」时 changelog 提取结果为空。
    $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content);
    $lines = preg_split("/\r?\n/", $content);
    $capture = false;
    $out = [];
    foreach ($lines as $line) {
        if (preg_match('/^#+\s*v?' . preg_quote($version, '/') . '(\s|\/|$)/', $line)) {
            $capture = true;
            continue;
        }
        if ($capture) {
            if (preg_match('/^#+\s*v?\d+(\.\d+)*/', $line)) {
                break;
            }
            $out[] = $line;
        }
    }
    return trim(implode("\n", $out));
}

function printHelp()
{
    echo "keshi manifest 签名工具\n";
    echo "  php sign_manifest.php <manifest.json> [--out=other.json] [--check]\n";
    echo "  php sign_manifest.php --version=<ver> --package=<zip> --private-key=<pem> --out=release/manifest.json\n";
}
