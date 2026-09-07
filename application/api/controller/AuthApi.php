<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\service\Auth;
use app\common\service\Boot;

/**
 * 登录 / 登出 / 当前身份
 */
class AuthApi extends Base
{
    /**
     * 登录
     * 管理员与教师共用入口，登录成功后按角色分流到不同首页
     */
    public function login()
    {
        $data = $this->jsonInput();
        $username = trim(isset($data['username']) ? $data['username'] : '');
        $password = isset($data['password']) ? $data['password'] : '';

        $res = Auth::attempt($username, $password);
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
        Auth::logout();
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
}
