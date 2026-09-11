<?php
namespace app\http\middleware;

use Closure;
use think\facade\Session;
use think\exception\HttpResponseException;

/**
 * CSRF 防护中间件
 *
 * - 保护所有非幂等（POST/PUT/DELETE/PATCH）请求
 * - 校验来源：请求头 X-CSRF-Token 必须与 SESSION 中 _csrf_token 一致
 * - 首次访问应用视图时，前端通过 /api/auth/csrfToken 拿到 token
 *   并在 fetch 时把 token 放到 X-CSRF-Token 头；或通过表单隐藏域 _token
 * - 公共放行路径：登录、注册、获取 CSRF token、安装入口、静态资源
 */
class CsrfVerify
{
    /** 放行白名单（按 path 开头匹配） */
    const ALLOW = [
        'api/auth/login',
        'api/auth/register',
        'api/auth/csrfToken',
        'api/studentauth/login',
        'api/studentauth/register',
        'install/',
    ];

    /** 只对写操作做校验 */
    const UNSAFE_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    public function handle($request, Closure $next)
    {
        $method = strtoupper((string) $request->method());

        // GET / HEAD / OPTIONS 一律放行（含前端首次拉取）
        if (!in_array($method, self::UNSAFE_METHODS, true)) {
            return $next($request);
        }

        $path = '/' . ltrim((string) $request->pathinfo(), '/');

        // 携带 Bearer 凭据的调用（外部程序 / AI 网关）放行：
        // 浏览器不会自动附带 Authorization 头，第三方站点也无法诱导浏览器带上，
        // 因此这类请求不存在 CSRF 风险。
        $auth = $request->header('Authorization', '');
        if (is_string($auth) && stripos(trim($auth), 'Bearer ') === 0) {
            return $next($request);
        }

        foreach (self::ALLOW as $prefix) {
            // 统一补前导斜杠与 $path 对齐：
            // 原写法 $path 带前导斜杠而白名单项不带，导致白名单实际从未命中。
            $p = '/' . ltrim((string) $prefix, '/');
            if ($p !== '/' && strpos($path, $p) === 0) {
                return $next($request);
            }
        }

        $token = $request->header('X-CSRF-Token', '');
        if (!is_string($token)) {
            $token = '';
        }
        // 兼容表单提交：也从 POST body 中读 _token
        if ($token === '') {
            $body = $request->post();
            if (is_array($body) && isset($body['_token'])) {
                $token = (string) $body['_token'];
            }
        }
        $token = trim($token);
        if ($token === '' || !hash_equals((string) Session::get('_csrf_token'), $token)) {
            $resp = json([
                'code' => 419,
                'msg'  => 'CSRF token 无效或已过期，请刷新页面后重试',
                'data' => null,
            ]);
            throw new HttpResponseException($resp);
        }
        return $next($request);
    }
}
