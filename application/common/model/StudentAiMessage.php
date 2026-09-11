<?php
namespace app\common\model;

use think\Model;

/**
 * 学生 AI 答疑消息（内容审计 / 学情画像数据源）
 */
class StudentAiMessage extends Model
{
    protected $table    = 'ks_student_ai_message';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}