<?php
namespace app\common\model;

use think\Model;

/**
 * 常用课程收藏（按教师隔离）
 */
class CourseFavorite extends Model
{
    protected $table    = 'ks_course_favorite';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = false;

    /**
     * 某教师收藏的课程 ID 列表
     */
    public static function idsOf($teacherId)
    {
        return self::where('teacher_id', $teacherId)->column('course_id');
    }

    /**
     * 切换收藏状态，返回切换后是否已收藏
     */
    public static function toggle($teacherId, $courseId)
    {
        $exist = self::where('teacher_id', $teacherId)
            ->where('course_id', $courseId)
            ->find();
        if ($exist) {
            $exist->delete();
            return false;
        }
        self::create([
            'teacher_id' => $teacherId,
            'course_id'  => $courseId,
        ]);
        return true;
    }
}
