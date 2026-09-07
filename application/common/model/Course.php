<?php
namespace app\common\model;

use think\Model;

/**
 * 课程库
 * teacher_id = 0  => 全校公共课程（管理员维护，所有教师可见）
 * teacher_id > 0  => 教师私有课程（仅本人可见，可单独设单价）
 */
class Course extends Model
{
    protected $table    = 'ks_course';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /**
     * 某教师可用的课程：全校公共课程 + 本人私有课程
     * @param int  $teacherId 教师ID
     * @param bool $all       管理员传 true 可取全部（含他人私有，用于后台查看）
     */
    public static function forTeacher($teacherId, $all = false)
    {
        $query = self::where('status', 1);
        if (!$all) {
            $query->where(function ($q) use ($teacherId) {
                $q->where('teacher_id', 0)->whereOr('teacher_id', $teacherId);
            });
        }
        return $query->order('teacher_id asc, id asc')->select();
    }

    /**
     * 判断某课程是否对某教师可见
     */
    public static function visibleTo($course, $teacherId)
    {
        if (!$course) {
            return false;
        }
        // 用 getData() 读取真实字段值，避开 think\Model::$name 的 protected 属性坑
        $owner = $course->getData('teacher_id');
        return $owner == 0 || $owner == $teacherId;
    }

    /**
     * 新增课程时做重名校验（同一归属下课程名不可重复）
     */
    public static function findByName($name, $teacherId)
    {
        return self::where('name', $name)
            ->where('teacher_id', $teacherId)
            ->find();
    }
}
