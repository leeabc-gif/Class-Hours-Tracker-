<?php
namespace app\common\service;

use app\common\model\Student;
use app\common\model\SchoolClass;
use app\common\model\OperationLog;
use think\facade\Request;

/**
 * 学生鉴权服务
 *
 * 使用独立 session key（keshi_student_id），与教师体系完全隔离。
 * 学生登录不上 ks_teacher 表，不走 Auth.php。
 */
class StudentAuth
{
    const SESSION_KEY = 'keshi_student_id';

    /**
     * 学生登录
     * @return array ['ok'=>bool, 'msg'=>'', 'student'=>Student|null]
     */
    public static function attempt($sno, $password)
    {
        $sno = trim($sno);
        if ($sno === '' || $password === '') {
            return ['ok' => false, 'msg' => '请输入学号和密码', 'student' => null];
        }

        $s = Student::where('sno', $sno)->find();
        if (!$s) {
            return ['ok' => false, 'msg' => '学号不存在', 'student' => null];
        }
        if (!$s->checkPassword($password)) {
            return ['ok' => false, 'msg' => '密码错误', 'student' => null];
        }
        if ((int)$s->status === Student::STATUS_DISABLED) {
            return ['ok' => false, 'msg' => '该账号已被禁用，请联系班主任', 'student' => null];
        }
        if ((int)$s->status === Student::STATUS_PENDING) {
            return ['ok' => false, 'msg' => '该账号待管理员审核，通过后方可登录', 'student' => null];
        }

        // 写登录信息
        $s->last_login_at = time();
        $s->last_login_ip = (string)Request::ip();
        $s->save();

        session(self::SESSION_KEY, (int)$s->id);

        // 首次登录时签 CSRF token
        if (!session('_csrf_token')) {
            session('_csrf_token', bin2hex(random_bytes(32)));
        }

        return ['ok' => true, 'msg' => '登录成功', 'student' => $s];
    }

    /** 当前登录学生（未登录返回 null） */
    public static function user()
    {
        $id = session(self::SESSION_KEY);
        if (!$id) return null;

        $s = Student::get(intval($id));
        if (!$s || (int)$s->status !== Student::STATUS_ACTIVE) {
            session(self::SESSION_KEY, null);
            return null;
        }
        return $s;
    }

    /** 当前学生 ID */
    public static function id()
    {
        $u = self::user();
        return $u ? (int)$u->id : 0;
    }

    /** 是否已登录 */
    public static function check()
    {
        return self::user() !== null;
    }

    /** 退出登录 */
    public static function logout()
    {
        session(self::SESSION_KEY, null);
    }
}