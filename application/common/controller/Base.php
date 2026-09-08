<?php
namespace app\common\controller;

use think\Controller;
use app\common\service\Auth;
use app\common\model\OperationLog;
use think\exception\HttpResponseException;

/**
 * 控制器基类
 *
 * 统一处理：登录态读取、JSON 响应格式、操作日志记录。
 * 子类只需关心业务逻辑。
 */
class Base extends Controller
{
    /** 当前登录用户（Teacher 模型实例），未登录为 null */
    protected $user = null;

    protected function initialize()
    {
        $this->user = Auth::user();

        // 维护模式拦截（用于在线更新等需要短暂停服的场景）。
        // 放行：管理员本人、登录接口、以及更新控制器（在途更新不能被自己拦掉）。
        if (self::underMaintenance() && !$this->maintenanceExempt()) {
            $resp = json([
                'code' => 503,
                'msg'  => '系统正在升级维护中，请稍后刷新页面。',
                'data' => ['maintenance' => true],
            ]);
            throw new HttpResponseException($resp);
        }
    }

    /**
     * 是否处于维护模式（存在 runtime/maintenance.flag 文件即视为维护中）
     */
    public static function underMaintenance()
    {
        $runtime = rtrim((string) app()->getRuntimePath(), DIRECTORY_SEPARATOR);
        return is_file($runtime . DIRECTORY_SEPARATOR . 'maintenance.flag');
    }

    /**
     * 写入/清除维护标记
     */
    public static function setMaintenance($on)
    {
        $runtime = rtrim((string) app()->getRuntimePath(), DIRECTORY_SEPARATOR);
        if (!is_dir($runtime)) {
            @mkdir($runtime, 0755, true);
        }
        $flag = $runtime . DIRECTORY_SEPARATOR . 'maintenance.flag';
        if ($on) {
            return @file_put_contents($flag, "maintenance at " . date('c') . "\n") !== false;
        }
        if (!is_file($flag)) {
            return true;
        }
        return @unlink($flag);
    }

    /**
     * 当前请求是否豁免维护拦截
     */
    protected function maintenanceExempt()
    {
        // 管理员放行，便于其进入后台解除维护/执行更新
        if ($this->user && $this->user->isAdmin()) {
            return true;
        }
        // 登录、登出、以及系统更新控制器放行
        $controller = strtolower((string) $this->request->controller());
        $action     = strtolower((string) $this->request->action());
        if (in_array($controller, ['auth', 'update'], true)) {
            return true;
        }
        // index/首页类页面放行，避免维护期间入口完全空白（前端仍需加载引导）
        if ($controller === 'index' && $action === 'index') {
            return true;
        }
        return false;
    }

    // -------------------- 统一响应 --------------------

    /**
     * 成功响应
     */
    protected function ok($data = null, $msg = 'ok')
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    /**
     * 失败响应
     */
    protected function fail($msg = '操作失败', $code = 1, $data = null)
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }

    // -------------------- 登录校验 --------------------

    /**
     * 要求已登录，否则抛 401
     */
    protected function requireLogin()
    {
        if (!$this->user) {
            $this->abort(401, '登录已失效，请重新登录');
        }
    }

    /**
     * 要求管理员权限，否则抛 403
     */
    protected function requireAdmin()
    {
        $this->requireLogin();
        if (!$this->user->isAdmin()) {
            $this->abort(403, '无权访问，该功能仅限管理员');
        }
    }

    /**
     * 中断并返回 JSON（用于中间件式的权限拦截）
     */
    protected function abort($code, $msg)
    {
        $resp = json(['code' => $code, 'msg' => $msg, 'data' => null]);
        throw new HttpResponseException($resp);
    }

    // -------------------- 参数读取 --------------------

    /**
     * 读取请求参数，支持默认值与类型转换
     */
    protected function input($key, $default = '', $filter = '')
    {
        $v = $this->request->param($key, $default);
        if ($filter === 'int')   return intval($v);
        if ($filter === 'float') return floatval($v);
        if ($filter === 'trim')  return trim(strval($v));
        return $v;
    }

    /**
     * 读取 POST JSON 体（前端统一用 JSON 提交）
     */
    protected function jsonInput()
    {
        $raw = $this->request->getContent();
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // -------------------- 操作日志 --------------------

    /**
     * 记录操作日志
     */
    protected function log($action, $targetType, $targetId, $summary)
    {
        if (!$this->user) return null;
        return OperationLog::record(
            $this->user->id,
            $this->user->name,
            $action,
            $targetType,
            $targetId,
            $summary
        );
    }
}
