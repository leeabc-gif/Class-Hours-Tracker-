<?php
// +----------------------------------------------------------------------
// | 中间件配置
// +----------------------------------------------------------------------
return [
    // 默认中间件命名空间
    'default_namespace' => 'app\\http\\middleware\\',

    // 全局 CSRF 防护：所有 POST/PUT/DELETE/PATCH 必须带 X-CSRF-Token
    // 前端通过 /api/auth/csrfToken 拿 token，登录/注册/取 token 本身白名单放行
    'csrf' => [
        'type' => 'path',
        'name' => '*',
        'middleware' => [\app\http\middleware\CsrfVerify::class],
    ],
];
