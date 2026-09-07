<?php
namespace app\common\model;

use think\Model;

/**
 * 教师档案 / 登录账号
 * 管理员与教师共用一张表，通过 role 字段区分
 */
class Teacher extends Model
{
    protected $table    = 'ks_teacher';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 角色常量 */
    const ROLE_ADMIN   = 'admin';
    const ROLE_TEACHER = 'teacher';

    /** 隐藏字段，转数组时不输出密码 */
    protected $hidden = ['password'];

    /**
     * 密码加密
     */
    public static function hashPassword($plain)
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    /**
     * 校验密码
     */
    public function checkPassword($plain)
    {
        return password_verify($plain, $this->password);
    }

    /**
     * 是否管理员
     */
    public function isAdmin()
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * 所属院系名称
     *
     * 注意：这里必须用 getData('name') 而不是 $d->name。
     * think\Model 自身声明了 protected $name（存放模型名），
     * 在 Model 子类的代码里访问另一个 Model 子类实例的 $name 时，
     * PHP 允许跨兄弟类访问 protected 属性，于是会拿到模型名
     * 而不是数据库里的 name 字段。getData() 直接读数据表字段，可以避开这个问题。
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
     * 输出给前端的安全数组（不含密码）
     *
     * 同样要注意 $name 被 think\Model 占用，必须走 getData()，
     * 否则前端拿到的姓名会变成模型名 "Teacher"。
     */
    public function toSafeArray()
    {
        return [
            'id'            => $this->id,
            'username'      => $this->username,
            'name'          => $this->getData('name'),
            'department_id' => $this->department_id,
            'department'    => $this->departmentName(),
            'position'      => $this->position,
            'role'          => $this->role,
            'status'        => (int)$this->status,
            'last_login_at' => (int)$this->last_login_at,
        ];
    }
}
