<?php
namespace app\api\controller;

use app\common\service\StudentAuth;
use think\Controller;

/**
 * 学生端控制器基类
 *
 * 独立于教师 Base 控制器，用 StudentAuth 做鉴权。
 */
class StudentBase extends Controller
{
    /** 当前登录学生（Student 实例），未登录为 null */
    protected $student = null;

    protected function initialize()
    {
        parent::initialize();
        $this->student = StudentAuth::user();
    }

    /** 成功响应 */
    protected function ok($data = null, $msg = 'ok')
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    /** 失败响应 */
    protected function fail($msg = '操作失败', $code = 1, $data = null)
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }

    /** 要求已登录，否则抛 401 */
    protected function requireLogin()
    {
        if (!$this->student) {
            $this->abort(401, '登录已失效，请重新登录');
        }
    }

    /** 中断 */
    protected function abort($code, $msg)
    {
        $resp = json(['code' => $code, 'msg' => $msg, 'data' => null]);
        throw new \think\exception\HttpResponseException($resp);
    }

    /** 读取 POST JSON 体 */
    protected function jsonInput()
    {
        $raw = $this->request->getContent();
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}