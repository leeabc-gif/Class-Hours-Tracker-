<?php
namespace app\common\service;

use think\Db;
use think\facade\Log;
use app\common\model\Setting;

/**
 * 系统在线更新服务
 *
 * 流程：check(读取远程 manifest 比对版本) → download(下载 zip + sha256 校验)
 *       → backup(备份将被覆盖的文件 + 可选整库备份) → enterMaintenance
 *       → apply(白名单解压覆盖 + 执行 upgrade.sql) → exitMaintenance
 *       → rollback(任一步失败时按备份还原文件 + 执行 downgrade.sql)
 *
 * 安全约束：
 *  1. 仅允许覆盖白名单内的业务路径，绝不动 runtime / config/installed.php / 安装锁等。
 *  2. manifest 必须提供 sha256，下载后强校验，不符即删。
 *  3. 并发用 runtime/update.lock 的 flock 独占锁，避免多人同时点更新。
 *  4. 解压做路径穿越防护；跳过隐藏文件。
 *  5. 数据库 upgrade.sql 用事务包裹执行，失败回滚；并留 downgrade.sql 作回滚。
 */
class UpdateService
{
    /** 被覆盖文件的备份目录（相对项目根） */
    const BACKUP_DIR = 'runtime' . DIRECTORY_SEPARATOR . 'update_backups';

    /** 下载的更新包目录（相对项目根） */
    const PACKAGE_DIR = 'runtime' . DIRECTORY_SEPARATOR . 'updates';

    /** 任何情况下都禁止触碰的路径（相对项目根）：运行时/配置等机器生成文件 */
    const PROTECTED_PATHS = [
        'config/installed.php',   // 安装时生成的数据库配置，绝不能覆盖
        'config/database.php',    // ThinkPHP 数据库配置（读取 installed.php）
        'runtime',                // 日志/缓存/锁/备份，绝不进更新包
        'public/install',         // 安装向导，避免被更新包篡改绕过
    ];

    /** 允许被更新包覆盖的顶层目录（白名单）。index.html 属前端交付物，允许更新 */
    const OVERWRITE_ALLOWLIST = ['application', 'public', 'config', 'route', 'thinkphp'];

    const MANIFEST_MAX = 1048576;   // 1MB
    const PACKAGE_MAX  = 134217728; // 128MB
    const MAX_BACKUPS  = 5;          // 保留最近 N 份文件备份

    /**
     * 进程级 Bearer Token：管理员在「基础配置」填了 GitHub PAT 后，
     * 控制器会在调用 check/download 前 setBearerToken，httpGet 自动注入。
     * 私有仓库或提升限流时用，公开仓库可不填。
     */
    protected static $bearerToken = '';

    public static function setBearerToken($t)
    {
        self::$bearerToken = trim((string) $t);
    }

    public static function getBearerToken()
    {
        return self::$bearerToken;
    }

    /**
     * 当前运行版本：优先读 ks_setting.app_version，缺失回退到 config('app.version')
     */
    public static function currentVersion()
    {
        $v = Setting::get('app_version', '');
        return trim($v) !== '' ? trim($v) : (string) config('app.version', '1.0.0');
    }

    // ---------------------------------------------------------------
    // 第 1 步：检查更新
    // ---------------------------------------------------------------

    /**
     * 读取远程 manifest 并对比本地版本
     *
     * v1.0.2+：支持 GitHub Releases 模式
     *   manifest URL 形如 https://api.github.com/repos/<owner>/<repo>/releases/latest
     *   时，先调该 API 拿 tag_name，再去拉
     *     https://github.com/<owner>/<repo>/releases/download/<tag>/manifest.json
     *   公开仓库匿名即可访问（60/h/IP），配 Token 后到 5000/h。
     *
     * @return array
     */
    public static function check($manifestUrl)
    {
        $manifestUrl = self::validateRemoteUrl($manifestUrl, '更新清单地址');
        // 链式解析：先识别 GitHub /releases/latest，再识别 CNB /-/releases/latest，
        // 命中其一就把"latest"入口 URL 改写为带具体 tag 的 manifest 直链。
        list($manifestUrl, $resolvedTag, $sourceKind) = self::resolveManifestEntry($manifestUrl);
        try {
            $json = self::httpGet($manifestUrl, self::MANIFEST_MAX, '更新清单');
        } catch (\RuntimeException $e) {
            // 把"远程资源 404"翻译成可读提示，避免用户对着 HTTP 状态码猜原因
            $msg = $e->getMessage();
            if (stripos($msg, '404') !== false || stripos($msg, '资源不存在') !== false) {
                if ($sourceKind === 'cnb') {
                    throw new \RuntimeException(
                        'CNB Release 资源未找到：仓库可能还没在「Releases」页发布带 manifest.json 资源的新版本 tag。'
                        . '请到 https://cnb.cool/<owner>/<repo>/-/releases 手动上传 manifest.json 和 keshi-<ver>.zip，'
                        . '或在「基础配置」里把更新源改回 GitHub Releases（默认推荐）。',
                        0, $e
                    );
                }
                throw new \RuntimeException(
                    '远程更新清单 404：请检查仓库路径是否正确、tag 是否带 manifest.json 资源。'
                    . '常见原因：仓库尚未发布 Release 资源（只有 tag 没上传 manifest.json 和 zip）。',
                    0, $e
                );
            }
            throw $e;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['latest_version'])) {
            throw new \RuntimeException('更新清单格式不正确：缺少 latest_version。');
        }
        // 验签：清单若含 signature 则强校验；发布侧应在受控 HTTPS 源上签名
        if (!self::verifyManifestSignature($data)) {
            throw new \RuntimeException('更新清单签名无效或缺失：拒绝使用未经官方签名的清单。');
        }
        $files = isset($data['files']) && is_array($data['files']) ? $data['files'] : [];
        // 当前实现仅支持单包：多包场景（混合增量 + 全量等）一律拒绝，
        // 避免任意第二个文件绕过 sha256/size 白名单策略。
        if (count($files) > 1) {
            throw new \RuntimeException('当前版本仅支持单包更新，请发布方合并后再上传。');
        }
        $current = self::currentVersion();
        $latest  = (string) $data['latest_version'];

        return [
            'current_version'  => $current,
            'latest_version'   => $latest,
            'update_available' => version_compare($latest, $current, '>'),
            'min_php'          => isset($data['min_php']) ? (string) $data['min_php'] : '',
            'from_version'     => isset($data['requires']['from_version']) ? (string) $data['requires']['from_version'] : '',
            'changelog'        => isset($data['changelog']) ? (string) $data['changelog'] : '',
            'published_at'     => isset($data['published_at']) ? (string) $data['published_at'] : '',
            'files'            => $files,
            'checked_at'       => date('Y-m-d H:i:s'),
            'source'           => $sourceKind.':'.($resolvedTag !== '' ? $resolvedTag : 'custom'),
        ];
    }

    /**
     * 识别 "GitHub latest release" 入口 URL，自动解析出真正的 manifest URL。
     * - https://api.github.com/repos/<owner>/<repo>/releases/latest
     *   → 先 GET 该 API 拿 tag_name，再返回
     *     https://github.com/<owner>/<repo>/releases/download/<tag>/manifest.json
     * - 其他 URL 原样返回
     *
     * 返回 [最终 manifest URL, 解析出的 tag 或 '']
     */
    public static function resolveGithubLatest($manifestUrl)
    {
        $manifestUrl = trim((string) $manifestUrl);
        if (stripos($manifestUrl, 'https://api.github.com/repos/') !== 0) {
            return [$manifestUrl, ''];
        }
        // 形如 https://api.github.com/repos/<owner>/<repo>/releases/latest
        if (!preg_match('#^https://api\.github\.com/repos/([^/]+)/([^/]+)/releases/latest/?$#i', $manifestUrl, $m)) {
            return [$manifestUrl, ''];
        }
        $owner = $m[1];
        $repo  = $m[2];
        // 拉 GitHub API 拿 tag_name（公开仓库匿名 60/h，配 Token 后 5000/h）
        $apiJson = self::httpGet($manifestUrl, 65536, 'GitHub latest release API');
        $api = json_decode($apiJson, true);
        if (!is_array($api) || empty($api['tag_name'])) {
            throw new \RuntimeException('GitHub latest release API 返回格式异常：缺少 tag_name。');
        }
        $tag = (string) $api['tag_name'];
        if (!preg_match('#^[A-Za-z0-9._-]+$#', $tag)) {
            throw new \RuntimeException('GitHub 返回的 tag_name 含非法字符，拒绝继续。');
        }
        $realUrl = "https://github.com/{$owner}/{$repo}/releases/download/{$tag}/manifest.json";
        // 走严格 SSRF 校验
        $realUrl = self::validateRemoteUrl($realUrl, '更新清单地址');
        return [$realUrl, $tag];
    }

    /**
     * 识别 CNB latest 入口 URL，自动解析出真正的 manifest URL。
     * CNB 入口形如：
     *   https://cnb.cool/<owner>/<repo>/-/releases/latest/download/manifest.json
     *   https://cnb.cool/<owner>/<repo>/-/releases/latest
     * 思路：
     *   1) 抓取 <owner>/<repo>/-/releases 列表页（公开，无需鉴权）
     *   2) 在 HTML 里找 vX.Y.Z 形式的最新 tag（兼容 v 前缀和无前缀）
     *   3) 拼回 <owner>/<repo>/-/releases/download/<tag>/manifest.json
     * 若 <owner>/<repo> 抽取失败 / HTML 抓不到 / 找不到合法 tag，抛可读异常。
     *
     * 返回 [最终 manifest URL, tag, 'cnb']
     */
    public static function resolveCnbLatest($manifestUrl)
    {
        $manifestUrl = trim((string) $manifestUrl);
        // 只处理 latest 入口；带具体 tag 的 download URL 必须原样透传
        if (!preg_match('~^https://cnb\.cool/([^/?#]+)/([^/?#]+)/-/releases/latest(?:/download/manifest\.json)?/?$~i', $manifestUrl, $m)) {
            return [$manifestUrl, '', 'cnb'];
        }
        $owner = $m[1];
        $repo  = $m[2];
        $listUrl = "https://cnb.cool/{$owner}/{$repo}/-/releases";
        $listUrl = self::validateRemoteUrl($listUrl, 'CNB Release 列表');
        $html = self::httpGet($listUrl, 2 * 1024 * 1024, 'CNB Release 列表');
        $tag = self::pickLatestTagFromCnbHtml($html);
        if ($tag === '') {
            throw new \RuntimeException(
                'CNB Release 列表里未找到任何 tag。请先在 cnb.cool 仓库 Releases 页发布一个带 '
                . 'manifest.json 资源的版本。'
            );
        }
        $realUrl = "https://cnb.cool/{$owner}/{$repo}/-/releases/download/{$tag}/manifest.json";
        $realUrl = self::validateRemoteUrl($realUrl, '更新清单地址');
        return [$realUrl, $tag, 'cnb'];
    }

    /**
     * 从 CNB Release 列表页 HTML 中找出"最新"tag
     * 策略：抓所有 vX.Y.Z 或裸 X.Y.Z 形式（带 v 前缀优先），取按 version_compare 排序后的最大值。
     * 接受带预发布后缀（-beta1, -rc2 等）。
     */
    public static function pickLatestTagFromCnbHtml($html)
    {
        if (!is_string($html) || $html === '') return '';
        // value 保留 Release 的真实 tag；排序时单独使用去掉 v 前缀后的版本号。
        $candidates = [];
        if (preg_match_all('#\bv(\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?)\b#', $html, $m1)) {
            foreach ($m1[1] as $v) $candidates['v' . $v] = $v;
        }
        // 退路：抓裸 X.Y.Z（且排除已经带 v 前缀的匹配）
        if (preg_match_all('#(?<![A-Za-z0-9.vV])(\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?)\b#', $html, $m2)) {
            foreach ($m2[1] as $v) $candidates[$v] = $v;
        }
        if (empty($candidates)) return '';
        uksort($candidates, function ($tagA, $tagB) use ($candidates) {
            return version_compare($candidates[$tagA], $candidates[$tagB]);
        });
        end($candidates);
        return (string) key($candidates);
    }


    /**
     * 链式解析：识别 GitHub / CNB 两种 latest 入口 URL。
     * 命中 GitHub → 改写为 github.com/.../releases/download/<tag>/manifest.json
     * 命中 CNB   → 改写为 cnb.cool/.../-/releases/download/<tag>/manifest.json
     * 其他       → 原样返回
     *
     * 返回 [最终 manifest URL, 解析出的 tag 或 '', 'github'|'cnb'|'custom']
     */
    public static function resolveManifestEntry($manifestUrl)
    {
        $manifestUrl = trim((string) $manifestUrl);
        if ($manifestUrl === '') {
            return [$manifestUrl, '', 'custom'];
        }
        $lower = strtolower($manifestUrl);
        if (strpos($lower, 'https://api.github.com/repos/') === 0
            || strpos($lower, 'https://github.com/') === 0) {
            list($u, $tag) = self::resolveGithubLatest($manifestUrl);
            return [$u, $tag, 'github'];
        }
        if (strpos($lower, 'https://cnb.cool/') === 0) {
            $ret = self::resolveCnbLatest($manifestUrl);
            return [$ret[0], $ret[1], 'cnb'];
        }
        return [$manifestUrl, '', 'custom'];
    }

    /**
     * 仅做 manifest URL 的安全校验（不下载）。供 settingsSave 写入前调用，
     * 防止脏数据（如 javascript: / 私网 / 长 URL）落库。
     * 规则：必须 https 开头，host/IP 不可为内网/保留段，长度 ≤ 1000。
     */
    public static function validateManifestUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            throw new \RuntimeException('更新清单地址不能为空。');
        }
        if (!preg_match('#^https://#i', $url)) {
            throw new \RuntimeException('更新清单地址必须以 https:// 开头。');
        }
        if (strlen($url) > 1000) {
            throw new \RuntimeException('更新清单地址不能超过 1000 个字符。');
        }
        // 复用严格 SSRF 校验（IP/host 名/私网/保留段）
        self::validateRemoteUrl($url, '更新清单地址');
        return $url;
    }

    // ---------------------------------------------------------------
    // 第 2 步：下载 + 校验
    // ---------------------------------------------------------------

    /**
     * 下载更新包并做 sha256 强校验
     * @return array 包信息
     */
    public static function download($fileInfo, $manifestUrl)
    {
        $url = self::resolveFileUrl($fileInfo, $manifestUrl);
        self::ensureDir(self::packageDir());

        $slug = preg_replace('/[^A-Za-z0-9._-]/', '-', isset($fileInfo['name']) ? $fileInfo['name'] : 'package');
        $target = self::packageDir() . DIRECTORY_SEPARATOR . 'keshi-' . $slug;
        $body = self::httpGet($url, self::PACKAGE_MAX, '更新包');
        if (file_put_contents($target, $body) === false) {
            throw new \RuntimeException('无法写入更新包到本地：' . basename($target));
        }

        $hash = hash_file('sha256', $target);
        $expect = strtolower(trim(isset($fileInfo['sha256']) ? (string) $fileInfo['sha256'] : ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $expect)) {
            @unlink($target);
            throw new \RuntimeException('更新清单缺少合法的 64 位 SHA-256，已拒绝安装。');
        }
        if (!hash_equals($expect, strtolower($hash))) {
            @unlink($target);
            throw new \RuntimeException('更新包 SHA-256 校验失败，已删除可疑文件。');
        }
        $packageVersion = trim(isset($fileInfo['version']) ? (string) $fileInfo['version'] : '');
        if ($packageVersion === '') {
            @unlink($target);
            throw new \RuntimeException('更新清单缺少更新包版本号，已拒绝安装。');
        }
        return [
            'path'    => $target,
            'size'    => filesize($target),
            'sha256'  => $hash,
            'version' => $packageVersion,
        ];
    }

    // ---------------------------------------------------------------
    // 第 3 步：备份
    // ---------------------------------------------------------------

    /**
     * 备份将被 zip 覆盖的文件，返回备份路径。
     * 备份集与 extractWhitelisted 的覆盖集口径一致，并记录新增文件与更新包哈希，供可靠回滚。
     */
    public static function backup($zipPath)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('更新包无法打开，请确认是有效 ZIP。');
        }
        $root = self::rootPath();
        $stamp = date('Ymd_His');
        $backDir = $root . self::BACKUP_DIR . DIRECTORY_SEPARATOR . 'files_' . $stamp;
        self::ensureDir($backDir);

        $files = self::zipEntryNames($zip);
        $backed = 0;
        $newFiles = [];
        $coveredFiles = [];
        foreach ($files as $entry) {
            $rel = self::normalizeEntry($entry);
            if ($rel === '' || self::isProtected($rel) || !self::inAllowlist($rel)) {
                continue;
            }
            if (substr($entry, -1) === '/' || strpos(basename($rel), '.') === 0) {
                continue;
            }
            $coveredFiles[] = $rel;
            $targetAbs = self::safeTargetAbs($rel);
            if ($targetAbs === '' || !is_file($targetAbs)) {
                $newFiles[] = $rel;
                continue;
            }
            $dest = $backDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            $dir  = dirname($dest);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (@copy($targetAbs, $dest)) {
                $backed++;
            }
        }
        $zip->close();
        $newFiles = array_values(array_unique($newFiles));
        $coveredFiles = array_values(array_unique($coveredFiles));
        // 元信息：回滚时恢复版本号、删除新增文件，并按包哈希匹配 downgrade.sql
        @file_put_contents($backDir . DIRECTORY_SEPARATOR . '_meta.json', json_encode([
            'version'       => self::currentVersion(),
            'time'          => date('c'),
            'files'         => $backed,
            'covered_files' => $coveredFiles,
            'new_files'     => $newFiles,
            'package_sha256'=> is_file($zipPath) ? hash_file('sha256', $zipPath) : '',
            'package_version' => '',
        ], JSON_UNESCAPED_UNICODE));
        return ['dir' => $backDir, 'files' => $backed, 'new_files' => $newFiles];
    }

    /**
     * 整库 SQL 备份（可选，强烈建议升级含 SQL 时调用）
     */
    public static function backupDatabase()
    {
        $root = self::rootPath();
        $backDir = $root . self::BACKUP_DIR . DIRECTORY_SEPARATOR . 'db_' . date('Ymd_His');
        self::ensureDir($backDir);
        $path = $backDir . DIRECTORY_SEPARATOR . 'backup.sql';
        $dump = Backup::dump();
        if (file_put_contents($path, $dump['sql']) === false) {
            throw new \RuntimeException('数据库备份文件写入失败。');
        }
        // M-6：单次备份超过 256MB 时强制告警（提醒运维扩容 / 清理）；
        // 不阻断升级，但写入 Log + 备份目录 manifest 提示。
        $warnThreshold = 256 * 1024 * 1024;
        if ($dump['size'] > $warnThreshold) {
            Log::warning('[update] db backup larger than 256MB: ' . $dump['size'] . ' bytes', [
                'tables' => $dump['tables'],
                'rows'   => $dump['rows'],
            ]);
            @file_put_contents(
                $backDir . DIRECTORY_SEPARATOR . 'BACKUP_LARGE.txt',
                "本备份超过 256MB（{$dump['size']} bytes），请关注磁盘容量。\n"
            );
        }
        return ['path' => $path, 'tables' => $dump['tables'], 'rows' => $dump['rows'], 'size' => $dump['size']];
    }

    // ---------------------------------------------------------------
    // 第 4 步：应用更新
    // ---------------------------------------------------------------

    /**
     * 解压覆盖（白名单）+ 执行 upgrade.sql（事务）
     * 注意：并发安全由调用方通过 acquireLock 兜底，本方法不再校验
     */
    public static function apply($zipPath)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('更新包无法打开。');
        }
        $entries = self::zipEntryNames($zip);
        $zip->close();

        // 找到 upgrade.sql（包根或 code 目录下）
        $upgradeSql = null;
        foreach ($entries as $entry) {
            $base = strtolower(basename($entry));
            if ($base === 'upgrade.sql') {
                $upgradeSql = $entry;
                break;
            }
        }

        // 先做文件覆盖（此步不含 SQL，即便失败也只是文件层，可回滚）
        self::extractWhitelisted($zipPath, $entries);
        // 再执行 SQL（事务，失败抛异常交上层回滚文件）
        if ($upgradeSql !== null) {
            self::runSqlFromZip($zipPath, $upgradeSql);
        }
        return true;
    }

    /**
     * 仅按白名单解压覆盖文件（跳过 protected/隐藏/目录）
     */
    protected static function extractWhitelisted($zipPath, $entries)
    {
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $root = self::rootPath();
        $applied = 0;
        try {
            foreach ($entries as $entry) {
                $rel = self::normalizeEntry($entry);
                if ($rel === '') {
                    continue;
                }
                // 只覆盖白名单目录内的文件
                if (!self::inAllowlist($rel)) {
                    continue;
                }
                if (self::isProtected($rel)) {
                    continue;
                }
                // 跳过目录项与隐藏文件
                if (substr($entry, -1) === '/') {
                    continue;
                }
                if (strpos(basename($rel), '.') === 0) {
                    continue;
                }
                $targetAbs = self::safeTargetAbs($rel);
                if ($targetAbs === '') {
                    continue;
                }
                $dir = dirname($targetAbs);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                // 解压单文件到目标（ZipArchive::extractTo 不安全，改为逐条读出写入）
                $fp = $zip->getStream($entry);
                if ($fp === false) {
                    continue;
                }
                $tmp = $targetAbs . '.new';
                $out = fopen($tmp, 'wb');
                if ($out === false) {
                    fclose($fp);
                    continue;
                }
                stream_copy_to_stream($fp, $out);
                fclose($fp);
                fclose($out);
                // M-1：尽量保留原文件权限（0755/0644），覆盖后不被强制改为 0644
                // 老文件不存在时退回 0644 兜底
                $permMode = 0644;
                if (is_file($targetAbs)) {
                    $oldPerm = @fileperms($targetAbs);
                    if (is_int($oldPerm)) {
                        $permMode = ($oldPerm & 0777);
                    }
                }
                @chmod($tmp, $permMode);
                // rename 同目录内原子覆盖
                if (!@rename($tmp, $targetAbs)) {
                    @unlink($tmp);
                } else {
                    $applied++;
                }
            }
        } finally {
            $zip->close();
        }
        return $applied;
    }

    /**
     * 从 zip 里读出 upgrade.sql 并事务执行
     */
    protected static function runSqlFromZip($zipPath, $entry)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('无法读取更新包内的 SQL。');
        }
        $sql = $zip->getFromName($entry);
        $zip->close();
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('更新包内 upgrade.sql 为空。');
        }
        $statements = self::splitSqlStatements(self::stripSqlLineComments($sql));
        $pdo = \app\common\service\Backup::rawPdo();
        $pdo->beginTransaction();
        try {
            $idx = 0;
            foreach ($statements as $stmt) {
                $idx++;
                $stmt = trim($stmt);
                if ($stmt === '' || stripos($stmt, '--') === 0) {
                    continue;
                }
                try {
                    $pdo->exec($stmt);
                } catch (\Throwable $e) {
                    // M-4：仅返回错误码 + 行号，SQL 内容（含 DDL/表结构）走 Log::error，
                    // 不直接回显到前端，避免表结构探针。
                    Log::error('[update] upgrade.sql exec failed at #' . $idx . ': ' . $e->getMessage(), [
                        'stmt_index' => $idx,
                        'stmt_preview' => mb_substr($stmt, 0, 200, 'UTF-8'),
                        'error' => $e->getMessage(),
                    ]);
                    throw new \RuntimeException('执行 upgrade.sql 失败：第 ' . $idx . ' 条语句（' . $e->getMessage() . '）');
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException('执行 upgrade.sql 失败，已回滚数据库：' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // 回滚
    // ---------------------------------------------------------------

    /**
     * 用最近一次文件备份还原；若有 downgrade.sql 也一并执行（可选传 zip）
     * @return array
     */
    public static function rollback($downgradeZipPath = '')
    {
        $root = self::rootPath();
        $base = $root . self::BACKUP_DIR;
        $fileBackups = glob($base . DIRECTORY_SEPARATOR . 'files_*');
        sort($fileBackups);
        $restored = 0;
        $removedNew = 0;
        $restoredVersion = '';
        $meta = [];
        // 取最近一份文件备份还原
        if ($fileBackups) {
            $dir = array_pop($fileBackups);
            // 读取备份时记录的版本号，还原完成后回写，避免"文件回了版本号没回"
            $metaFile = $dir . DIRECTORY_SEPARATOR . '_meta.json';
            if (is_file($metaFile)) {
                $meta = json_decode((string) file_get_contents($metaFile), true);
                if (is_array($meta) && !empty($meta['version'])) {
                    $restoredVersion = (string) $meta['version'];
                }
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($dir) + 1);
                $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
                if ($rel === '_meta.json' || !self::inAllowlist($rel) || self::isProtected($rel)) {
                    continue;
                }
                $targetAbs = self::safeTargetAbs($rel);
                if ($targetAbs === '') {
                    continue;
                }
                $d = dirname($targetAbs);
                if (!is_dir($d)) {
                    @mkdir($d, 0755, true);
                }
                if (@copy($file->getPathname(), $targetAbs)) {
                    $restored++;
                }
            }
            $newFiles = is_array($meta) && isset($meta['new_files']) && is_array($meta['new_files'])
                ? array_values(array_unique($meta['new_files'])) : [];
            if ($downgradeZipPath !== '' && is_file($downgradeZipPath)
                && is_array($meta) && !empty($meta['package_sha256'])) {
                $actualPackageHash = hash_file('sha256', $downgradeZipPath);
                if (!hash_equals(strtolower((string) $meta['package_sha256']), strtolower($actualPackageHash))) {
                    throw new \RuntimeException('回滚包与文件备份不匹配，已拒绝执行 downgrade.sql。');
                }
            }
            // M-10：拆开"还原文件数"和"删除新文件数"，避免 UI 把两类混在一起显示。
            foreach ($newFiles as $rel) {
                $rel = self::normalizeEntry($rel);
                if ($rel === '' || !self::inAllowlist($rel) || self::isProtected($rel)) {
                    continue;
                }
                $targetAbs = self::safeTargetAbs($rel);
                if ($targetAbs !== '' && is_file($targetAbs) && @unlink($targetAbs)) {
                    $removedNew++;
                }
            }
            if ($restoredVersion !== '') {
                Setting::set('app_version', $restoredVersion);
            }
        }
        // 执行 downgrade.sql（若提供 zip 且内含）
        if ($downgradeZipPath !== '' && is_file($downgradeZipPath)) {
            $zip = new \ZipArchive();
            if ($zip->open($downgradeZipPath) === true) {
                $names = self::zipEntryNames($zip);
                $zip->close();
                foreach ($names as $entry) {
                    if (strtolower(basename($entry)) === 'downgrade.sql') {
                        self::runSqlFromZip($downgradeZipPath, $entry);
                        break;
                    }
                }
            }
        }
        return [
            'restored_files'  => $restored,
            'removed_new_files' => $removedNew,
            'restored_version' => $restoredVersion,
        ];
    }

    // ---------------------------------------------------------------
    // 备份清单 / 裁剪 / 包清理 / 环境预检
    // ---------------------------------------------------------------

    /**
     * 是否存在可用的文件备份（决定"回滚"按钮是否可用）
     */
    public static function hasFileBackup()
    {
        $base = self::rootPath() . self::BACKUP_DIR;
        return (bool) glob($base . DIRECTORY_SEPARATOR . 'files_*');
    }

    /**
     * 列出全部备份（文件备份 + 数据库备份），新的在前
     * @return array
     */
    public static function listBackups()
    {
        $base = self::rootPath() . self::BACKUP_DIR;
        $out  = [];
        foreach (['files', 'db'] as $type) {
            $dirs = glob($base . DIRECTORY_SEPARATOR . $type . '_*') ?: [];
            rsort($dirs); // 目录名含时间戳，字典序即时间序
            foreach ($dirs as $dir) {
                $stat = self::dirStat($dir);
                $item = [
                    'type'  => $type,
                    'dir'   => basename($dir),
                    'time'  => self::backupDirTime(basename($dir)),
                    'files' => $stat['files'],
                    'size'  => $stat['size'],
                ];
                if ($type === 'files') {
                    $metaFile = $dir . DIRECTORY_SEPARATOR . '_meta.json';
                    $meta = is_file($metaFile) ? json_decode((string) file_get_contents($metaFile), true) : null;
                    $item['version'] = is_array($meta) && !empty($meta['version']) ? (string) $meta['version'] : '';
                }
                $out[] = $item;
            }
        }
        // 混合后按时间倒序
        usort($out, function ($a, $b) {
            return strcmp($b['dir'], $a['dir']);
        });
        return $out;
    }

    /**
     * 裁剪旧备份：files_* 与 db_* 各保留最近 MAX_BACKUPS 份
     * @return int 删除的备份目录数
     */
    public static function pruneBackups()
    {
        $base = self::rootPath() . self::BACKUP_DIR;
        $removed = 0;
        foreach (['files', 'db'] as $type) {
            $dirs = glob($base . DIRECTORY_SEPARATOR . $type . '_*') ?: [];
            sort($dirs); // 旧 → 新
            $excess = count($dirs) - self::MAX_BACKUPS;
            for ($i = 0; $i < $excess; $i++) {
                self::removeDir($dirs[$i]);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * 清理 runtime/updates 下下载过的更新包，保留最新 N 个
     * （最近一个可能在回滚时用于执行 downgrade.sql，别全删）
     */
    public static function cleanupPackages($keep = 3)
    {
        $files = glob(self::packageDir() . DIRECTORY_SEPARATOR . 'keshi-*') ?: [];
        usort($files, function ($a, $b) {
            return filemtime($a) - filemtime($b); // 旧 → 新
        });
        $removed = 0;
        $excess = count($files) - max(1, (int) $keep);
        for ($i = 0; $i < $excess; $i++) {
            if (@unlink($files[$i])) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * 最近一次下载的更新包路径（可能内含 downgrade.sql）；无则返回空串
     */
    public static function latestPackage()
    {
        $files = glob(self::packageDir() . DIRECTORY_SEPARATOR . 'keshi-*') ?: [];
        if (!$files) {
            return '';
        }
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        return $files[0];
    }

    /**
     * 升级前环境预检：PHP 版本下限 / 起始版本要求，不满足直接拒绝
     * @param array $info UpdateService::check() 的返回
     */
    public static function assertEnvCompatible(array $info)
    {
        if (!empty($info['min_php']) && version_compare(PHP_VERSION, (string) $info['min_php'], '<')) {
            throw new \RuntimeException(
                '新版要求 PHP ≥ ' . $info['min_php'] . '，当前为 ' . PHP_VERSION . '，请先升级 PHP。');
        }
        if (!empty($info['from_version'])) {
            $current = self::currentVersion();
            if (version_compare($current, (string) $info['from_version'], '<')) {
                throw new \RuntimeException(
                    '该更新包要求从 v' . $info['from_version'] . ' 起升，当前 v' . $current . ' 跨度过大，请先升到中间版本。');
            }
        }
    }

    // ---------------------------------------------------------------
    // 并发锁
    // ---------------------------------------------------------------

    /**
     * 获取更新独占锁；已占用返回 null
     */
    public static function acquireLock()
    {
        $lockFile = self::rootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'update.lock';
        $fp = @fopen($lockFile, 'c');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        ftruncate($fp, 0);
        fwrite($fp, date('c') . "\n");
        fflush($fp);
        return $fp;
    }

    public static function releaseLock($fp)
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ---------------------------------------------------------------
    // 工具函数
    // ---------------------------------------------------------------

    protected static function rootPath()
    {
        return app()->getRootPath();
    }

    protected static function packageDir()
    {
        return self::rootPath() . self::PACKAGE_DIR;
    }

    protected static function ensureDir($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException('无法创建目录：' . basename($dir));
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('目录不可写：' . $dir);
        }
    }

    /**
     * 统计目录下的文件数与总字节
     */
    protected static function dirStat($dir)
    {
        $files = 0;
        $size  = 0;
        if (!is_dir($dir)) {
            return ['files' => 0, 'size' => 0];
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files++;
                $size += $file->getSize();
            }
        }
        return ['files' => $files, 'size' => $size];
    }

    /**
     * 从备份目录名（files_20260907_190000）解析出可读时间
     */
    protected static function backupDirTime($name)
    {
        if (preg_match('/_(\d{8})_(\d{6})$/', $name, $m)) {
            $t = strtotime($m[1] . ' ' . $m[2]);
            if ($t !== false) {
                return date('Y-m-d H:i:s', $t);
            }
        }
        return $name;
    }

    /**
     * 递归删除目录（仅限备份目录内部使用，调用方保证路径来源可信）
     */
    protected static function removeDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    protected static function validateRemoteUrl($url, $label)
    {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException($label . '必须以 http:// 或 https:// 开头。');
        }
        if (strlen($url) > 1000) {
            throw new \RuntimeException($label . '过长。');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException($label . '格式不正确，且不得包含用户名或密码。');
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || $host === 'metadata.google.internal' || $host === '169.254.169.254') {
            throw new \RuntimeException($label . '不能指向本机或云元数据地址。');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException($label . '不能指向内网或保留 IP 地址。');
            }
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ip = isset($record['ip']) ? $record['ip'] : (isset($record['ipv6']) ? $record['ipv6'] : '');
                    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        throw new \RuntimeException($label . '解析到了内网或保留 IP 地址。');
                    }
                }
            }
        }
        return $url;
    }

    protected static function resolveFileUrl($fileInfo, $manifestUrl)
    {
        if (isset($fileInfo['url']) && trim($fileInfo['url']) !== '') {
            return self::validateRemoteUrl($fileInfo['url'], '更新包地址');
        }
        // 相对路径：相对 manifest 所在目录解析
        $base = preg_replace('#/[^/]*$#', '/', (string) $manifestUrl);
        return self::validateRemoteUrl($base . (isset($fileInfo['name']) ? $fileInfo['name'] : ''), '更新包地址');
    }

    protected static function httpGet($url, $maxBytes, $label, $redirects = 0)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前 PHP 未启用 curl 扩展。');
        }
        if ($redirects > 3) {
            throw new \RuntimeException('下载' . $label . '失败：重定向次数过多。');
        }
        // S-1：先用严格 SSRF 规则校验（已含 DNS A/AAAA 解析），同时返回所有合法 IP。
        $ips = self::resolveSafeIps($url);
        if (empty($ips)) {
            throw new \RuntimeException($label . '无法解析或解析到不可信 IP。');
        }
        $parts = parse_url($url);
        $host  = isset($parts['host']) ? trim((string) $parts['host'], '[]') : '';
        $port  = isset($parts['port']) ? (int) $parts['port'] : 0;
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';

        $ch = curl_init();
        // 锁定解析结果到首个合法 IP（防 DNS rebinding / TOCTOU）
        $primaryIp = $ips[0];
        // 缺省端口：443/80
        if ($port <= 0) {
            $port = ($scheme === 'https') ? 443 : 80;
        }
        $headers = [
            'Accept: application/json, application/octet-stream, */*',
            'X-Keshi-Updater: 1',
        ];
        // GitHub REST API 必须带 vnd.github+json Accept，否则会返回 HTML 渲染页（404 时也返 HTML）
        if (stripos($url, 'api.github.com/') !== false) {
            $headers[] = 'Accept: application/vnd.github+json';
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
            $headers[] = 'User-Agent: keshi-updater/1.0.2';
        }
        // v1.0.2+：管理员在「基础配置」填的 GitHub PAT 会通过 setBearerToken 注入
        if (self::$bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . self::$bearerToken;
        }
        $redirectUrl = '';
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . $primaryIp],
            CURLOPT_RETURNTRANSFER => true,
            // 不直接交给 cURL 跟随，避免跳转绕过 URL 和 DNS 安全校验；收到 3xx 后逐跳校验。
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Keshi-Updater/1.0',
            CURLOPT_HTTPHEADER     => $headers,
            // 限制协议：仅 HTTP/HTTPS，绝不允许 file:// / gopher:// / ftp://
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$redirectUrl) {
                if (preg_match('/^Location:\s*(.+?)\s*$/i', $header, $m)) {
                    $redirectUrl = trim($m[1]);
                }
                return strlen($header);
            },
        ]);
        // 通过写临时文件限制体积
        $tmp = tempnam(sys_get_temp_dir(), 'ksdl');
        $out = fopen($tmp, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $out);
        curl_exec($ch);
        fclose($out);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err !== '') {
            @unlink($tmp);
            throw new \RuntimeException('下载' . $label . '失败：' . $err);
        }
        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            @unlink($tmp);
            if ($redirectUrl === '') {
                throw new \RuntimeException('下载' . $label . '失败：HTTP ' . $code . ' 且未提供跳转地址。');
            }
            // Location 允许相对地址；以当前 URL 为基准拼接后，再执行完整 URL/域名/IP 校验。
            $redirectUrl = self::resolveRedirectUrl($url, $redirectUrl);
            $redirectUrl = self::validateRemoteUrl($redirectUrl, $label . '跳转地址');
            return self::httpGet($redirectUrl, $maxBytes, $label, $redirects + 1);
        }
        if ($code === 401) {
            @unlink($tmp);
            throw new \RuntimeException('下载' . $label . '失败：HTTP 401 未授权。若为 GitHub 私有仓库，请在「基础配置 → 更新源 → GitHub Token」填写有 repo 权限的 PAT；公开仓库请清空 Token。');
        }
        if ($code === 403) {
            @unlink($tmp);
            throw new \RuntimeException('下载' . $label . '失败：HTTP 403 拒绝访问。常见原因：GitHub 限流（请配置 PAT）/ IP 被封禁 / 仓库不存在或资产名错误。');
        }
        if ($code === 404) {
            @unlink($tmp);
            throw new \RuntimeException('下载' . $label . '失败：HTTP 404 资源不存在。请检查 manifest URL 与资产名是否匹配 GitHub Release 中的实际文件。');
        }
        if ($code === 429) {
            @unlink($tmp);
            throw new \RuntimeException('下载' . $label . '失败：HTTP 429 触发限流。请在「基础配置 → 更新源」配置 GitHub Token 后再试。');
        }
        if ($code < 200 || $code >= 300) {
            @unlink($tmp);
            throw new \RuntimeException('下载' . $label . '失败：HTTP ' . $code);
        }
        $size = filesize($tmp);
        if ($size > $maxBytes) {
            @unlink($tmp);
            throw new \RuntimeException($label . '超出允许大小。');
        }
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    protected static function resolveRedirectUrl($baseUrl, $location)
    {
        $location = trim((string) $location);
        if ($location === '') return '';
        if (preg_match('#^https?://#i', $location)) return $location;
        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) return $location;
        if (strpos($location, '//') === 0) return $base['scheme'] . ':' . $location;
        $origin = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) $origin .= ':' . (int) $base['port'];
        if (strpos($location, '/') === 0) return $origin . $location;
        $path = isset($base['path']) ? $base['path'] : '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path);
        return $origin . ($dir ?: '/') . $location;
    }

    /**
     * 把域名解析为 IP 列表，只保留通过严格 SSRF 校验的结果。
     * @return string[] IP 列表（可空）
     */
    protected static function resolveSafeIps($url)
    {
        $url = trim((string) $url);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return [];
        }
        $host = trim((string) $parts['host'], '[]');
        if ($host === 'localhost' || $host === 'metadata.google.internal' || $host === '169.254.169.254') {
            return [];
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ips[] = $host;
            }
            return $ips;
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = isset($record['ip']) ? $record['ip'] : (isset($record['ipv6']) ? $record['ipv6'] : '');
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ips[] = $ip;
                }
            }
        }
        return $ips;
    }

    protected static function zipEntryNames($zip)
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        return $names;
    }

    /**
     * 归一化 zip 内条目路径，返回相对站点根的路径；含穿越则返回空。
     * 支持三种包内布局：
     *   A) code/application/...      （仅 code 前缀）
     *   B) keshi-1.1.0/code/application/...
     *   C) keshi-1.1.0/application/...（顶层版本外壳目录）
     */
    protected static function normalizeEntry($entry)
    {
        $entry = str_replace('\\', '/', (string) $entry);
        $entry = preg_replace('#^\./+|^/+#', '', $entry);
        $parts = explode('/', $entry);
        // 去掉顶层版本外壳目录（直到首段进入白名单或为 code）
        while ($parts) {
            $head = $parts[0];
            if (in_array($head, self::OVERWRITE_ALLOWLIST, true)) {
                break; // 已到 application/config/... 层
            }
            if (strtolower($head) === 'code') {
                array_shift($parts);
                continue;
            }
            array_shift($parts); // 剥版本外壳目录
            if (!$parts) {
                break;
            }
        }
        $rel = implode('/', $parts);
        if (preg_match('#(^|/)\.\.(/|$)#', $rel) || strpos($rel, ':') !== false || strpos($rel, "\0") !== false) {
            return '';
        }
        return trim($rel, '/');
    }

    protected static function inAllowlist($rel)
    {
        foreach (self::OVERWRITE_ALLOWLIST as $prefix) {
            if ($rel === $prefix || strpos($rel, $prefix . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    protected static function isProtected($rel)
    {
        foreach (self::PROTECTED_PATHS as $p) {
            $p = rtrim($p, '/');
            if ($rel === $p || strpos($rel, $p . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 由相对路径得到绝对目标路径；不在项目根内则返回空
     */
    protected static function safeTargetAbs($rel)
    {
        $root = realpath(self::rootPath());
        if ($root === false) {
            return '';
        }
        $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $realRoot = realpath($abs) !== false ? realpath($abs) : $abs;
        // 确保目标落在项目根内
        if (strpos($realRoot, $root . DIRECTORY_SEPARATOR) !== 0 && $realRoot !== $root) {
            return '';
        }
        return $realRoot;
    }

    /** 引号感知剥离行注释（-- 至行尾），字符串内 -- 不误删 */
    protected static function stripSqlLineComments($sql)
    {
        $len = strlen($sql);
        $out = '';
        $quote = '';
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';
            if ($quote !== '') {
                $out .= $char;
                if ($char === '\\') {
                    if ($i + 1 < $len) {
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
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                if ($i < $len) {
                    $out .= "\n";
                }
                continue;
            }
            $out .= $char;
        }
        return $out;
    }

    /** 引号感知按分号拆分 SQL */
    protected static function splitSqlStatements($sql)
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $quote = '';
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $buffer .= $char;
            if ($quote !== '') {
                if ($char === '\\' && $i + 1 < $len) {
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

    // ---------------------------------------------------------------
    // Manifest 数字签名（RSA-SHA256）
    //
    // 信任模型：keshi 出厂内置官方公钥（config/update_trust.php）。
    // 发布端用对应私钥对清单的“规范化 JSON”签名，把 base64 签名放进
    // manifest 的 signature 字段。本机验签通过才允许使用该清单，
    // 防止清单被篡改指向恶意更新包。
    //
    // 规范化规则（签名/验签两端必须一致）：
    //   取清单数组，剔除 signature 字段，按键名递归排序，再以
    //   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE 紧凑编码。
    // ---------------------------------------------------------------

    /**
     * 校验 manifest 的数字签名
     * @param array $manifest 已解码的清单数组
     * @return bool true=通过；false=未签/签名无效/公钥不可用
     */
    public static function verifyManifestSignature(array $manifest)
    {
        if (!isset($manifest['signature']) || !is_string($manifest['signature']) || trim($manifest['signature']) === '') {
            return false; // 未携带签名
        }
        $pubPem = self::manifestPublicKey();
        if ($pubPem === '') {
            return false; // 未内置公钥则无法验签，拒绝
        }
        $canon = self::manifestCanonicalJson($manifest);
        $sig   = base64_decode(trim($manifest['signature']), true);
        if ($canon === '' || $sig === false || $sig === '') {
            return false;
        }
        $key = openssl_pkey_get_public($pubPem);
        if (!$key) {
            return false;
        }
        $ok = openssl_verify($canon, $sig, $key, OPENSSL_ALGO_SHA256);
        // PHP 8+ 会自动释放 OpenSSLKey 对象，无需调用已弃用的 openssl_free_key()
        unset($key);
        return $ok === 1;
    }

    /**
     * 读取出厂内置公钥 PEM（config/update_trust.php 的 public_key）
     */
    protected static function manifestPublicKey()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        // 用 __DIR__ 相对定位，避免依赖 app() 容器（签名工具/单元测试可在不引导框架时直接调用）
        $confPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'update_trust.php';
        $conf = is_file($confPath) ? (require $confPath) : [];
        $pem  = is_array($conf) && !empty($conf['public_key']) ? (string) $conf['public_key'] : '';
        $cached = trim($pem);
        return $cached;
    }

    /**
     * 计算清单的规范化 JSON（剔除 signature + 递归按键排序 + 紧凑编码）
     * 供签名工具与验签复用，保证两端字节一致。
     */
    public static function manifestCanonicalJson(array $manifest)
    {
        unset($manifest['signature']);
        $normalized = self::ksortRecursive($manifest);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }

    /**
     * 递归按键名排序（规范化签名输入）
     */
    protected static function ksortRecursive($value)
    {
        if (is_array($value)) {
            // 区分“关联数组”与“顺序数组”：
            // 顺序数组保持原序并递归；关联数组按键排序。
            if (array_keys($value) === range(0, count($value) - 1)) {
                return array_map([self::class, 'ksortRecursive'], $value);
            }
            ksort($value);
            foreach ($value as $k => $v) {
                $value[$k] = self::ksortRecursive($v);
            }
        }
        return $value;
    }
}
