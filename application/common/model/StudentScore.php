<?php
namespace app\common\model;

use think\Model;

/**
 * 学生成绩与评价
 */
class StudentScore extends Model
{
    protected $table    = 'ks_student_score';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}