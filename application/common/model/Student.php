<?php
namespace app\common\model;

use think\Model;

/**
 * 学生档案 / 登录账号
 *
 * 独立于教师体系（ks_teacher），通过学号 sno 登录。
 * status: 1=正常 0=禁用 2=待审核（自助注册后管理员启用）
 * source: import=批量导入 register=自助注册
 */
class Student extends Model
{
    protected $table    = 'ks_student';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 隐藏密码 */
    protected $hidden = ['password'];

    /** 状态常量 */
    const STATUS_PENDING  = 2;
    const STATUS_ACTIVE   = 1;
    const STATUS_DISABLED = 0;

    public static function hashPassword($plain)
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public function checkPassword($plain)
    {
        return password_verify($plain, $this->password);
    }

    /** 所属班级名称（注意 think\Model 占用 $name，必须 getData） */
    public function className()
    {
        if (!$this->class_id) return '—';
        $c = SchoolClass::get((int)$this->class_id);
        return $c ? $c->getData('name') : '—';
    }

    /** 输出给前端的安全数组（不含密码） */
    public function toSafeArray()
    {
        return [
            'id'        => (int)$this->id,
            'sno'       => (string)$this->sno,
            'name'      => (string)$this->getData('name'),
            'gender'    => (int)$this->gender,
            'class_id'  => (int)$this->class_id,
            'class_name'=> $this->className(),
            'year'      => (int)$this->year,
            'phone'     => (string)$this->phone,
            'avatar'    => (string)$this->avatar,
            'status'    => (int)$this->status,
            'source'    => (string)$this->source,
            'last_login_at' => (int)$this->last_login_at,
            'created_at'    => (int)$this->getData('created_at'),
        ];
    }
}