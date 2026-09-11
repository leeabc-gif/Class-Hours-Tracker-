<?php
namespace app\common\service;

use app\common\model\AiChannel;
use app\common\model\AiModel;
use app\common\model\AiUsageLog;
use app\common\model\Setting;
use app\common\service\StudentQuotaService;

/**
 * AI 中转代理（对标 New API / One API 的转发核心）
 *
 * 职责：选渠道 → 转发 → 解析 usage → 计费 → 记日志 → 失败自动切下一渠道
 *
 * 兼容策略：
 *   若管理员尚未配置任何「AI 渠道」，自动回退到旧版的单条系统/个人配置
 *   （ks_setting.ai_api_url / ks_teacher_ai_config），保证升级后老站点不失效，
 *   且回退路径同样纳入计费与日志。
 */
class AiProxy
{
    const MAX_TRY_CHANNELS = 3;
    const DEFAULT_TIMEOUT  = 60;

    /**
     * 发起一次聊天补全（非流式）
     *
     * @param array $opt
     *   teacher_id  必填
     *   model       可选，缺省时取 ai_model 配置 / 渠道首个模型
     *   messages    必填，[['role'=>'user','content'=>'…'], …]
     *   temperature 可选，默认 0.3
     *   max_tokens  可选
     *   token_id    可选，外部 API 调用时传令牌 id，站内为 0
     *   source      chat / playground / api，默认 chat
     *   ip          可选
     * @return array ok/msg/content/model/usage/points/channel_id/latency_ms/quota
     */
    public static function chat(array $opt)
    {
        $ownerType = in_array((string)(isset($opt['owner_type']) ? $opt['owner_type'] : 'teacher'), ['teacher','student'], true)
            ? (string)$opt['owner_type'] : 'teacher';
        $isStudent = ($ownerType === 'student');
        $teacherId = intval(isset($opt['teacher_id']) ? $opt['teacher_id'] : 0);
        $studentId = intval(isset($opt['student_id']) ? $opt['student_id'] : 0);
        $ownerId   = $isStudent ? $studentId : $teacherId;
        if ($ownerId <= 0) {
            return self::fail('缺少用户身份，无法计费');
        }

        $messages = isset($opt['messages']) && is_array($opt['messages']) ? $opt['messages'] : [];
        if (!$messages) {
            return self::fail('消息内容为空');
        }

        $source      = in_array((string)(isset($opt['source']) ? $opt['source'] : 'chat'), ['chat', 'playground', 'api', 'student'], true)
            ? (string)$opt['source'] : 'chat';
        $tokenId     = intval(isset($opt['token_id']) ? $opt['token_id'] : 0);
        $temperature = isset($opt['temperature']) ? floatval($opt['temperature']) : 0.3;
        $ip          = (string)(isset($opt['ip']) ? $opt['ip'] : '');

        // ---- 1) 额度粗检 ----
        if ($isStudent) {
            $pre = StudentQuotaService::preCheck($studentId);
            if (!$pre['ok']) {
                return array_merge(self::fail($pre['msg']), ['quota' => StudentQuotaService::summary($studentId)]);
            }
        } else {
            $strict = ($source !== 'chat');
            $pre = QuotaService::preCheck($teacherId, $strict);
            if (!$pre['ok']) {
                return array_merge(self::fail($pre['msg']), ['quota' => QuotaService::summary($teacherId)]);
            }
        }

        // ---- 2) 决定模型 ----
        $model = trim((string)(isset($opt['model']) ? $opt['model'] : ''));
        if ($model === '') $model = self::defaultModel();
        if ($model === '') {
            return self::fail('未指定模型，且系统未配置默认模型（请在「AI 渠道」中先添加渠道）');
        }

        // ---- 3) 选渠道 ----
        $channels = self::pickChannels($model);

        // ---- 4) 无渠道则回退旧版单条配置 ----
        if (!$channels) {
            return self::legacyChat($teacherId, $model, $messages, $temperature, $tokenId, $source, $ip);
        }

        // ---- 5) 逐个渠道尝试 ----
        $tried  = 0;
        $lastErr = '';
        foreach ($channels as $ch) {
            if ($tried >= self::MAX_TRY_CHANNELS) break;
            $tried++;

            $key = $ch->plainKey();
            if ($key === '') {
                $lastErr = '渠道「' . $ch->getData('name') . '」未配置 API Key';
                continue;
            }

            $res = self::requestChannel($ch, $key, $model, $messages, $temperature, $opt);
            if ($res['ok']) {
                $ch->markOk();

                $promptTokens     = intval($res['usage']['prompt_tokens']);
                $completionTokens = intval($res['usage']['completion_tokens']);
                $points           = AiModel::cost($model, $promptTokens, $completionTokens);

                // 结算（原子扣减）
                if ($isStudent) {
                    $settle = StudentQuotaService::settle($studentId, $points);
                } else {
                    $settle = QuotaService::settle($teacherId, $points);
                }

                $logData = [
                    'teacher_id'        => $teacherId,
                    'owner_type'        => $ownerType,
                    'student_id'        => $studentId,
                    'token_id'          => $tokenId,
                    'channel_id'        => (int)$ch->id,
                    'model'             => $model,
                    'prompt_tokens'     => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'points'            => $points,
                    'latency_ms'        => $res['latency_ms'],
                    'status'            => 1,
                    'source'            => $source,
                    'ip'                => $ip,
                ];
                AiUsageLog::record($logData);

                $quotaSummary = $isStudent ? StudentQuotaService::summary($studentId) : QuotaService::summary($teacherId);

                return [
                    'ok'         => true,
                    'msg'        => '',
                    'content'    => $res['content'],
                    'model'      => $model,
                    'usage'      => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
                    'points'     => $points,
                    'channel_id' => (int)$ch->id,
                    'latency_ms' => $res['latency_ms'],
                    'settled'    => $settle['ok'] ? 1 : 0,
                    'quota'      => $quotaSummary,
                ];
            }

            $lastErr = $res['msg'];
            $ch->markFail($res['msg']);
        }

        // ---- 6) 全部失败 ----
        AiUsageLog::record([
            'teacher_id' => $teacherId,
            'owner_type' => $ownerType,
            'student_id' => $studentId,
            'token_id'   => $tokenId,
            'channel_id' => 0,
            'model'      => $model,
            'status'     => 0,
            'error_msg'  => $lastErr,
            'source'     => $source,
            'ip'         => $ip,
        ]);

        $errQuota = $isStudent ? StudentQuotaService::summary($studentId) : QuotaService::summary($teacherId);
        return array_merge(self::fail('AI 调用失败：' . $lastErr), ['quota' => $errQuota]);
    }

    /**
     * 回退通道：沿用旧版单条配置（ks_setting.ai_* 或教师自定义 ks_teacher_ai_config）
     * 这样未配置渠道的老站点升级后依然可用，且同样计费、同样记日志。
     */
    private static function legacyChat($teacherId, $model, array $messages, $temperature, $tokenId, $source, $ip)
    {
        $cfg = AiConfig::effective($teacherId);
        if (!AiConfig::isConfigured($cfg)) {
            return array_merge(
                self::fail('尚未配置任何 AI 渠道或系统模型，请联系管理员先在「AI 渠道」中添加'),
                ['quota' => QuotaService::summary($teacherId)]
            );
        }
        if (!preg_match('#^https?://#i', $cfg['url'])) {
            return self::fail('AI 接口地址必须以 http:// 或 https:// 开头');
        }

        $start = microtime(true);
        $body  = json_encode([
            'model'       => $model !== '' ? $model : $cfg['model'],
            'messages'    => $messages,
            'temperature' => $temperature,
            'user'        => 'user_' . $teacherId,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($cfg['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::DEFAULT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp   = curl_exec($ch);
        $http   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        $latency = (int)round((microtime(true) - $start) * 1000);

        if ($err || $http < 200 || $http >= 300 || !$resp) {
            $msg = $err ?: ('HTTP ' . $http);
            AiUsageLog::record([
                'teacher_id' => $teacherId, 'token_id' => $tokenId, 'channel_id' => 0,
                'model' => $model, 'status' => 0, 'error_msg' => $msg,
                'latency_ms' => $latency, 'source' => $source, 'ip' => $ip,
            ]);
            return self::fail('AI 调用失败：' . $msg);
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            return self::fail('AI 返回内容不是合法 JSON');
        }

        $content = self::extractContent($json);
        if ($content === '') {
            return self::fail('AI 返回内容为空');
        }

        $usage = self::extractUsage($json);
        $points = AiModel::cost($model, $usage['prompt_tokens'], $usage['completion_tokens']);
        $settle = QuotaService::settle($teacherId, $points);

        AiUsageLog::record([
            'teacher_id'        => $teacherId,
            'token_id'          => $tokenId,
            'channel_id'        => 0,
            'model'             => $model,
            'prompt_tokens'     => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'points'            => $points,
            'latency_ms'        => $latency,
            'status'            => 1,
            'source'            => $source,
            'ip'                => $ip,
        ]);

        return [
            'ok'         => true,
            'msg'        => '',
            'content'    => $content,
            'model'      => $model,
            'usage'      => $usage,
            'points'     => $points,
            'channel_id' => 0,
            'latency_ms' => $latency,
            'settled'    => $settle['ok'] ? 1 : 0,
            'quota'      => QuotaService::summary($teacherId),
        ];
    }

    /** 按权重挑选支持该模型的启用渠道 */
    private static function pickChannels($modelKey)
    {
        $out = [];
        foreach (AiChannel::enabledList() as $ch) {
            if ($ch->allowsModel($modelKey)) $out[] = $ch;
        }
        return $out;
    }

    /**
     * 流式聊天补全（SSE）
     *
     * 与 chat() 同样的渠道选路、计费、记日志，但响应正文按 SSE 逐 chunk 推给 $onDelta 回调。
     * 渠道失败自动切下一个（不超过 MAX_TRY_CHANNELS）。
     *
     * @param array  $opt     同 chat()
     * @param callable $onDelta function(string $delta): void  每收到一段正文调用一次
     * @return array  {ok, msg, model, content, usage, points, channel_id, latency_ms, settled, quota}
     *                $content 是 $onDelta 累积拼出的完整正文（用于日志/回退渲染）
     */
    public static function streamChat(array $opt, $onDelta)
    {
        if (!is_callable($onDelta)) {
            return self::fail('流式回调不可调用');
        }

        $ownerType = in_array((string)(isset($opt['owner_type']) ? $opt['owner_type'] : 'teacher'), ['teacher','student'], true)
            ? (string)$opt['owner_type'] : 'teacher';
        $isStudent = ($ownerType === 'student');
        $teacherId = intval(isset($opt['teacher_id']) ? $opt['teacher_id'] : 0);
        $studentId = intval(isset($opt['student_id']) ? $opt['student_id'] : 0);
        $ownerId   = $isStudent ? $studentId : $teacherId;
        if ($ownerId <= 0) return self::fail('缺少用户身份，无法计费');

        $messages = isset($opt['messages']) && is_array($opt['messages']) ? $opt['messages'] : [];
        if (!$messages) return self::fail('消息内容为空');

        $source = isset($opt['source']) ? (string)$opt['source'] : 'chat';
        if (!in_array($source, ['chat', 'playground', 'api', 'student'], true)) $source = 'chat';
        $tokenId     = intval(isset($opt['token_id']) ? $opt['token_id'] : 0);
        $temperature = isset($opt['temperature']) ? floatval($opt['temperature']) : 0.3;
        $ip          = (string)(isset($opt['ip']) ? $opt['ip'] : '');

        // 额度粗检
        if ($isStudent) {
            $pre = StudentQuotaService::preCheck($studentId);
            if (!$pre['ok']) {
                return array_merge(self::fail($pre['msg']), ['quota' => StudentQuotaService::summary($studentId)]);
            }
        } else {
            $strict = ($source !== 'chat');
            $pre = QuotaService::preCheck($teacherId, $strict);
            if (!$pre['ok']) {
                return array_merge(self::fail($pre['msg']), ['quota' => QuotaService::summary($teacherId)]);
            }
        }

        $model = trim((string)(isset($opt['model']) ? $opt['model'] : ''));
        if ($model === '') $model = self::defaultModel();
        if ($model === '') {
            return self::fail('未指定模型，且系统未配置默认模型（请在「AI 渠道」中先添加渠道）');
        }

        $channels = self::pickChannels($model);
        if (!$channels) {
            return self::fail('尚未配置任何 AI 渠道，请联系管理员先在「AI 渠道」中添加');
        }

        $tried = 0;
        $lastErr = '';
        foreach ($channels as $ch) {
            if ($tried >= self::MAX_TRY_CHANNELS) break;
            $tried++;

            $key = $ch->plainKey();
            if ($key === '') {
                $lastErr = '渠道「' . $ch->getData('name') . '」未配置 API Key';
                continue;
            }

            // 给客户端一个"换渠道了"的信号，便于排查
            try { @call_user_func($onDelta, '__SWITCH__' . (int)$ch->id . '|' . $ch->getData('name')); } catch (\Throwable $e) {}

            $res = self::requestChannelStream($ch, $key, $model, $messages, $temperature, $opt, $onDelta);
            if ($res['ok']) {
                $ch->markOk();
                $promptTokens     = intval($res['usage']['prompt_tokens']);
                $completionTokens = intval($res['usage']['completion_tokens']);
                $points           = AiModel::cost($model, $promptTokens, $completionTokens);

                if ($isStudent) {
                    $settle = StudentQuotaService::settle($studentId, $points);
                } else {
                    $settle = QuotaService::settle($teacherId, $points);
                }

                $logData = [
                    'teacher_id'        => $teacherId,
                    'owner_type'        => $ownerType,
                    'student_id'        => $studentId,
                    'token_id'          => $tokenId,
                    'channel_id'        => (int)$ch->id,
                    'model'             => $model,
                    'prompt_tokens'     => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'points'            => $points,
                    'latency_ms'        => $res['latency_ms'],
                    'status'            => 1,
                    'source'            => $source,
                    'ip'                => $ip,
                ];
                AiUsageLog::record($logData);

                $quotaSummary = $isStudent ? StudentQuotaService::summary($studentId) : QuotaService::summary($teacherId);

                return [
                    'ok'         => true,
                    'msg'        => '',
                    'model'      => $model,
                    'content'    => $res['content'],
                    'usage'      => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
                    'points'     => $points,
                    'channel_id' => (int)$ch->id,
                    'latency_ms' => $res['latency_ms'],
                    'settled'    => $settle['ok'] ? 1 : 0,
                    'quota'      => $quotaSummary,
                ];
            }
            $lastErr = $res['msg'];
            $ch->markFail($res['msg']);
        }

        $logData = [
            'teacher_id' => $teacherId,
            'owner_type' => $ownerType,
            'student_id' => $studentId,
            'token_id'   => $tokenId,
            'channel_id' => 0,
            'model'      => $model,
            'status'     => 0,
            'error_msg'  => $lastErr,
            'source'     => $source,
            'ip'         => $ip,
        ];
        AiUsageLog::record($logData);

        $errQuota = $isStudent ? StudentQuotaService::summary($studentId) : QuotaService::summary($teacherId);
        return array_merge(self::fail('AI 调用失败：' . $lastErr), ['quota' => $errQuota]);
    }

    /** 单一渠道流式转发（curl WRITEFUNCTION + SSE 行解析） */
    private static function requestChannelStream($chRow, $key, $model, array $messages, $temperature, array $opt, $onDelta)
    {
        $url = self::chatUrl($chRow->base_url);
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'stream'      => true,
        ];
        if (!empty($opt['max_tokens'])) $body['max_tokens'] = intval($opt['max_tokens']);

        $start = microtime(true);
        $accumulated = '';
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $buffer = '';
        $httpCode = 0;
        $curlErr = '';
        $firstByteOk = false;

        $chh = curl_init($url);
        curl_setopt_array($chh, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => intval(isset($opt['timeout']) ? $opt['timeout'] : self::DEFAULT_TIMEOUT),
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$accumulated, &$usage, &$firstByteOk, $onDelta) {
                $firstByteOk = true;
                $buffer .= $chunk;
                // 按 \n\n 切事件
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    self::parseSseEvent($event, $accumulated, $usage, $onDelta);
                }
                return strlen($chunk);
            },
        ]);

        $resp = curl_exec($chh);
        $httpCode = (int)curl_getinfo($chh, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($chh);
        curl_close($chh);
        $latency = (int)round((microtime(true) - $start) * 1000);

        if ($curlErr !== '') {
            return ['ok' => false, 'msg' => '网络错误：' . $curlErr, 'latency_ms' => $latency, 'content' => '', 'usage' => $usage];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            // 失败时把已读到的 body 当错误信息（OpenAI 流式失败会一次性回 JSON）
            $snippet = is_string($resp) ? mb_substr(trim($resp), 0, 200) : '';
            return ['ok' => false, 'msg' => 'HTTP ' . $httpCode . ($snippet ? '：' . $snippet : ''), 'latency_ms' => $latency, 'content' => '', 'usage' => $usage];
        }
        if (!$firstByteOk) {
            return ['ok' => false, 'msg' => '上游流式响应为空', 'latency_ms' => $latency, 'content' => '', 'usage' => $usage];
        }
        if ($buffer !== '') {
            // 兜底：最后一段可能不带 \n\n
            self::parseSseEvent($buffer, $accumulated, $usage, $onDelta);
        }
        if ($accumulated === '') {
            return ['ok' => false, 'msg' => '上游流式未返回有效内容', 'latency_ms' => $latency, 'content' => '', 'usage' => $usage];
        }

        return [
            'ok'         => true,
            'content'    => $accumulated,
            'usage'      => $usage,
            'latency_ms' => $latency,
        ];
    }

    /**
     * 解析一段 SSE 事件（一段可能多行）。
     * 支持：event: / data: / 空行（已在外层按 \n\n 切好）。
     * 终止信号 [DONE] 视作正常结束。
     */
    private static function parseSseEvent($event, &$accumulated, &$usage, $onDelta)
    {
        $dataLines = [];
        foreach (preg_split("/\r\n|\n/", $event) as $line) {
            if ($line === '' || strpos($line, ':') === false) continue;
            $field = substr($line, 0, strpos($line, ':'));
            $val   = ltrim(substr($line, strpos($line, ':') + 1));
            if ($field === 'data') $dataLines[] = $val;
        }
        if (!$dataLines) return;
        $payload = implode("\n", $dataLines);
        if ($payload === '[DONE]') return;
        $json = json_decode($payload, true);
        if (!is_array($json)) return;

        // 收 usage（部分上游在最后一帧才发）
        if (isset($json['usage']) && is_array($json['usage'])) {
            $usage['prompt_tokens']     = intval(isset($json['usage']['prompt_tokens'])     ? $json['usage']['prompt_tokens']     : $usage['prompt_tokens']);
            $usage['completion_tokens'] = intval(isset($json['usage']['completion_tokens']) ? $json['usage']['completion_tokens'] : $usage['completion_tokens']);
        }

        // 收 delta.content
        if (isset($json['choices'][0]['delta']['content'])) {
            $delta = (string)$json['choices'][0]['delta']['content'];
            if ($delta !== '') {
                $accumulated .= $delta;
                try { @call_user_func($onDelta, $delta); } catch (\Throwable $e) {}
            }
        } elseif (isset($json['choices'][0]['text'])) {
            $delta = (string)$json['choices'][0]['text'];
            if ($delta !== '') {
                $accumulated .= $delta;
                try { @call_user_func($onDelta, $delta); } catch (\Throwable $e) {}
            }
        }
    }

    /** 单一渠道转发（非流式） */
    private static function requestChannel($ch, $key, $model, array $messages, $temperature, array $opt)
    {
        $url = self::chatUrl($ch->base_url);
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ];
        if (!empty($opt['max_tokens'])) $body['max_tokens'] = intval($opt['max_tokens']);

        $start = microtime(true);
        $chh = curl_init($url);
        curl_setopt_array($chh, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => intval(isset($opt['timeout']) ? $opt['timeout'] : self::DEFAULT_TIMEOUT),
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($chh);
        $http = (int)curl_getinfo($chh, CURLINFO_HTTP_CODE);
        $err  = curl_error($chh);
        curl_close($chh);
        $latency = (int)round((microtime(true) - $start) * 1000);

        if ($err) {
            return ['ok' => false, 'msg' => '网络错误：' . $err, 'latency_ms' => $latency];
        }
        if ($http < 200 || $http >= 300) {
            $snippet = is_string($resp) ? mb_substr(trim($resp), 0, 200) : '';
            return ['ok' => false, 'msg' => 'HTTP ' . $http . ($snippet ? '：' . $snippet : ''), 'latency_ms' => $latency];
        }
        if (!is_string($resp) || $resp === '') {
            return ['ok' => false, 'msg' => '上游返回空响应', 'latency_ms' => $latency];
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => '上游返回不是合法 JSON', 'latency_ms' => $latency];
        }

        $content = self::extractContent($json);
        if ($content === '') {
            return ['ok' => false, 'msg' => '上游未返回有效内容', 'latency_ms' => $latency];
        }

        return [
            'ok'         => true,
            'content'    => $content,
            'usage'      => self::extractUsage($json),
            'latency_ms' => $latency,
        ];
    }

    /** 兼容多种上游响应格式取正文 */
    private static function extractContent(array $json)
    {
        if (isset($json['choices'][0]['message']['content'])) {
            return trim((string)$json['choices'][0]['message']['content']);
        }
        if (isset($json['choices'][0]['text'])) {
            return trim((string)$json['choices'][0]['text']);
        }
        if (isset($json['result']) && is_string($json['result'])) {
            return trim($json['result']);
        }
        if (isset($json['output']['text']) && is_string($json['output']['text'])) {
            return trim($json['output']['text']);
        }
        return '';
    }

    /** 取 token 用量；上游不给则记 0（callLLM 场景常见） */
    private static function extractUsage(array $json)
    {
        $p = 0; $c = 0;
        if (isset($json['usage']['prompt_tokens']))     $p = intval($json['usage']['prompt_tokens']);
        if (isset($json['usage']['completion_tokens'])) $c = intval($json['usage']['completion_tokens']);
        if (!$p && !$c && isset($json['usage']['total_tokens'])) {
            $p = intval($json['usage']['total_tokens']);
        }
        return ['prompt_tokens' => $p, 'completion_tokens' => $c];
    }

    /** 由 base_url 推导 chat/completions 地址（已带则原样返回） */
    public static function chatUrl($baseUrl)
    {
        $u = rtrim(trim((string)$baseUrl), '/');
        if ($u === '') return '';
        if (preg_match('#/chat/completions$#i', $u)) return $u;
        return $u . '/chat/completions';
    }

    /** 默认模型：系统配置 → 首个已登记模型 → 首个渠道首个模型 */
    public static function defaultModel()
    {
        $m = trim((string)Setting::get('ai_model', ''));
        if ($m !== '') return $m;

        foreach (AiModel::enabledList() as $row) {
            return (string)$row->model_key;
        }
        foreach (AiChannel::enabledList() as $ch) {
            $list = $ch->modelList();
            if ($list) return $list[0];
        }
        return '';
    }

    private static function fail($msg)
    {
        return ['ok' => false, 'msg' => $msg, 'content' => '', 'model' => '', 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0], 'points' => 0];
    }
}
