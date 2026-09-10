<?php
namespace app\common\model;

use think\Model;
use app\common\service\AiConfig;

/**
 * AI 渠道（多厂商上游：OpenAI / Claude / 通义 / DeepSeek / 自定义 …）
 *
 * ThinkPHP 陷阱提醒：
 *   - `name` 是 Model 自身属性（模型名），读字段必须 $this->getData('name')
 *   - `type` 是 Attribute trait 的字段类型转换配置，读字段必须 $this->getData('type')
 */
class AiChannel extends Model
{
    protected $table    = 'ks_ai_channel';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 可用渠道：仅启用，按权重降序、同权重按 id 升序（权重大的先试） */
    public static function enabledList()
    {
        return self::where('status', 1)->order('priority desc, id asc')->select();
    }

    /** 解密后的明文 Key（仅服务端内部使用，绝不出现在任何接口响应里） */
    public function plainKey()
    {
        $enc = (string)$this->api_key;
        return $enc === '' ? '' : AiConfig::decryptKey($enc);
    }

    /** 写入 Key（自动加密；空串表示不改动由调用方判断） */
    public function setPlainKey($plain)
    {
        $plain = (string)$plain;
        $this->api_key = $plain === '' ? '' : AiConfig::encryptKey($plain);
        return $this;
    }

    /** 该渠道声明支持的模型；空数组表示"不限制，转发任意模型" */
    public function modelList()
    {
        $raw = (string)$this->getData('models');
        if ($raw === '') return [];
        $arr = json_decode($raw, true);
        if (!is_array($arr)) return [];
        return array_values(array_filter(array_map('strval', $arr), function ($v) {
            return $v !== '';
        }));
    }

    public function setModelList($models)
    {
        $list = is_array($models) ? array_values(array_filter(array_map('strval', $models))) : [];
        $this->models = $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : '';
        return $this;
    }

    /** 渠道是否允许转发该模型（白名单为空=全放行） */
    public function allowsModel($modelKey)
    {
        $allow = $this->modelList();
        if (!$allow) return true;
        return in_array((string)$modelKey, $allow, true);
    }

    public function markOk()
    {
        $this->last_ok_at = time();
        $this->last_err   = '';
        return $this->save();
    }

    public function markFail($msg)
    {
        $this->last_err = mb_substr((string)$msg, 0, 500);
        return $this->save();
    }

    /** 输出给前端（永不携带 api_key 明文） */
    public function toArrayLite()
    {
        return [
            'id'             => (int)$this->id,
            'name'           => (string)$this->getData('name'),
            'type'           => (string)$this->getData('type'),
            'base_url'       => (string)$this->base_url,
            'key_configured' => $this->plainKey() !== '',
            'models'         => $this->modelList(),
            'priority'       => (int)$this->priority,
            'status'         => (int)$this->status,
            'last_ok_at'     => (int)$this->last_ok_at,
            'last_err'       => (string)$this->last_err,
            'created_at'     => (int)$this->getData('created_at'),
        ];
    }
}
