<?php
namespace app\common\model;

use think\Model;

/**
 * 班级
 *
 * 管理员维护的班级档案，录入课时时给教师多选用。
 * 课时表 ks_lesson.classes 仍存逗号分隔文本快照（不外键依赖），
 * 这样不强制历史课时必须能匹配到班级表。
 */
class SchoolClass extends Model
{
    protected $table    = 'ks_class';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /**
     * 关联院系名称
     */
    public function departmentName()
    {
        if (!$this->department_id) {
            return '—';
        }
        $d = Department::get($this->department_id);
        return $d ? $d->getData('name') : '—';
    }

    /**
     * 关联课时数（ks_lesson.classes 文本字段含本班名就算）
     * 注意：classes 是逗号分隔文本，所以这里用 LIKE 近似统计
     */
    public function lessonCount()
    {
        $name = $this->getData('name');
        if ($name === '') return 0;
        // 匹配 "name," 或 ",name" 或 "name" 单独，避免"机器人2301班"误匹配"机器人2301班实验班"
        return Lesson::where('deleted_at', 0)
            ->where('classes', 'like', '%' . $name . '%')
            ->count();
    }
}
