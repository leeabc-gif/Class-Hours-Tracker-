<?php
namespace app\common\model;

use think\Model;

/**
 * 教师个人 AI 配置（按教师隔离）
 */
class TeacherAiConfig extends Model
{
    protected $table = 'ks_teacher_ai_config';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    public static function forUser($teacherId)
    {
        return self::where('teacher_id', intval($teacherId))->find();
    }
}
