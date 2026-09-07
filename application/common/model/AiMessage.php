<?php
namespace app\common\model;

use think\Model;

/**
 * AI 消息
 * payload 字段保存结构化数据（JSON），例如课表解析后的预览表
 */
class AiMessage extends Model
{
    protected $table    = 'ks_ai_message';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = false;

    /**
     * 追加一条消息
     *
     * 方法名不能用 append()：think\model\concern\Conversion 里已经有
     * public function append()（设置追加属性），声明成静态方法会直接致命错误。
     */
    public static function add($conversationId, $userId, $role, $content, $intent = '', $payload = null)
    {
        return self::create([
            'conversation_id' => $conversationId,
            'user_id'         => $userId,
            'role'            => $role,
            'intent'          => $intent,
            'content'         => $content,
            'payload'         => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function payloadArray()
    {
        if (!$this->payload) {
            return null;
        }
        $d = json_decode($this->payload, true);
        return $d === null ? null : $d;
    }

    public function toArray()
    {
        $arr = parent::toArray();
        $arr['payload'] = $this->payloadArray();
        return $arr;
    }
}
