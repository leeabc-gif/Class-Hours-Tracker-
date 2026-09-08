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
        // S-2：先做鉴权（requireLogin/requireAdmin 由子类调用），
        // 再做维护拦截，避免未登录/普通用户被 503 泄漏"系统是否在维护"。
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
            // LOCK_EX 写：避免并发写产生半截内容
            $ok = @file_put_contents($flag, "maintenance at " . date('c') . "\n", LOCK_EX) !== false;
            if ($ok) {
                // 必须-5：兜底关闭 —— 若 PHP 后续 fatal / exit / OOM 跳过 finally，
                // shutdown_function 仍会执行，强制清除维护标记，避免系统被锁死。
                // 这里只注册一次，依靠维护标记本身作为去重信号。
                static $guardRegistered = false;
                if (!$guardRegistered) {
                    $guardRegistered = true;
                    register_shutdown_function(function () {
                        try {
                            $runtime2 = rtrim((string) app()->getRuntimePath(), DIRECTORY_SEPARATOR);
                            $f = $runtime2 . DIRECTORY_SEPARATOR . 'maintenance.flag';
                            if (is_file($f)) {
                                @unlink($f);
                                @file_put_contents(
                                    $runtime2 . DIRECTORY_SEPARATOR . 'maintenance.auto_cleared',
                                    date('c') . " maintenance.flag auto-cleared by shutdown_function\n"
                                );
                            }
                        } catch (\Throwable $e) {
                            // 兜底不能再抛
                        }
                    });
                }
            }
            return $ok;
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
        // 登录、登出、放行（避免登录接口被维护锁卡死）
        $controller = strtolower((string) $this->request->controller());
        $action     = strtolower((string) $this->request->action());
        if (in_array($controller, ['auth'], true)) {
            return true;
        }
        // 在线更新控制器自身放行（在途更新需要继续调用 updateCheck/updateInstall）
        if ($controller === 'admin' && in_array($action, ['updatestatus', 'updatecheck', 'updateinstall', 'updaterollback', 'maintenancetoggle'], true)) {
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
        // 登录用户也走维护拦截（已在 requireAdmin 之前会再校验一次，覆盖普通用户路由）
        $this->enforceMaintenance();
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
        $this->enforceMaintenance();
    }

    /**
     * 维护模式拦截：仅在已鉴权后调用，避免对未登录用户泄漏维护状态。
     * 放行：管理员本人、登录/登出/Cookie 探活、更新控制器自身。
     */
    protected function enforceMaintenance()
    {
        if (!self::underMaintenance() || $this->maintenanceExempt()) {
            return;
        }
        // 维护时附带 Retry-After（按当前 flag 时间推一个短延迟），
        // 让浏览器/爬虫/proxy 知道几秒后可重试。
        $runtime = rtrim((string) app()->getRuntimePath(), DIRECTORY_SEPARATOR);
        $flag = $runtime . DIRECTORY_SEPARATOR . 'maintenance.flag';
        $retry = 30;
        if (is_file($flag)) {
            $mtime = @filemtime($flag);
            if (is_int($mtime)) {
                $retry = max(5, 30 - (time() - $mtime));
            }
        }
        $resp = json([
            'code' => 503,
            'msg'  => '系统正在升级维护中，请稍后刷新页面。',
            'data' => ['maintenance' => true],
        ]);
        $httpResp = \think\Response::create($resp, 'json', 503, ['Retry-After' => (string) $retry]);
        throw new HttpResponseException($httpResp);
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
