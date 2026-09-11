<?php
namespace app\common\model;

use think\Model;

/**
 * 学生出勤记录（一节课对一个学生一条）
 */
class StudentAttendance extends Model
{
    protected $table    = 'ks_student_attendance';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}