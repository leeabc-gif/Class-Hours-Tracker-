<?php
namespace app\api\controller;

use app\common\model\Lesson;
use app\common\model\StudentAttendance;
use app\common\model\StudentScore;
use app\common\model\Student;
use app\common\model\StudentAiConversation;
use app\common\model\StudentAiMessage;
use app\common\service\AiProxy;
use app\common\service\StudentQuotaService;

/**
 * 学生端门户 API（移动端学生入口的后台）
 *
 * 路由：/api/studentportal/xxx 自动映射到此控制器
 */
class Studentportal extends StudentBase
{
    /**
     * 页面启动数据
     * GET /api/studentportal/bootstrap
     */
    public function bootstrap()
    {
        $this->requireLogin();
        $stu = $this->student;

        $data = [
            'student' => $stu->toSafeArray(),
            'class_name' => $stu->className(),
        ];

        return $this->ok($data);
    }

    /**
     * 个人档案完整信息
     * GET /api/studentportal/profile
     */
    public function profile()
    {
        $this->requireLogin();
        return $this->ok(['student' => $this->student->toSafeArray()]);
    }

    /**
     * 我的 AI 额度
     * GET /api/studentportal/quota
     */
    public function quota()
    {
        $this->requireLogin();
        return $this->ok(StudentQuotaService::summary((int)$this->student->id));
    }

    /**
     * 我的出勤记录
     * GET /api/studentportal/attendance?limit=20
     */
    public function attendance()
    {
        $this->requireLogin();
        $sid   = (int)$this->student->id;
        $limit = max(1, min(100, intval($this->request->param('limit', 20))));

        $rows = StudentAttendance::where('student_id', $sid)
            ->order('id desc')
            ->limit($limit)
            ->select();

        $out = [];
        foreach ($rows as $r) {
            $lesson = Lesson::get((int)$r->lesson_id);
            $out[] = [
                'id'         => (int)$r->id,
                'lesson_id'  => (int)$r->lesson_id,
                'teach_date' => $lesson ? $lesson->teach_date : null,
                'course_name'=> $lesson ? $lesson->course_name : '',
                'status'     => (string)$r->status,
                'note'       => (string)$r->note,
                'created_at' => (int)$r->getData('created_at'),
            ];
        }
        return $this->ok(['list' => $out]);
    }

    /**
     * 我的成绩与评价
     * GET /api/studentportal/scores
     */
    public function scores()
    {
        $this->requireLogin();
        $sid = (int)$this->student->id;

        $rows = StudentScore::where('student_id', $sid)
            ->order('id desc')
            ->select();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'          => (int)$r->id,
                'course_name' => (string)$r->course_name,
                'title'       => (string)$r->title,
                'score'       => $r->score !== null ? (float)$r->score : null,
                'grade'       => (string)$r->grade,
                'comment'     => (string)$r->comment,
                'created_at'  => (int)$r->getData('created_at'),
            ];
        }
        return $this->ok(['list' => $out]);
    }

    /**
     * AI 答疑（非流式）
     * POST /api/studentportal/aiChat
     * {"conversation_id":0,"message":"哪道题不会?"}
     *
     * 支持继续已有会话（传 conversation_id）或新会话（conversation_id=0）。
     * 记录消息到 ks_student_ai_conversation + ks_student_ai_message 供审计/学情画像。
     */
    public function aiChat()
    {
        $this->requireLogin();
        $sid = (int)$this->student->id;
        $data = $this->jsonInput();

        $conversationId = intval(isset($data['conversation_id']) ? $data['conversation_id'] : 0);
        $message = trim(isset($data['message']) ? $data['message'] : '');

        if ($message === '') {
            return $this->fail('请输入问题');
        }
        if (mb_strlen($message) > 4000) {
            return $this->fail('问题不能超过 4000 个字符');
        }

        // 检查会话归属
        if ($conversationId > 0) {
            $conv = StudentAiConversation::get($conversationId);
            if (!$conv || (int)$conv->student_id !== $sid) {
                return $this->fail('会话不存在或无权访问');
            }
        }

        // 获取最近 20 条历史消息构建上下文
        $messages = [];
        if ($conversationId > 0) {
            $history = StudentAiMessage::where('conversation_id', $conversationId)
                ->order('id asc')
                ->limit(20)
                ->select();
            foreach ($history as $m) {
                $messages[] = ['role' => (string)$m->role, 'content' => (string)$m->content];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        // 通过 AiProxy 调用，使用学生主体
        try {
            $res = AiProxy::chat([
                'teacher_id'  => 0,
                'owner_type'  => 'student',
                'student_id'  => $sid,
                'model'       => '',
                'messages'    => $messages,
                'temperature' => 0.7,
                'source'      => 'student',
                'ip'          => (string)$this->request->ip(),
            ]);
        } catch (\Throwable $e) {
            return $this->fail('AI 调用异常：' . $e->getMessage());
        }

        if (!$res['ok']) {
            return $this->fail($res['msg'], 1, isset($res['quota']) ? ['quota' => $res['quota']] : null);
        }

        $reply = (string)$res['content'];
        $points = (float)$res['points'];
        $model = (string)$res['model'];

        // 保存会话
        if ($conversationId <= 0) {
            $conv = StudentAiConversation::create([
                'student_id' => $sid,
                'title'      => mb_substr($message, 0, 50),
            ]);
            $conversationId = (int)$conv->id;
        } else {
            $conv->updated_at = time();
            $conv->save();
        }

        // 保存消息
        StudentAiMessage::create([
            'conversation_id' => $conversationId,
            'student_id'      => $sid,
            'role'            => 'user',
            'content'         => $message,
            'model'           => '',
            'points'          => 0,
        ]);
        StudentAiMessage::create([
            'conversation_id' => $conversationId,
            'student_id'      => $sid,
            'role'            => 'assistant',
            'content'         => $reply,
            'model'           => $model,
            'points'          => $points,
        ]);

        return $this->ok([
            'reply'           => $reply,
            'conversation_id' => $conversationId,
            'model'           => $model,
            'points'          => $points,
            'usage'           => $res['usage'],
            'quota'           => isset($res['quota']) ? $res['quota'] : StudentQuotaService::summary($sid),
        ]);
    }

    /**
     * AI 答疑（流式 SSE）
     * POST /api/studentportal/aiChatStream
     * {"conversation_id":0,"message":"哪道题不会?"}
     * 输出 text/event-stream，与 Playground 兼容的结构：
     *   data: {"choices":[{"delta":{"content":"..."}}]}\n\n
     *   data: {"done":true,"conversation_id":123,"points":0.5}\n\n
     */
    public function aiChatStream()
    {
        $this->requireLogin();
        $sid = (int)$this->student->id;
        $data = $this->jsonInput();

        $conversationId = intval(isset($data['conversation_id']) ? $data['conversation_id'] : 0);
        $message = trim(isset($data['message']) ? $data['message'] : '');

        if ($message === '') {
            $this->sseError('请输入问题');
            return;
        }
        if (mb_strlen($message) > 4000) {
            $this->sseError('问题不能超过 4000 个字符');
            return;
        }

        // 检查会话归属
        if ($conversationId > 0) {
            $conv = StudentAiConversation::get($conversationId);
            if (!$conv || (int)$conv->student_id !== $sid) {
                $this->sseError('会话不存在或无权访问');
                return;
            }
        }

        // 获取最近 20 条历史消息构建上下文
        $messages = [];
        if ($conversationId > 0) {
            $history = StudentAiMessage::where('conversation_id', $conversationId)
                ->order('id asc')
                ->limit(20)
                ->select();
            foreach ($history as $m) {
                $messages[] = ['role' => (string)$m->role, 'content' => (string)$m->content];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        // SSE 准备
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ob_implicit_flush(true);
        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');
        @header('Content-Type: text/event-stream; charset=utf-8');
        @header('Cache-Control: no-cache, no-transform');
        @header('X-Accel-Buffering: no');
        @http_response_code(200);

        $respId = 'stu-' . bin2hex(random_bytes(8));
        $created = time();
        $sseWrite = function ($payload) {
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            @ob_flush(); @flush();
        };

        // 首包
        $sseWrite([
            'id' => $respId, 'object' => 'stu.chunk', 'created' => $created,
            'choices' => [['index' => 0, 'delta' => ['role' => 'assistant'], 'finish_reason' => null]],
        ]);

        $opt = [
            'teacher_id'  => 0,
            'owner_type'  => 'student',
            'student_id'  => $sid,
            'model'       => '',
            'messages'    => $messages,
            'temperature' => 0.7,
            'source'      => 'student',
            'ip'          => (string)$this->request->ip(),
        ];

        $fullReply = '';
        $usedModel = '';
        $usedPoints = 0.0;

        try {
            $res = AiProxy::streamChat($opt, function ($delta) use ($sseWrite, $respId, $created, &$fullReply, &$usedModel, &$usedPoints) {
            if (strpos($delta, '__SWITCH__') === 0) {
                echo ": switch-channel " . substr($delta, 10) . "\n\n";
                @ob_flush(); @flush();
                return;
            }
            if (strpos($delta, '__USAGE__') === 0) {
                // __USAGE__model|points
                $parts = explode('|', substr($delta, 9));
                $usedModel  = isset($parts[0]) ? $parts[0] : '';
                $usedPoints = isset($parts[1]) ? (float)$parts[1] : 0.0;
                return;
            }
            $fullReply .= $delta;
            $sseWrite([
                'id' => $respId, 'object' => 'stu.chunk', 'created' => $created,
                'choices' => [['index' => 0, 'delta' => ['content' => $delta], 'finish_reason' => null]],
            ]);
        });

        if (!is_array($res) || empty($res['ok'])) {
            $errorMsg = is_array($res) && !empty($res['msg']) ? $res['msg'] : 'AI 调用失败';
            $sseWrite(['error' => $errorMsg]);
            echo "data: [DONE]\n\n";
            @ob_flush(); @flush();
            return;
        }

        $usedModel = isset($res['model']) ? (string)$res['model'] : $usedModel;
        $usedPoints = isset($res['points']) && is_finite((float)$res['points']) ? (float)$res['points'] : $usedPoints;

        // 流结束后保存对话
        if ($fullReply !== '') {
            if ($conversationId <= 0) {
                $conv = StudentAiConversation::create([
                    'student_id' => $sid,
                    'title'      => mb_substr($message, 0, 50),
                ]);
                $conversationId = (int)$conv->id;
            } else {
                $conv->updated_at = time();
                $conv->save();
            }

            StudentAiMessage::create([
                'conversation_id' => $conversationId,
                'student_id'      => $sid,
                'role'            => 'user',
                'content'         => $message,
                'model'           => '',
                'points'          => 0,
            ]);
            StudentAiMessage::create([
                'conversation_id' => $conversationId,
                'student_id'      => $sid,
                'role'            => 'assistant',
                'content'         => $fullReply,
                'model'           => $usedModel,
                'points'          => $usedPoints,
            ]);
        }

        // 结束信号
        $sseWrite([
            'done' => true,
            'conversation_id' => $conversationId,
            'model' => $usedModel,
            'points' => $usedPoints,
            'quota' => StudentQuotaService::summary($sid),
        ]);

        // 最后发 [DONE]（兼容 OpenAI 格式）
        echo "data: [DONE]\n\n";
        @ob_flush(); @flush();
        } catch (\Throwable $e) {
            $this->sseError('AI 流式调用异常，请稍后重试');
            return;
        }
    }

    /**
     * SSE 错误消息
     */
    private function sseError($msg)
    {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ob_implicit_flush(true);
        @ini_set('output_buffering', '0');
        @header('Content-Type: text/event-stream; charset=utf-8');
        @header('Cache-Control: no-cache');
        @http_response_code(200);
        echo 'data: ' . json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";
        @ob_flush(); @flush();
    }

    /**
     * 我的 AI 会话列表
     * GET /api/studentportal/aiConversations
     */
    public function aiConversations()
    {
        $this->requireLogin();
        $sid = (int)$this->student->id;

        $rows = StudentAiConversation::where('student_id', $sid)
            ->order('updated_at desc')
            ->limit(30)
            ->select();

        $out = [];
        foreach ($rows as $c) {
            $out[] = [
                'id'    => (int)$c->id,
                'title' => (string)$c->title,
                'updated_at' => (int)$c->getData('updated_at'),
                'msg_count' => StudentAiMessage::where('conversation_id', (int)$c->id)->count(),
            ];
        }
        return $this->ok(['list' => $out]);
    }

    /**
     * 我的 AI 会话详情（消息历史）
     * GET /api/studentportal/aiMessages?conversation_id=1
     */
    public function aiMessages()
    {
        $this->requireLogin();
        $sid = (int)$this->student->id;
        $convId = intval($this->request->param('conversation_id', 0));

        $conv = StudentAiConversation::get($convId);
        if (!$conv || (int)$conv->student_id !== $sid) {
            return $this->fail('会话不存在');
        }

        $rows = StudentAiMessage::where('conversation_id', $convId)
            ->order('id asc')
            ->select();

        $out = [];
        foreach ($rows as $m) {
            $out[] = [
                'role'    => (string)$m->role,
                'content' => (string)$m->content,
                'model'   => (string)$m->model,
                'points'  => (float)$m->points,
                'created_at' => (int)$m->getData('created_at'),
            ];
        }
        return $this->ok(['list' => $out, 'conversation' => [
            'id'    => (int)$conv->id,
            'title' => (string)$conv->title,
        ]]);
    }
}