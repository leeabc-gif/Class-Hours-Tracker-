<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\service\Auth as AuthService;
use app\common\service\Boot;
use think\facade\Session;

/**
 * 登录 / 登出 / 当前身份
 *
 * 说明：控制器叫 Auth，所以服务类用 AuthService 别名引入，
 * 否则 PHP 会报「类名已存在」的致命错误。
 */
class Auth extends Base
{
    /**
     * 颁发 CSRF token（白名单放行，无需登录）
     * 前端在加载主页面 / 每次刷新时调用，把返回的 token 写入
     * 后续所有写请求的 X-CSRF-Token 头。
     */
    public function csrfToken()
    {
        $token = (string) Session::get('_csrf_token');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf_token', $token);
        }
        return $this->ok(['token' => $token]);
    }
    /**
     * 登录
     * 管理员与教师共用入口，登录成功后按角色分流到不同首页
     */
    public function login()
    {
        $data     = $this->jsonInput();
        $username = trim(isset($data['username']) ? $data['username'] : '');
        $password = isset($data['password']) ? $data['password'] : '';

        $res = AuthService::attempt($username, $password);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }

        $teacher = $res['teacher'];
        return $this->ok([
            'user'     => $teacher->toSafeArray(),
            'redirect' => $teacher->isAdmin() ? '/admin/dashboard' : '/dashboard',
            'boot'     => Boot::common($teacher),
        ], '登录成功');
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        AuthService::logout();
        return $this->ok(null, '已退出登录');
    }

    /**
     * 当前登录身份（前端刷新页面时恢复状态用）
     */
    public function me()
    {
        if (!$this->user) {
            return $this->fail('未登录', 401);
        }
        return $this->ok([
            'user' => $this->user->toSafeArray(),
            'boot' => Boot::common($this->user),
        ]);
    }

    /**
     * 教师自助注册（开放注册 + 待管理员启用）
     * 字段：username / password / name
     * 拒绝与已存在用户名（管理员 / seed 演示账号）冲突
     * 新账号 status=0，待管理员在后台「用户管理」启用后方可登录
     */
    public function register()
    {
        $data     = $this->jsonInput();
        $username = trim(isset($data['username']) ? $data['username'] : '');
        $password = isset($data['password']) ? $data['password'] : '';
        $name     = trim(isset($data['name']) ? $data['name'] : '');

        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            return $this->fail('账号只能包含字母、数字和下划线，长度 3-32 位');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            return $this->fail('密码长度须为 8-72 位');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 32) {
            return $this->fail('真实姓名长度须为 2-32 个字符');
        }

        // 与种子演示账号冲突预检（避免在 ks_teacher.uk_username 唯一索引上爆错）
        $reserved = ['admin', 'wanglaoshi', 'lilaoshi', 'zhaolaoshi'];
        if (in_array(strtolower($username), $reserved, true)) {
            return $this->fail('该账号名不可用，请更换');
        }

        $t = \app\common\model\Teacher::where('username', $username)->find();
        if ($t) {
            return $this->fail('该账号已被注册');
        }

        $new = \app\common\model\Teacher::create([
            'username'      => $username,
            'password'      => \app\common\model\Teacher::hashPassword($password),
            'name'          => $name,
            'department_id' => 0,
            'position'      => '',
            'role'          => 'teacher',
            'status'        => 0, // 0=待启用，管理员审核通过后改为 1
        ]);

        \app\common\model\OperationLog::record(0, $username, 'register', 'teacher', $new->id,
            '教师自助注册（新账号待启用）');

        return $this->ok(['username' => $username, 'status' => 0], '注册成功，请等待管理员启用后登录');
    }
}
