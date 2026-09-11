<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

Route::get('think', function () {
    return 'hello,ThinkPHP5!';
});

Route::get('hello/:name', 'index/hello');

// ---------------------------------------------------------------------
// OpenAI 兼容网关：外部程序（Cherry Studio / NextChat / 自己写的脚本等）
// 用 sk- 令牌调用。地址固定为 /v1/*，客户端把 base_url 填「站点域名/v1」即可。
//
// 注意：这两个接口用 Bearer 鉴权而非 session，
// CSRF 中间件已对携带 Authorization: Bearer 的请求放行。
// ---------------------------------------------------------------------
Route::post('v1/chat/completions', 'api/V1/chatCompletions');
Route::get('v1/models', 'api/V1/models');

// 站内 AI 操练场流式接口（SSE），与 OpenAI 兼容网关同结构
Route::post('playground/stream', 'api/Playground/streamChat');

// ---------------------------------------------------------------------
// 外部课表导入（CSV / Excel）：upload 走 multipart/form-data，其余走 JSON
// ---------------------------------------------------------------------
Route::post('scheduleimport/preview', 'api/ScheduleImport/preview');
Route::post('scheduleimport/import', 'api/ScheduleImport/import');
Route::get('scheduleimport/template', 'api/ScheduleImport/template');

return [

];
