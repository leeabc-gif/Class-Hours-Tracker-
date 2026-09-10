<?php
namespace app\common\model;

use think\Model;

/**
 * 教师 API 令牌（sk- 密钥，供第三方软件调用本站 AI 网关）
 *
 * 安全约定：
 *   - 明文 sk- 仅在「创建成功」这一次响应里返回，之后无法找回
 *   - 库里只存 sha256(明文)，校验时同样 hash 后比对
 *   - ThinkPHP 陷阱：`name` 是 Model 自身属性，读字段必须 getData('name')
 */
class AiToken extends Model
{
    protected $table    = 'ks_ai_token';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    const PREFIX = 'sk-';

    /**
     * 签发一枚新令牌
     * @return array [0]=明文（仅此一次可见） [1]=模型对象
     */
    public static function issue($teacherId, $name, $modelLimit = [], $expiresAt = 0)
    {
        $rand  = function_exists('random_bytes')
            ? random_bytes(16)
            : openssl_random_pseudo_bytes(16);
        $plain = self::PREFIX . bin2hex($rand);

        $row = new self();
        $row->teacher_id   = (int)$teacherId;
        $row->name         = (string)$name;
        $row->token_prefix = substr($plain, 0, 11);   // sk- + 8 位，够辨识又不泄露全文
        $row->token_hash   = hash('sha256', $plain);
        $row->model_limit  = (is_array($modelLimit) && $modelLimit)
            ? json_encode(array_values(array_map('strval', $modelLimit)), JSON_UNESCAPED_UNICODE)
            : '';
        $row->expires_at   = (int)$expiresAt;
        $row->status       = 1;
        $row->save();

        return [$plain, $row];
    }

    /** 用明文查找令牌（网关鉴权入口） */
    public static function findByPlain($plain)
    {
        if (!is_string($plain) || $plain === '') return null;
        return self::where('token_hash', hash('sha256', $plain))->find();
    }

    public function modelLimit()
    {
        $raw = (string)$this->model_limit;
        if ($raw === '') return [];
        $arr = json_decode($raw, true);
        return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
    }

    /** 令牌当前是否可用（启用 且 未过期） */
    public function isValid()
    {
        if ((int)$this->status !== 1) return false;
        $exp = (int)$this->expires_at;
        if ($exp > 0 && $exp <= time()) return false;
        return true;
    }

    public function allowsModel($modelKey)
    {
        $allow = $this->modelLimit();
        if (!$allow) return true;
        return in_array((string)$modelKey, $allow, true);
    }

    public function markUsed()
    {
        $this->last_used_at = time();
        return $this->save();
    }

    public function toArrayLite()
    {
        return [
            'id'           => (int)$this->id,
            'teacher_id'   => (int)$this->teacher_id,
            'name'         => (string)$this->getData('name'),
            'token_prefix' => (string)$this->token_prefix,
            'model_limit'  => $this->modelLimit(),
            'status'       => (int)$this->status,
            'expires_at'   => (int)$this->expires_at,
            'last_used_at' => (int)$this->last_used_at,
            'created_at'   => (int)$this->getData('created_at'),
        ];
    }
}
