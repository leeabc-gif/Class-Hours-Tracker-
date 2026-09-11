<?php
namespace app\common\service;

use app\common\model\Setting;
use app\common\model\TeacherAiConfig;

/**
 * AI 配置解析与敏感字段保护
 */
class AiConfig
{
    const SOURCE_SYSTEM = 'system';
    const SOURCE_CUSTOM = 'custom';

    public static function effective($teacherId)
    {
        $system = [
            'enabled' => intval(Setting::get('ai_enabled', 1)) === 1,
            'url' => trim((string)Setting::get('ai_api_url', '')),
            'key' => (string)Setting::get('ai_api_key', ''),
            'model' => trim((string)Setting::get('ai_model', '')),
            'source' => self::SOURCE_SYSTEM,
        ];
        $custom = TeacherAiConfig::forUser($teacherId);
        if (!$custom || !$system['enabled']) return self::normalize($system);
        if (intval($custom->enabled) !== 1) {
            return self::normalize([
                'enabled' => false,
                'url' => '',
                'key' => '',
                'model' => '',
                'source' => $custom->source === self::SOURCE_CUSTOM ? self::SOURCE_CUSTOM : self::SOURCE_SYSTEM,
            ]);
        }
        if ($custom->source !== self::SOURCE_CUSTOM) return self::normalize($system);

        return self::normalize([
            'enabled' => true,
            'url' => trim((string)$custom->api_url),
            'key' => self::decryptKey((string)$custom->api_key),
            'model' => trim((string)$custom->model),
            'source' => self::SOURCE_CUSTOM,
        ]);
    }

    public static function publicForUser($teacherId)
    {
        $custom = TeacherAiConfig::forUser($teacherId);
        $effective = self::effective($teacherId);
        return [
            'system_enabled' => intval(Setting::get('ai_enabled', 1)),
            'system_configured' => self::isConfigured([
                'enabled' => true,
                'url' => Setting::get('ai_api_url', ''),
                'key' => Setting::get('ai_api_key', ''),
                'model' => Setting::get('ai_model', ''),
            ]),
            'enabled' => $custom ? intval($custom->enabled) : 1,
            'source' => $custom && $custom->source === self::SOURCE_CUSTOM ? self::SOURCE_CUSTOM : self::SOURCE_SYSTEM,
            'api_url' => $custom ? (string)$custom->api_url : '',
            'model' => $custom ? (string)$custom->model : '',
            'key_configured' => $custom ? self::decryptKey((string)$custom->api_key) !== '' : false,
            'effective_source' => $effective['source'],
            'effective_configured' => self::isConfigured($effective),
        ];
    }

    public static function saveForUser($teacherId, $data)
    {
        $source = isset($data['source']) && $data['source'] === self::SOURCE_CUSTOM
            ? self::SOURCE_CUSTOM : self::SOURCE_SYSTEM;
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $url = trim(isset($data['api_url']) ? (string)$data['api_url'] : '');
        $model = trim(isset($data['model']) ? (string)$data['model'] : '');
        $key = isset($data['api_key']) ? trim((string)$data['api_key']) : '';

        if ($source === self::SOURCE_CUSTOM && $enabled) {
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return ['ok' => false, 'msg' => '自定义接口地址必须以 http:// 或 https:// 开头'];
            }
            if (strlen($url) > 500) return ['ok' => false, 'msg' => '接口地址不能超过 500 个字符'];
            if ($model === '') return ['ok' => false, 'msg' => '请填写自定义模型名称'];
            if (strlen($model) > 100) return ['ok' => false, 'msg' => '模型名称不能超过 100 个字符'];
            if ($key === '' && !self::hasKey($teacherId)) {
                return ['ok' => false, 'msg' => '请填写自定义模型 API Key'];
            }
        }

        $row = TeacherAiConfig::forUser($teacherId);
        if (!$row) {
            $row = new TeacherAiConfig();
            $row->teacher_id = intval($teacherId);
        }
        $row->enabled = $enabled;
        $row->source = $source;
        $row->api_url = $url;
        $row->model = $model;
        if ($key !== '') $row->api_key = self::encryptKey($key);
        $row->save();
        return ['ok' => true];
    }

    public static function isConfigured($config)
    {
        return !empty($config['enabled']) && trim((string)$config['url']) !== ''
            && trim((string)$config['key']) !== '' && trim((string)$config['model']) !== '';
    }

    /**
     * 当前用户已保存的自定义 Key 明文（仅服务端内部使用，绝不出接口）
     */
    public static function plainKeyForUser($teacherId)
    {
        $row = TeacherAiConfig::forUser($teacherId);
        return $row ? self::decryptKey((string)$row->api_key) : '';
    }

    /**
     * 从上游拉取模型列表。按渠道 $type 分发到不同端点：
     *   - openai  / qwen / deepseek / custom：GET {base}/models（OpenAI 兼容）
     *   - claude  ：用 Anthropic 兼容代理（绝大多数第三方中转都暴露 /v1/models）
     *   - gemini  ：GET {base}/v1beta/models?key=KEY（Google AI Studio 官方端点）
     *   失败时统一返回 {ok:false, msg, models:[]}
     */
    public static function fetchModels($chatUrl, $key, $type = 'openai')
    {
        $chatUrl = trim((string)$chatUrl);
        if ($chatUrl === '' || !preg_match('#^https?://#i', $chatUrl)) {
            return ['ok' => false, 'msg' => '请先填写接口地址（须以 http:// 或 https:// 开头）', 'models' => []];
        }
        if (strlen($chatUrl) > 500) {
            return ['ok' => false, 'msg' => '接口地址过长', 'models' => []];
        }
        $urlError = self::validateRemoteUrl($chatUrl);
        if ($urlError !== '') {
            return ['ok' => false, 'msg' => $urlError, 'models' => []];
        }
        $key = trim((string)$key);
        if ($key === '') {
            return ['ok' => false, 'msg' => '请填写 API Key（或先保存一次配置后再拉取）', 'models' => []];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'msg' => '当前 PHP 未启用 curl 扩展', 'models' => []];
        }

        $type = in_array($type, ['openai', 'claude', 'qwen', 'deepseek', 'gemini', 'custom'], true) ? $type : 'openai';

        if ($type === 'gemini') {
            return self::fetchModelsGemini($chatUrl, $key);
        }
        // openai / claude / qwen / deepseek / custom：均按 OpenAI 兼容 GET /models 处理
        return self::fetchModelsOpenaiCompatible($chatUrl, $key);
    }

    /** OpenAI 兼容（也覆盖 Claude / 通义 / DeepSeek / 自定义中转） */
    private static function fetchModelsOpenaiCompatible($chatUrl, $key)
    {
        $modelsUrl = self::modelsUrlFrom($chatUrl);
        $resp = null; $httpCode = 0; $err = '';
        $ch = curl_init($modelsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'msg' => '请求模型列表失败：' . $err, 'models' => []];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = is_string($resp) ? mb_substr(trim($resp), 0, 200) : '';
            return ['ok' => false, 'msg' => '接口返回 HTTP ' . $httpCode . ($snippet ? '：' . $snippet : ''), 'models' => []];
        }
        if (!is_string($resp) || strlen($resp) > 2097152) {
            return ['ok' => false, 'msg' => '模型列表响应异常', 'models' => []];
        }
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => '返回内容不是 JSON', 'models' => []];
        }
        $ids = [];
        // OpenAI 格式：{data:[{id:"..."}]}
        if (isset($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $m) {
                if (is_array($m) && !empty($m['id'])) $ids[] = (string)$m['id'];
            }
        }
        // 另一种常见格式：[{id:"..."}]
        if (!$ids && array_is_list($json)) {
            foreach ($json as $m) {
                if (is_array($m) && !empty($m['id'])) $ids[] = (string)$m['id'];
            }
        }
        if (!$ids) {
            return ['ok' => false, 'msg' => '该接口未返回任何模型 id', 'models' => []];
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        return ['ok' => true, 'msg' => '', 'models' => $ids];
    }

    /**
     * Google Gemini 官方：GET {base}/v1beta/models?key=KEY
     * 响应：{models:[{name:"models/gemini-1.5-flash", ...}]}，需把 name 末段抽出来
     */
    private static function fetchModelsGemini($chatUrl, $key)
    {
        $base = rtrim($chatUrl, '/');
        // 用户填的可能是 …/v1beta/…/chat/completions 之类的，统一把末尾的 /chat/completions 去掉再拼 models
        $base = preg_replace('#/chat/completions/?$#i', '', $base);
        $base = rtrim($base, '/');
        $url  = $base . '/v1beta/models?key=' . urlencode($key) . '&pageSize=200';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'msg' => '请求 Gemini 模型列表失败：' . $err, 'models' => []];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = is_string($resp) ? mb_substr(trim($resp), 0, 200) : '';
            return ['ok' => false, 'msg' => 'Gemini 接口返回 HTTP ' . $httpCode . ($snippet ? '：' . $snippet : ''), 'models' => []];
        }
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json['models']) || !is_array($json['models'])) {
            return ['ok' => false, 'msg' => 'Gemini 返回内容不是预期格式', 'models' => []];
        }
        $ids = [];
        foreach ($json['models'] as $m) {
            $name = isset($m['name']) ? (string)$m['name'] : '';
            // name 形如 "models/gemini-1.5-flash" → 取末段
            $short = $name !== '' ? preg_replace('#^models/#', '', $name) : '';
            if ($short !== '') $ids[] = $short;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return ['ok' => false, 'msg' => 'Gemini 接口未返回任何模型', 'models' => []];
        }
        sort($ids, SORT_STRING);
        return ['ok' => true, 'msg' => '', 'models' => $ids];
    }

    /**
     * 由对话接口地址推导模型列表地址：
     *   …/v1/chat/completions → …/v1/models
     *   …/v1（或其他 base）  → …/v1/models
     */
    private static function modelsUrlFrom($url)
    {
        if (preg_match('#/chat/completions/?$#i', $url)) {
            return preg_replace('#/chat/completions/?$#i', '/models', $url);
        }
        if (preg_match('#/models/?$#i', $url)) {
            return $url;
        }
        return rtrim($url, '/') . '/models';
    }

    private static function validateRemoteUrl($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '接口地址格式不正确，且不得包含用户名或密码';
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || $host === 'metadata.google.internal' || $host === '169.254.169.254') {
            return '为安全起见，不允许访问本机或云元数据地址';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return '为安全起见，不允许访问内网或保留 IP 地址';
            }
            return '';
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = isset($record['ip']) ? $record['ip'] : (isset($record['ipv6']) ? $record['ipv6'] : '');
                if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return '接口域名解析到了内网或保留 IP 地址';
                }
            }
        }
        return '';
    }

    private static function normalize($config)
    {
        $config['url'] = trim((string)$config['url']);
        $config['key'] = (string)$config['key'];
        $config['model'] = trim((string)$config['model']);
        $config['enabled'] = !empty($config['enabled']);
        return $config;
    }

    private static function hasKey($teacherId)
    {
        $row = TeacherAiConfig::forUser($teacherId);
        return $row && self::decryptKey((string)$row->api_key) !== '';
    }

    public static function cipherKey()
    {
        $seed = defined('APP_AI_CONFIG_KEY') ? APP_AI_CONFIG_KEY : '';
        if ($seed === '') $seed = 'keshi-ai-config-change-this-key';
        return hash('sha256', $seed, true);
    }

    public static function encryptKey($plain)
    {
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', self::cipherKey(), OPENSSL_RAW_DATA, $iv);
        return 'v1:' . base64_encode($iv . $cipher);
    }

    public static function decryptKey($encoded)
    {
        if ($encoded === '') return '';
        if (strpos($encoded, 'v1:') !== 0) return $encoded;
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) <= 16) return '';
        return (string)openssl_decrypt(substr($raw, 16), 'AES-256-CBC', self::cipherKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }
}
