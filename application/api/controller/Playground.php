<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\AiChannel;
use app\common\model\AiModel;
use app\common\model\AiUsageLog;
use app\common\model\Setting;
use app\common\service\AiProxy;
use app\common\service\AiConfig;
use app\common\service\QuotaService;

/**
 * AI 操练场
 *
 * 与「AI 助手」的区别：
 *   - AI 助手：面向业务，走 AiEngine 的规则/意图解析，额度宽松校验
 *   - 操练场：面向模型本身，教师自由选模型、调参数、看 token 与点数，额度严格校验
 * 会话不落库（纯前端内存），避免与业务会话表 ks_ai_conversation 混在一起；
 * 但每一次调用都如实写入 ks_ai_usage_log，管理员用量统计里能看到。
 */
class Playground extends Base
{
    /** 单条消息最大字符数 */
    const MAX_CHARS = 8000;
    /** 一次请求最多携带的历史轮数（不含 system） */
    const MAX_MSGS  = 40;

    /**
     * 页面启动数据：可用模型 + 我的额度 + 预设提示词
     */
    public function bootstrap()
    {
        $this->requireLogin();
        $uid = (int)$this->user->id;

        return $this->ok([
            'models'     => self::availableModels(),
            'default_model' => AiProxy::defaultModel(),
            'quota'      => QuotaService::summary($uid),
            'presets'    => self::presets(),
            'limits'     => [
                'max_chars'   => self::MAX_CHARS,
                'max_msgs'    => self::MAX_MSGS,
                'temperature' => [0, 2],
                'max_tokens'  => [1, 8192],
            ],
            'has_channel' => self::hasAnyChannel(),
        ]);
    }

    /**
     * 发起一次对话
     *
     * 入参（JSON）：
     *   model        模型 key，缺省用系统默认
     *   system       系统提示词，可选
     *   messages     [['role'=>'user|assistant','content'=>'…'], …]
     *   temperature  0~2
     *   max_tokens   可选
     *
     * 返回：content / usage / points / latency_ms / model / quota
     */
    public function chat()
    {
        $this->requireLogin();
        $uid  = (int)$this->user->id;
        $data = $this->jsonInput();

        $model = trim((string)(isset($data['model']) ? $data['model'] : ''));
        if ($model === '') $model = AiProxy::defaultModel();
        if ($model === '') {
            return $this->fail('系统尚未配置可用模型，请联系管理员先在「AI 中转 → 渠道」中添加');
        }
        if (!self::modelAllowed($model)) {
            return $this->fail('模型「' . $model . '」当前不可用，请换一个');
        }

        $messages = self::normalizeMessages($data);
        if (empty($messages)) {
            return $this->fail('请输入内容');
        }

        $temperature = isset($data['temperature']) ? floatval($data['temperature']) : 0.7;
        if ($temperature < 0) $temperature = 0;
        if ($temperature > 2) $temperature = 2;

        $maxTokens = intval(isset($data['max_tokens']) ? $data['max_tokens'] : 0);
        if ($maxTokens < 0) $maxTokens = 0;
        if ($maxTokens > 8192) $maxTokens = 8192;

        $opt = [
            'teacher_id'  => $uid,
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'source'      => 'playground',   // 严格额度校验
            'ip'          => (string)$this->request->ip(),
        ];
        if ($maxTokens > 0) $opt['max_tokens'] = $maxTokens;

        try {
            $res = AiProxy::chat($opt);
        } catch (\Throwable $e) {
            return $this->fail('AI 调用异常：' . $e->getMessage());
        }

        if (!$res['ok']) {
            // 额度类错误把余额一并带回去，前端好提示
            return $this->fail($res['msg'], 1, isset($res['quota']) ? ['quota' => $res['quota']] : null);
        }

        return $this->ok([
            'content'    => $res['content'],
            'model'      => $res['model'],
            'usage'      => $res['usage'],
            'points'     => $res['points'],
            'latency_ms' => isset($res['latency_ms']) ? (int)$res['latency_ms'] : 0,
            'quota'      => isset($res['quota']) ? $res['quota'] : QuotaService::summary($uid),
        ]);
    }

    /** 我的最近调用记录（操练场 + 外部 API），用于右下角对照 */
    public function history()
    {
        $this->requireLogin();
        $uid = (int)$this->user->id;

        $rows = AiUsageLog::where('teacher_id', $uid)
            ->order('id desc')
            ->limit(20)
            ->select();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'        => (int)$r->id,
                'model'     => (string)$r->model,
                'points'    => (float)$r->points,
                'prompt'    => (int)$r->prompt_tokens,
                'completion'=> (int)$r->completion_tokens,
                'latency_ms'=> (int)$r->latency_ms,
                'status'    => (int)$r->status,
                'source'    => (string)$r->source,
                'created_at'=> (int)$r->created_at,
            ];
        }
        return $this->ok(['list' => $out]);
    }

    /** 刷新额度（对话后前端可直接调这个同步顶部余额） */
    public function quota()
    {
        $this->requireLogin();
        return $this->ok(['quota' => QuotaService::summary((int)$this->user->id)]);
    }

    // ----------------------------------------------------------------
    // 内部方法
    // ----------------------------------------------------------------

    /**
     * 可用模型：以「已登记且启用的模型」为主体，
     * 再并入渠道声明的模型（渠道可能是新加的、还没登记倍率）。
     * 若一个渠道都没配，且旧版单条配置可用，则给出旧配置的模型，保证不空白。
     */
    private static function availableModels()
    {
        $out   = [];
        $seen  = [];

        foreach (AiModel::enabledList() as $m) {
            $key = (string)$m->model_key;
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = [
                'model_key'        => $key,
                'display_name'     => (string)$m->display_name !== '' ? (string)$m->display_name : $key,
                'prompt_ratio'     => (float)$m->prompt_ratio,
                'completion_ratio' => (float)$m->completion_ratio,
                'runnable'         => self::hasChannelFor($key),
            ];
        }

        foreach (AiChannel::enabledList() as $ch) {
            foreach ($ch->modelList() as $key) {
                if ($key === '' || isset($seen[$key])) continue;
                $seen[$key] = true;
                $r = AiModel::ratioOf($key);
                $out[] = [
                    'model_key'        => $key,
                    'display_name'     => $key,
                    'prompt_ratio'     => $r['prompt'],
                    'completion_ratio' => $r['completion'],
                    'runnable'         => true,
                ];
            }
        }

        // 兼容旧版单条配置（ks_setting.ai_*）
        if (!$out) {
            $m = trim((string)Setting::get('ai_model', ''));
            if ($m !== '') {
                $r = AiModel::ratioOf($m);
                $out[] = [
                    'model_key'        => $m,
                    'display_name'     => $m,
                    'prompt_ratio'     => $r['prompt'],
                    'completion_ratio' => $r['completion'],
                    'runnable'         => self::legacyConfigured(),
                ];
            }
        }

        usort($out, function ($a, $b) {
            return strcmp($a['model_key'], $b['model_key']);
        });
        return $out;
    }

    /** 是否配置了任何渠道（没有则走旧版回退） */
    private static function hasAnyChannel()
    {
        foreach (AiChannel::enabledList() as $ch) {
            return true;
        }
        return false;
    }

    /** 该模型是否至少有一个启用渠道可转发（无渠道时看旧配置） */
    private static function modelAllowed($modelKey)
    {
        $any = false;
        foreach (AiChannel::enabledList() as $ch) {
            $any = true;
            if ($ch->allowsModel($modelKey)) return true;
        }
        if (!$any) {
            return self::legacyConfigured();
        }
        return false;
    }

    private static function hasChannelFor($modelKey)
    {
        return self::modelAllowed($modelKey);
    }

    /** 旧版单条系统配置是否可用（ks_setting.ai_api_url / ai_api_key / ai_model） */
    private static function legacyConfigured()
    {
        return AiConfig::isConfigured([
            'enabled' => intval(Setting::get('ai_enabled', 1)) === 1,
            'url'     => (string)Setting::get('ai_api_url', ''),
            'key'     => (string)Setting::get('ai_api_key', ''),
            'model'   => (string)Setting::get('ai_model', ''),
        ]);
    }

    /**
     * 清洗消息：只保留 user/assistant，空内容丢弃，超长截断，超轮数只保留最近若干条
     * system 单独拼到数组头部（OpenAI 兼容格式）
     */
    private static function normalizeMessages(array $data)
    {
        $raw = isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : [];
        $list = [];
        foreach ($raw as $m) {
            if (!is_array($m)) continue;
            $role = strtolower(trim((string)(isset($m['role']) ? $m['role'] : '')));
            if (!in_array($role, ['user', 'assistant'], true)) continue;
            $content = trim((string)(isset($m['content']) ? $m['content'] : ''));
            if ($content === '') continue;
            if (mb_strlen($content) > self::MAX_CHARS) {
                $content = mb_substr($content, 0, self::MAX_CHARS);
            }
            $list[] = ['role' => $role, 'content' => $content];
        }

        if (count($list) > self::MAX_MSGS) {
            $list = array_slice($list, -self::MAX_MSGS);
        }
        if (!$list) return [];

        $system = trim((string)(isset($data['system']) ? $data['system'] : ''));
        if ($system !== '') {
            if (mb_strlen($system) > self::MAX_CHARS) $system = mb_substr($system, 0, self::MAX_CHARS);
            array_unshift($list, ['role' => 'system', 'content' => $system]);
        }
        return $list;
    }

    /** 预设提示词：贴合中职/高职教师日常场景 */
    private static function presets()
    {
        return [
            ['t' => '通用助手', 's' => '你是一位耐心、专业的助手，回答简洁清晰。'],
            ['t' => '教案设计', 's' => '你是一位资深职业教育专业课教师。请围绕我给出的课题，输出一份 45 分钟课堂教案，要求包含：教学目标（知识/能力/素养）、重难点、教学过程（导入-新授-练习-小结-作业，标注时间分配）、板书设计。语言务实，可直接拿去上课。'],
            ['t' => '试题生成', 's' => '你是一位命题专家。请根据我给出的知识点与难度，生成一套试题。要求：单选 10 题、判断 5 题、简答 2 题；每题附答案与解析；知识点覆盖均匀，表述严谨无歧义。'],
            ['t' => '学情分析', 's' => '你是一位教学诊断专家。请根据我提供的学生表现数据或描述，分析学习困难成因，并给出分层教学建议与可执行的干预措施。'],
            ['t' => '代码讲解', 's' => '你是一位编程教师。讲解代码时请：先一句话说明整体作用，再逐段解释关键行，最后给出常见错误与改进建议。示例代码要能直接运行。'],
            ['t' => '公文通知', 's' => '你是一位学校行政人员。请把我的要点改写成规范的通知/公告，要求：标题简明、主送明确、正文分条、落款与日期齐全、语气正式得体。'],
            ['t' => '课堂导入', 's' => '你是一位擅长调动气氛的教师。请为我的课题设计 3 个课堂导入方案：一个生活案例、一个悬念问题、一个短视频/实物演示，每个不超过 100 字。'],
        ];
    }
}
