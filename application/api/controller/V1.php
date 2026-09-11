<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\AiModel;
use app\common\model\AiToken as AiTokenModel;
use app\common\model\Teacher;
use app\common\service\AiProxy;
use think\exception\HttpResponseException;

/**
 * OpenAI 兼容网关（外部程序用 sk- 令牌调用）
 *
 *   POST /v1/chat/completions
 *   GET  /v1/models
 *
 * 与站内接口的区别：
 *   1) 鉴权用 Authorization: Bearer sk-xxx，不依赖 session/cookie
 *   2) 返回体严格遵循 OpenAI 格式（choices / usage），不是本站的 {code,msg,data}
 *   3) 消耗走教师额度，严格校验（不像站内 AI 助手那样对未分配额度的教师放行）
 *
 * CSRF 已在中间件里对 Bearer 请求放行（浏览器不会自动附带 Authorization 头）。
 */
class V1 extends Base
{
    /** @var AiTokenModel */
    protected $tokenRow = null;
    /** @var Teacher */
    protected $teacher  = null;

    protected function initialize()
    {
        // 网关不走 session，因此不调用 parent::initialize()
        $this->authenticate();
    }

    private function authenticate()
    {
        $auth = trim((string)request()->header('Authorization', ''));
        if (stripos($auth, 'Bearer ') !== 0) {
            $this->abortJson(401, '缺少 Bearer 凭据：请在 Authorization 头传入 “Bearer sk-xxx”', 'authentication_error');
        }
        $plain = trim(substr($auth, 7));
        if ($plain === '') {
            $this->abortJson(401, 'Bearer 凭据为空', 'authentication_error');
        }

        $tok = AiTokenModel::findByPlain($plain);
        if (!$tok) {
            $this->abortJson(401, '令牌无效', 'authentication_error');
        }
        if (!$tok->isValid()) {
            $this->abortJson(401, '令牌已停用或已过期', 'authentication_error');
        }

        $t = Teacher::get((int)$tok->teacher_id);
        if (!$t || !(int)$t->status) {
            $this->abortJson(401, '所属账号不存在或已停用', 'authentication_error');
        }

        $this->tokenRow = $tok;
        $this->teacher  = $t;
    }

    /** POST /v1/chat/completions */
    public function chatCompletions()
    {
        $this->tokenRow->markUsed();

        $raw  = file_get_contents('php://input');
        $body = json_decode($raw ?: '', true);
        if (!is_array($body)) {
            $this->abortJson(400, '请求体必须是合法 JSON', 'invalid_request_error');
        }

        $model = trim((string)(isset($body['model']) ? $body['model'] : ''));
        if ($model === '') $model = AiProxy::defaultModel();
        if ($model === '') {
            $this->abortJson(400, '未指定 model，且系统尚未配置默认模型', 'invalid_request_error');
        }
        if (!$this->tokenRow->allowsModel($model)) {
            $this->abortJson(403, '该令牌未被授权调用模型：' . $model, 'invalid_request_error');
        }

        $messages = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : [];
        $clean    = [];
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            $role    = isset($m['role']) ? (string)$m['role'] : 'user';
            $content = isset($m['content']) ? (string)$m['content'] : '';
            if (!in_array($role, ['system', 'user', 'assistant'], true)) $role = 'user';
            $clean[] = ['role' => $role, 'content' => $content];
        }
        if (!$clean) {
            $this->abortJson(400, 'messages 不能为空，且需为 [{role,content}] 结构', 'invalid_request_error');
        }

        if (!empty($body['stream'])) {
            return $this->streamChatCompletions($body, $model, $clean);
        }

        $res = AiProxy::chat([
            'teacher_id'  => (int)$this->teacher->id,
            'token_id'    => (int)$this->tokenRow->id,
            'model'       => $model,
            'messages'    => $clean,
            'temperature' => isset($body['temperature']) ? floatval($body['temperature']) : 0.7,
            'max_tokens'  => isset($body['max_tokens']) ? intval($body['max_tokens']) : 0,
            'source'      => 'api',
            'ip'          => (string)request()->ip(),
        ]);

        if (empty($res['ok'])) {
            // 额度不足用 429，其他上游问题用 502，方便客户端区分重试策略
            $isQuota = (strpos($res['msg'], '额度') !== false);
            $this->abortJson($isQuota ? 429 : 502, $res['msg'], $isQuota ? 'insufficient_quota' : 'upstream_error');
        }

        $p = intval($res['usage']['prompt_tokens']);
        $c = intval($res['usage']['completion_tokens']);

        return json([
            'id'      => 'chatcmpl-' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8)),
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => $model,
            'choices' => [
                [
                    'index'         => 0,
                    'message'       => ['role' => 'assistant', 'content' => $res['content']],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens'     => $p,
                'completion_tokens' => $c,
                'total_tokens'      => $p + $c,
            ],
        ]);
    }

    /**
     * 流式输出（SSE：Server-Sent Events）
     * - 关闭输出缓冲，逐 chunk flush
     * - 强制禁用 nginx 等反代的缓冲
     * - 首包携带 role，最后一包含 usage + points + channel
     */
    private function streamChatCompletions(array $body, $model, array $clean)
    {
        // 关闭 PHP 端所有缓冲（保证 flush 真的把字节推给客户端）
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ob_implicit_flush(true);
        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');

        $respId = 'chatcmpl-' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
        $created = time();
        $sentSwitch = false;

        // 写一帧 SSE 的辅助
        $sseWrite = function ($payload) {
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            @ob_flush();
            @flush();
        };
        // 错误帧
        $sseError = function ($message, $type = 'upstream_error', $code = null) use ($sseWrite) {
            $sseWrite([
                'error' => ['message' => $message, 'type' => $type, 'code' => $code],
            ]);
            echo "data: [DONE]\n\n";
            @ob_flush();
            @flush();
        };

        // 响应头
        @header('Content-Type: text/event-stream; charset=utf-8');
        @header('Cache-Control: no-cache, no-transform');
        @header('Connection: keep-alive');
        @header('X-Accel-Buffering: no'); // 告诉 nginx 别缓存响应
        @http_response_code(200);

        // 首包（role 提示客户端这是 assistant 流）
        $sseWrite([
            'id'      => $respId,
            'object'  => 'chat.completion.chunk',
            'created' => $created,
            'model'   => $model,
            'choices' => [
                ['index' => 0, 'delta' => ['role' => 'assistant'], 'finish_reason' => null],
            ],
        ]);

        $res = AiProxy::streamChat([
            'teacher_id'  => (int)$this->teacher->id,
            'token_id'    => (int)$this->tokenRow->id,
            'model'       => $model,
            'messages'    => $clean,
            'temperature' => isset($body['temperature']) ? floatval($body['temperature']) : 0.7,
            'max_tokens'  => isset($body['max_tokens']) ? intval($body['max_tokens']) : 0,
            'source'      => 'api',
            'ip'          => (string)request()->ip(),
        ], function ($delta) use ($sseWrite, $respId, $created, $model, &$sentSwitch) {
            // 内部"换渠道"信号：__SWITCH__<id>|<name>
            if (strpos($delta, '__SWITCH__') === 0) {
                if (!$sentSwitch) {
                    $sentSwitch = true;
                    // 用 ping 注释让客户端能感知，不影响正文解析
                    echo ": switch-channel " . substr($delta, 10) . "\n\n";
                    @ob_flush(); @flush();
                }
                return;
            }
            $sseWrite([
                'id'      => $respId,
                'object'  => 'chat.completion.chunk',
                'created' => $created,
                'model'   => $model,
                'choices' => [
                    ['index' => 0, 'delta' => ['content' => $delta], 'finish_reason' => null],
                ],
            ]);
        });

        if (empty($res['ok'])) {
            $isQuota = (strpos($res['msg'], '额度') !== false);
            $sseError($res['msg'], $isQuota ? 'insufficient_quota' : 'upstream_error');
            return;
        }

        $p = intval($res['usage']['prompt_tokens']);
        $c = intval($res['usage']['completion_tokens']);
        $points = (float)$res['points'];

        // 终止块：携带 finish_reason + usage
        $sseWrite([
            'id'      => $respId,
            'object'  => 'chat.completion.chunk',
            'created' => $created,
            'model'   => $model,
            'choices' => [
                ['index' => 0, 'delta' => new \stdClass(), 'finish_reason' => 'stop'],
            ],
            'usage' => [
                'prompt_tokens'     => $p,
                'completion_tokens' => $c,
                'total_tokens'      => $p + $c,
                'points'            => $points,
                'channel_id'        => (int)$res['channel_id'],
            ],
        ]);
        // 显式结束
        echo "data: [DONE]\n\n";
        @ob_flush(); @flush();
    }

    /** GET /v1/models */
    public function models()
    {
        $limit = $this->tokenRow->modelLimit();
        $list  = [];

        foreach (AiModel::enabledList() as $m) {
            $key = (string)$m->model_key;
            if ($limit && !in_array($key, $limit, true)) continue;
            $list[] = ['id' => $key, 'object' => 'model', 'created' => time(), 'owned_by' => 'keshi'];
        }
        // 令牌白名单里的模型若尚未登记进倍率表，也要列出来
        foreach ($limit as $k) {
            $found = false;
            foreach ($list as $l) {
                if ($l['id'] === $k) { $found = true; break; }
            }
            if (!$found) $list[] = ['id' => $k, 'object' => 'model', 'created' => time(), 'owned_by' => 'keshi'];
        }
        // 兜底：至少给出系统默认模型，避免客户端拿到空列表报错
        if (!$list) {
            $d = AiProxy::defaultModel();
            if ($d !== '') $list[] = ['id' => $d, 'object' => 'model', 'created' => time(), 'owned_by' => 'keshi'];
        }

        return json(['object' => 'list', 'data' => $list]);
    }

    /** 抛 OpenAI 风格的错误响应 */
    private function abortJson($httpCode, $message, $type = 'invalid_request_error', $code = null)
    {
        throw new HttpResponseException(json([
            'error' => [
                'message' => $message,
                'type'    => $type,
                'code'    => $code,
            ],
        ], $httpCode));
    }
}
