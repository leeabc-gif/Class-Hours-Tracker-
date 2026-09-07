<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\AiConversation;
use app\common\model\AiMessage;
use app\common\model\Setting;
use app\common\service\AiEngine;
use app\common\service\AiConfig;

/**
 * AI 智能分析接口
 *
 * 会话隔离：所有会话 / 消息查询都带 user_id 条件，
 * 教师之间看不到彼此的 AI 对话记录。
 *
 * 大模型接入点：AiEngine::callLLM()（app\common\service\AiEngine 末尾），
 * 后台配置 ai_api_url / ai_api_key 后即走真实模型，失败自动回落本地规则引擎。
 */
class Ai extends Base
{
    /**
     * 对话主入口
     */
    public function chat()
    {
        $this->requireLogin();
        if (!intval(Setting::get('ai_enabled', 1))) {
            return $this->fail('AI 模块已被管理员关闭');
        }
        $config = AiConfig::publicForUser($this->user->id);
        if (!intval($config['enabled'])) {
            return $this->fail('你已关闭个人 AI，请先在个人设置中开启');
        }

        $data = $this->jsonInput();
        $text = trim(isset($data['text']) ? $data['text'] : '');
        $intent = trim(isset($data['intent']) ? $data['intent'] : '');
        if ($text === '' && $intent === '') {
            return $this->fail('请输入内容');
        }

        try {
            $result = AiEngine::handle($this->user->id, $data);
        } catch (\Throwable $e) {
            // 不把模型、数据库或服务器内部细节暴露给浏览器
            return $this->fail('AI 处理失败，请稍后重试');
        }

        return $this->ok($result);
    }

    /**
     * AI 页面启动数据：会话列表 + 当前会话消息
     */
    public function bootstrap()
    {
        $this->requireLogin();
        $uid = $this->user->id;

        $conversations = [];
        foreach (AiConversation::listOf($uid) as $c) {
            $conversations[] = [
                'id'         => (int)$c->id,
                'title'      => $c->title,
                'updated_at' => (int)$c->updated_at,
            ];
        }

        $currentId = 0;
        $messages  = [];
        if (!empty($conversations)) {
            $currentId = $conversations[0]['id'];
            foreach (AiMessage::where('conversation_id', $currentId)->order('id asc')->select() as $m) {
                $messages[] = [
                    'id'      => (int)$m->id,
                    'role'    => $m->role,
                    'intent'  => $m->intent,
                    'content' => $m->content,
                    'payload' => $m->payloadArray(),
                    'time'    => date('H:i', (int)$m->created_at),
                ];
            }
        }

        return $this->ok([
            'conversations' => $conversations,
            'current_id'    => $currentId,
            'messages'      => $messages,
            'ai_enabled'    => intval(Setting::get('ai_enabled', 1)),
            'provider'      => Setting::get('ai_provider', 'local'),
            'config'        => AiConfig::publicForUser($uid),
        ]);
    }

    /**
     * 会话列表
     */
    public function conversations()
    {
        $this->requireLogin();
        $out = [];
        foreach (AiConversation::listOf($this->user->id) as $c) {
            $out[] = [
                'id'         => (int)$c->id,
                'title'      => $c->title,
                'updated_at' => (int)$c->updated_at,
            ];
        }
        return $this->ok($out);
    }

    /**
     * 指定会话的消息（带归属校验）
     */
    public function messages()
    {
        $this->requireLogin();
        $id   = intval($this->input('conversation_id', 0));
        $conv = AiConversation::where('id', $id)->where('user_id', $this->user->id)->find();
        if (!$conv) {
            return $this->fail('会话不存在或无权访问');
        }
        $out = [];
        foreach ($conv->messages() as $m) {
            $out[] = [
                'id'      => (int)$m->id,
                'role'    => $m->role,
                'intent'  => $m->intent,
                'content' => $m->content,
                'payload' => $m->payloadArray(),
                'time'    => date('H:i', (int)$m->created_at),
            ];
        }
        return $this->ok(['messages' => $out]);
    }

    /**
     * 新建会话
     */
    public function create()
    {
        $this->requireLogin();
        $conv = AiConversation::create([
            'user_id' => $this->user->id,
            'title'   => '新的对话',
        ]);
        return $this->ok(['id' => $conv->id], '已创建新对话');
    }

    /**
     * 清空会话消息（保留会话本身）
     */
    public function clear()
    {
        $this->requireLogin();
        $data = $this->jsonInput();
        $conv = $this->ownConversation(intval($data['conversation_id']));
        if (!$conv) {
            return $this->fail('会话不存在或无权访问');
        }
        $conv->clearMessages();
        return $this->ok(null, '已清空对话');
    }

    /**
     * 删除会话
     */
    public function delete()
    {
        $this->requireLogin();
        $data = $this->jsonInput();
        $conv = $this->ownConversation(intval($data['conversation_id']));
        if (!$conv) {
            return $this->fail('会话不存在或无权访问');
        }
        AiMessage::where('conversation_id', $conv->id)->delete();
        $conv->delete();
        return $this->ok(null, '已删除对话');
    }

    /**
     * 取当前用户自己的会话
     */
    private function ownConversation($id)
    {
        return AiConversation::where('id', $id)->where('user_id', $this->user->id)->find();
    }
}
