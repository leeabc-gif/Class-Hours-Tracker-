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
     * 我的出勤记录
     * GET /api/studentportal/attendance?limit=20
     */
    public function attendance()
    {
        $this->requireLogin();
        $sid   = (int)$this->student->id;
        $limit = intval($this->request->param('limit', 20));

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