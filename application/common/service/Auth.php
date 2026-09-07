<?php
namespace app\common\service;

use app\common\model\Teacher;
use app\common\model\OperationLog;
use think\facade\Request;

/**
 * 认证服务
 *
 * Session 里只存用户 ID，每次请求重新查库读取账号状态。
 * 这样管理员把某教师改为「禁用」后，该教师已登录的会话会立刻失效，
 * 不需要等 Session 过期，避免禁用被绕过。
 */
class Auth
{
    const SESSION_KEY = 'keshi_user_id';

    /**
     * 登录校验
     * @return array ['ok'=>bool, 'msg'=>'', 'teacher'=>Teacher|null]
     */
    public static function attempt($username, $password)
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'msg' => '请输入账号和密码', 'teacher' => null];
        }

        $t = Teacher::where('username', $username)->find();
        if (!$t) {
            return ['ok' => false, 'msg' => '账号不存在', 'teacher' => null];
        }
        if (!$t->checkPassword($password)) {
            return ['ok' => false, 'msg' => '密码错误', 'teacher' => null];
        }
        if (!$t->status) {
            return ['ok' => false, 'msg' => '该账号已被禁用，请联系管理员', 'teacher' => null];
        }

        // 写登录信息
        $t->last_login_at = time();
        $t->last_login_ip = Request::ip();
        $t->save();

        session(self::SESSION_KEY, $t->id);

        OperationLog::record($t->id, $t->name, 'login', 'teacher', $t->id,
            '登录系统（' . ($t->isAdmin() ? '管理员' : '教师') . '）');

        return ['ok' => true, 'msg' => '登录成功', 'teacher' => $t];
    }

    /**
     * 当前登录用户（未登录返回 null）
     */
    public static function user()
    {
        $id = session(self::SESSION_KEY);
        if (!$id) {
            return null;
        }
        $t = Teacher::get(intval($id));
        if (!$t || !$t->status) {
            // 账号被删除或禁用，强制退出
            session(self::SESSION_KEY, null);
            return null;
        }
        return $t;
    }

    /**
     * 当前用户ID
     */
    public static function id()
    {
        $u = self::user();
        return $u ? $u->id : 0;
    }

    /**
     * 是否管理员
     */
    public static function isAdmin()
    {
        $u = self::user();
        return $u && $u->isAdmin();
    }

    /**
     * 是否已登录
     */
    public static function check()
    {
        return self::user() !== null;
    }

    /**
     * 退出登录
     */
    public static function logout()
    {
        $u = self::user();
        if ($u) {
            OperationLog::record($u->id, $u->name, 'logout', 'teacher', $u->id, '退出登录');
        }
        session(self::SESSION_KEY, null);
    }
}
