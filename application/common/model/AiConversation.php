<?php
namespace app\common\model;

use think\Model;

/**
 * AI 会话（按用户隔离，每个用户只能看到自己的会话）
 */
class AiConversation extends Model
{
    protected $table    = 'ks_ai_conversation';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /**
     * 取当前用户的会话，没有则自动创建一个默认会话
     */
    public static function current($userId)
    {
        $c = self::where('user_id', $userId)->order('updated_at desc')->find();
        if (!$c) {
            $c = self::create([
                'user_id' => $userId,
                'title'   => '新的对话',
            ]);
        }
        return $c;
    }

    /**
     * 某用户的会话列表
     */
    public static function listOf($userId)
    {
        return self::where('user_id', $userId)->order('updated_at desc')->select();
    }

    /**
     * 消息列表
     */
    public function messages()
    {
        return AiMessage::where('conversation_id', $this->id)
            ->order('id asc')
            ->select();
    }

    /**
     * 清空该会话的历史消息
     */
    public function clearMessages()
    {
        return AiMessage::where('conversation_id', $this->id)->delete();
    }
}
