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

    private static function cipherKey()
    {
        $seed = defined('APP_AI_CONFIG_KEY') ? APP_AI_CONFIG_KEY : '';
        if ($seed === '') $seed = 'keshi-ai-config-change-this-key';
        return hash('sha256', $seed, true);
    }

    private static function encryptKey($plain)
    {
        $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', self::cipherKey(), OPENSSL_RAW_DATA, $iv);
        return 'v1:' . base64_encode($iv . $cipher);
    }

    private static function decryptKey($encoded)
    {
        if ($encoded === '') return '';
        if (strpos($encoded, 'v1:') !== 0) return $encoded;
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) <= 16) return '';
        return (string)openssl_decrypt(substr($raw, 16), 'AES-256-CBC', self::cipherKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }
}
