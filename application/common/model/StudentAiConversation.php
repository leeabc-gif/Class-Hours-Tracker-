<?php
namespace app\common\model;

use think\Model;

/**
 * 学生 AI 答疑会话
 */
class StudentAiConversation extends Model
{
    protected $table    = 'ks_student_ai_conversation';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 消息关联 */
    public function messages()
    {
        return $this->hasMany(StudentAiMessage::class, 'conversation_id');
    }
}