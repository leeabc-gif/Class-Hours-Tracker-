<?php
namespace app\common\model;

use think\Model;

/**
 * 院系
 */
class Department extends Model
{
    protected $table    = 'ks_department';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /**
     * 关联教师数量
     */
    public function teacherCount()
    {
        return Teacher::where('department_id', $this->id)->count();
    }
}
