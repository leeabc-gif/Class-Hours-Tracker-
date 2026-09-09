<?php
namespace app\common\service;

use app\common\model\Lesson;
use app\common\model\Course;
use app\common\model\Term;
use app\common\model\Setting;
use app\common\model\Teacher;
use app\common\model\AiConversation;
use app\common\model\AiMessage;
use app\common\service\AiConfig;

/**
 * AI 智能分析服务
 *
 * 设计要点：
 * 1. 所有查询都带 user_id 条件，教师永远只能读到自己的数据（多用户隔离）。
 * 2. callLLM() 是真实大模型的接入点，未配置或调用失败时返回 null，
 *    系统自动回落本地规则引擎，因此接入过程不会影响现有功能。
 */
class AiEngine
{
    /**
     * 节次区间 → section 值
     * 键是 "起始节-结束节" 字符串，值是 Lesson::SECTIONS 中的索引。
     * 同步 SECTIONS 新增的 1-4节/2-4节/5-8节/1-8节。
     */
    const SECTION_MAP = [
        '1-2'  => 1,
        '3-4'  => 2,
        '5-6'  => 3,
        '7-8'  => 4,
        '9-10' => 5,
        '11-12'=> 6,
        '1-4'  => 7,
        '2-4'  => 8,
        '5-8'  => 9,
        '1-8'  => 10,
    ];

    /** 节次起始节 → section 值（兼容旧版只填起始节的文本，如 "1-2节"） */
    const SECTION_START_MAP = [1 => 1, 3 => 2, 5 => 3, 7 => 4, 9 => 5, 11 => 6];

    /** 中文星期 → 数字 */
    const WEEKDAY_MAP = [
        '一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6,
        '日' => 7, '天' => 7, '1' => 1, '2' => 2, '3' => 3, '4' => 4,
        '5' => 5, '6' => 6, '7' => 7,
    ];

    /** 类型关键词 */
    const TYPE_MAP = [
        '实训课' => 'training', '实训' => 'training',
        '补课'   => 'makeup',
        '调课'   => 'swap',
        '常规课' => 'normal', '常规' => 'normal',
    ];

    // ============================================================
    // 对外主入口
    // ============================================================

    /**
     * 处理一次 AI 请求
     * @param int   $userId  当前用户ID（数据隔离的唯一依据）
     * @param array $input   ['intent'=>..,'text'=>..,'conversation_id'=>..,'payload'=>..]
     * @return array 统一响应
     */
    public static function handle($userId, $input)
    {
        $text   = trim(isset($input['text']) ? $input['text'] : '');
        $intent = isset($input['intent']) && $input['intent'] !== ''
            ? $input['intent']
            : self::guessIntent($text);

        // 会话归属校验：只能操作自己的会话
        $conv = null;
        if (!empty($input['conversation_id'])) {
            $conv = AiConversation::where('id', intval($input['conversation_id']))
                ->where('user_id', $userId)
                ->find();
        }
        if (!$conv) {
            $conv = AiConversation::current($userId);
        }

        // 保存用户消息
        AiMessage::add($conv->id, $userId, 'user', $text, $intent);

        $result = null;
        switch ($intent) {
            case 'parse':
                $result = self::parseSchedule($userId, $text, $input);
                break;
            case 'parse_confirm':
                $result = self::confirmParse($userId, $input);
                break;
            case 'evaluate':
                $result = self::evaluate($userId, $input);
                break;
            case 'qa':
                $result = self::qa($userId, $text, $input);
                break;
            case 'proof':
                $result = self::proof($userId, $input);
                break;
            default:
                $result = self::chat($userId, $text);
                break;
        }

        if (!isset($result['engine'])) $result['engine'] = 'local';
        if ($intent !== 'parse' && $intent !== 'parse_confirm') {
            $llmReply = self::callLLMForIntent($userId, $intent, $text, $result);
            if ($llmReply !== null && $llmReply !== '') {
                $result['reply'] = $llmReply;
                $result['engine'] = 'llm';
                $result['model_source'] = AiConfig::effective($userId)['source'];
            }
        }

        // 保存助手回复
        $assistantMsg = AiMessage::add(
            $conv->id, $userId, 'assistant',
            isset($result['reply']) ? $result['reply'] : '',
            $intent,
            isset($result) ? $result : null
        );

        // 会话标题取首条用户消息
        if ($conv->title === '新的对话' && $text !== '') {
            $conv->title = mb_substr($text, 0, 20, 'UTF-8');
        }
        $conv->updated_at = time();
        $conv->save();

        $result['conversation_id'] = $conv->id;
        $result['intent']          = $intent;
        $result['message_id']      = $assistantMsg->id;
        return $result;
    }

    /**
     * 根据文本猜测意图（本地规则；接入大模型后可完全交给模型）
     */
    public static function guessIntent($text)
    {
        if ($text === '') return 'chat';

        // 课表文本：出现「周」+「节」基本可判定为排课文本
        if (preg_match('/\d+\s*[-~－—至]\s*\d+\s*周/u', $text) && preg_match('/\d+\s*[-~－—]\s*\d+\s*节/u', $text)) {
            return 'parse';
        }
        if (preg_match('/周[一二三四五六日天]/u', $text) && preg_match('/\d+\s*[-~－—]\s*\d+\s*节/u', $text)) {
            return 'parse';
        }
        if (preg_match('/(工作量|饱和度|负荷|评估|分析|说明文案)/u', $text)) {
            return 'evaluate';
        }
        if (preg_match('/(证明|证明信|证明材|开具)/u', $text)) {
            return 'proof';
        }
        if (preg_match('/(多少|几节|查询|统计|峰值|哪些|什么时候|课酬|课时费)/u', $text)) {
            return 'qa';
        }
        return 'chat';
    }

    // ============================================================
    // 1. 课表文本识别 → 批量生成课时记录
    // ============================================================

    /**
     * 解析课表文本，返回预览表（不落库）
     */
    public static function parseSchedule($userId, $text, $input = [])
    {
        $term = Term::current();
        if (!$term) {
            return ['reply' => '系统尚未配置学期，请先联系管理员创建学期。', 'rows' => []];
        }

        $lines  = preg_split('/[\r\n]+/', $text);
        $rows   = [];
        $errors = [];
        $seq    = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // 去掉行首序号 1. 1) (1)
            $line = preg_replace('/^[\(（]?\d+[\)）\.、]\s*/u', '', $line);

            $parsed = self::parseLine($line);
            if ($parsed === null) {
                $errors[] = $line;
                continue;
            }

            // 课程未在库时，价格用全局参考价；已存在则用库中单价
            $course = self::matchCourse($userId, $parsed['course_name']);
            if ($parsed['price'] === null) {
                $parsed['price'] = $course
                    ? (float)$course->price
                    : (float)Setting::get('global_price', 60);
            }

            foreach ($parsed['weeks'] as $w) {
                $seq++;
                $dup = Lesson::findDup($userId, $term->id, $w, $parsed['weekday'], $parsed['section']);
                $rows[] = [
                    'seq'         => $seq,
                    'course_name' => $parsed['course_name'],
                    'classes'     => $parsed['classes'],
                    'week'        => $w,
                    'weekday'     => $parsed['weekday'],
                    'weekday_text' => Lesson::WEEKDAYS[$parsed['weekday']],
                    'section'     => $parsed['section'],
                    'section_text' => Lesson::SECTIONS[$parsed['section']],
                    'teach_date'  => Term::dateOf($term, $w, $parsed['weekday']),
                    'periods'     => $parsed['periods'],
                    'price'       => $parsed['price'],
                    'amount'      => round($parsed['periods'] * $parsed['price'], 2),
                    'type'        => $parsed['type'],
                    'type_text'   => Lesson::TYPES[$parsed['type']],
                    'remark'      => $parsed['remark'],
                    'conflict'    => $dup ? true : false,
                    'conflict_id' => $dup ? $dup->id : 0,
                ];
            }
        }

        if (empty($rows)) {
            $reply = "没能从这段文字里识别出课表信息。\n\n支持格式示例：\n工业机器人导论 机器人2301班 1-16周 周一 1-2节\nPLC应用技术 电气2301班 第1-8周 周三 3-4节 补课 55元";
            if ($errors) {
                $reply .= "\n\n未识别的 " . count($errors) . " 行：\n· " . implode("\n· ", array_slice($errors, 0, 5));
            }
            return ['reply' => $reply, 'rows' => [], 'errors' => $errors];
        }

        $okCount   = count(array_filter($rows, function ($r) { return !$r['conflict']; }));
        $confCount = count($rows) - $okCount;

        $reply = "已识别 " . count($rows) . " 条课时，可导入 {$okCount} 条";
        if ($confCount > 0) {
            $reply .= "，{$confCount} 条与已有记录时间冲突（同一周+星期+节次），将自动跳过";
        }
        $reply .= "。请确认下表后点击「确认导入」。";
        if ($errors) {
            $reply .= "\n\n另有 " . count($errors) . " 行未识别：\n· " . implode("\n· ", array_slice($errors, 0, 5));
        }

        return [
            'reply'   => $reply,
            'rows'    => $rows,
            'errors'  => $errors,
            'term_id' => $term->id,
        ];
    }

    /**
     * 解析单行课表文本
     * @return array|null
     */
    public static function parseLine($line)
    {
        $orig = $line;

        // --- 节次：1-2节 / 1-4节 / 5-8节 / 1-8节 ...
        // 优先按 "起-止" 区间整体匹配（支持跨节组合），找不到再退回"起始节"映射。
        $section = null; $periods = 2;
        if (preg_match('/(\d{1,2})\s*[-~－—]\s*(\d{1,2})\s*节/u', $line, $m)) {
            $start = intval($m[1]);
            $end   = intval($m[2]);
            $key   = $start . '-' . $end;
            if (isset(self::SECTION_MAP[$key])) {
                $section = self::SECTION_MAP[$key];
                $periods = $end - $start + 1;
            } elseif (isset(self::SECTION_START_MAP[$start])) {
                // 兜底：识别到常见起始节，按传统 1-2 节制记录；periods 仍按实际区间算
                $section = self::SECTION_START_MAP[$start];
                $periods = max(1, $end - $start + 1);
            }
            $line = str_replace($m[0], ' ', $line);
        }
        if ($section === null) return null;

        // --- 星期：周一 / 星期三 / 周1 ---
        $weekday = null;
        if (preg_match('/(?:周|星期)\s*([一二三四五六日天1-7])/u', $line, $m)) {
            $key = $m[1];
            $weekday = isset(self::WEEKDAY_MAP[$key]) ? self::WEEKDAY_MAP[$key] : null;
            $line = str_replace($m[0], ' ', $line);
        }
        if ($weekday === null) return null;

        // --- 周次：1-16周 / 第2-18周 / 5,7,9周 ---
        $weeks = [];
        if (preg_match('/(?:第)?\s*(\d{1,2})\s*[-~－—至]\s*(\d{1,2})\s*周/u', $line, $m)) {
            $s = intval($m[1]); $e = intval($m[2]);
            if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }
            for ($i = $s; $i <= $e; $i++) $weeks[] = $i;
            $line = str_replace($m[0], ' ', $line);
        } elseif (preg_match('/((?:\d{1,2}\s*[,，、]\s*){0,}\d{1,2})\s*周/u', $line, $m)) {
            $parts = preg_split('/[,，、\s]+/u', trim($m[1]));
            foreach ($parts as $p) {
                if (is_numeric($p)) $weeks[] = intval($p);
            }
            $line = str_replace($m[0], ' ', $line);
        }
        if (empty($weeks)) return null;

        // --- 单价：60元 / 单价60 ---
        $price = null;
        if (preg_match('/(?:单价)?\s*(\d+(?:\.\d+)?)\s*元/u', $line, $m)) {
            $price = floatval($m[1]);
            $line  = str_replace($m[0], ' ', $line);
        }

        // --- 上课类型 ---
        $type = 'normal';
        foreach (self::TYPE_MAP as $kw => $code) {
            if (mb_strpos($line, $kw, 0, 'UTF-8') !== false) {
                $type = $code;
                $line = str_replace($kw, ' ', $line);
                break;
            }
        }

        // --- 班级：含「班」字的词 ---
        $classes = '';
        if (preg_match_all('/[^\s,，、]+?班(?=[,，、\s]|$)/u', $line, $m)) {
            $classes = implode(',', array_unique($m[0]));
            foreach ($m[0] as $c) {
                $line = str_replace($c, ' ', $line);
            }
        }

        // --- 课程名：剩余内容里的第一段连续非空字符 ---
        $line  = trim(preg_replace('/\s+/u', ' ', $line));
        $parts = preg_split('/[\s,，、]+/u', $line);
        $courseName = '';
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            // 剔除纯数字残留
            if (preg_match('/^\d+$/u', $p)) continue;
            $courseName = $p;
            break;
        }
        if ($courseName === '') return null;

        return [
            'course_name' => $courseName,
            'classes'     => $classes,
            'weeks'       => $weeks,
            'weekday'     => $weekday,
            'section'     => $section,
            'periods'     => $periods,
            'price'       => $price,
            'type'        => $type,
            'remark'      => 'AI解析：' . $orig,
        ];
    }

    /**
     * 按课程名匹配可用课程（公共课程 + 本人私有课程）
     */
    public static function matchCourse($userId, $name)
    {
        if ($name === '') return null;
        $c = Course::where('name', $name)
            ->where('status', 1)
            ->where(function ($q) use ($userId) {
                $q->where('teacher_id', 0)->whereOr('teacher_id', $userId);
            })
            ->find();
        if ($c) return $c;
        // 模糊匹配
        return Course::where('name', 'like', '%' . $name . '%')
            ->where('status', 1)
            ->where(function ($q) use ($userId) {
                $q->where('teacher_id', 0)->whereOr('teacher_id', $userId);
            })
            ->find();
    }

    /**
     * 确认导入（把预览行写入数据库）
     */
    public static function confirmParse($userId, $input)
    {
        $rows = isset($input['payload']['rows']) ? $input['payload']['rows'] : [];
        if (!is_array($rows) || empty($rows)) {
            return ['reply' => '没有可导入的数据。', 'imported' => 0];
        }
        $term    = Term::current();
        $created = 0;
        $skipped = 0;

        foreach ($rows as $r) {
            if (!empty($r['conflict'])) { $skipped++; continue; }
            $termId = !empty($r['term_id']) ? intval($r['term_id']) : ($term ? $term->id : 0);
            // 入库前再查一次，防止预览后到确认前被他人抢占
            if (Lesson::findDup($userId, $termId, intval($r['week']), intval($r['weekday']), intval($r['section']))) {
                $skipped++;
                continue;
            }
            $course = self::matchCourse($userId, $r['course_name']);
            Lesson::create([
                'teacher_id'  => $userId,
                'term_id'     => $termId,
                'course_id'   => $course ? $course->id : 0,
                'course_name' => $r['course_name'],
                'classes'     => isset($r['classes']) ? $r['classes'] : '',
                'week'        => intval($r['week']),
                'weekday'     => intval($r['weekday']),
                'section'     => intval($r['section']),
                'teach_date'  => !empty($r['teach_date']) ? $r['teach_date'] : null,
                'periods'     => floatval($r['periods']),
                'price'       => floatval($r['price']),
                'amount'      => round(floatval($r['periods']) * floatval($r['price']), 2),
                'type'        => isset($r['type']) ? $r['type'] : 'normal',
                'remark'      => isset($r['remark']) ? $r['remark'] : '',
                'source'      => 'ai',
                'deleted_at'  => 0,
            ]);
            $created++;
        }

        $reply = "导入完成：成功 {$created} 条";
        if ($skipped > 0) $reply .= "，跳过 {$skipped} 条（时间冲突）";
        $reply .= "。";

        return ['reply' => $reply, 'imported' => $created, 'skipped' => $skipped];
    }

    // ============================================================
    // 2. 工作量 AI 评估
    // ============================================================

    public static function evaluate($userId, $input = [])
    {
        $term    = Term::current();
        $termId  = $term ? $term->id : 0;
        $teacher = Teacher::get($userId);

        $ov      = Stats::overview($userId, $termId);
        $byWeek  = Stats::byWeek($userId, $termId);
        $byMonth = Stats::byMonth($userId, $termId);

        $totalWeeks = $term ? ($term->end_week - $term->start_week + 1) : 20;
        $totalWeeks = max(1, $totalWeeks);
        $avgWeek    = round($ov['periods'] / $totalWeeks, 1);

        // 峰值周
        $peak = null;
        foreach ($byWeek as $w) {
            if ($peak === null || $w['periods'] > $peak['periods']) $peak = $w;
        }

        $standard = (float)Setting::get('week_standard_periods', 12);
        $rate     = $standard > 0 ? round($avgWeek / $standard * 100, 1) : 0;

        if ($rate >= 120)      $level = '偏高';
        elseif ($rate >= 100)  $level = '饱和';
        elseif ($rate >= 70)   $level = '适中';
        else                   $level = '偏轻';

        // 类型结构
        $typeLines = [];
        foreach (Lesson::TYPES as $code => $txt) {
            if (isset($ov['by_type'][$code])) {
                $t = $ov['by_type'][$code];
                $pct = $ov['periods'] > 0 ? round($t['periods'] / $ov['periods'] * 100, 1) : 0;
                $typeLines[] = "· {$txt}：{$t['periods']} 节（{$t['cnt']} 条，占 {$pct}%）";
            }
        }

        $termName = $term ? $term->name : '当前学期';
        $peakTxt  = $peak ? "第 {$peak['week']} 周（{$peak['periods']} 节）" : '暂无数据';

        $reply  = "【{$termName} 工作量评估】\n\n";
        $reply .= "教师：{$teacher->name}（{$teacher->departmentName()}）\n";
        $reply .= "总课时：{$ov['periods']} 节（共 {$ov['cnt']} 条记录）\n";
        $reply .= "预估课酬：¥" . number_format($ov['amount'], 2) . "\n";
        $reply .= "周均课时：{$avgWeek} 节 / 周（按 {$totalWeeks} 个教学周计）\n";
        $reply .= "峰值周：{$peakTxt}\n";
        $reply .= "饱和度：{$rate}%（对照周标准 {$standard} 节）—— {$level}\n";
        if ($typeLines) {
            $reply .= "\n课时结构：\n" . implode("\n", $typeLines);
        }

        // 教务处说明文案
        $doc  = "{$termName}，本人承担教学任务共计 {$ov['periods']} 节，";
        $doc .= "其中";
        $segs = [];
        foreach (Lesson::TYPES as $code => $txt) {
            if (isset($ov['by_type'][$code])) {
                $segs[] = "{$txt}" . $ov['by_type'][$code]['periods'] . " 节";
            }
        }
        $doc .= implode('、', $segs);
        $doc .= "。周均 {$avgWeek} 节，教学任务量处于「{$level}」水平。";
        if ($peak) {
            $doc .= "课时峰值出现在第 {$peak['week']} 周（{$peak['periods']} 节）。";
        }
        $doc .= "上述课时均已按学校规定完成授课，特此说明。";

        $reply .= "\n\n【教务处说明文案（可直接复制）】\n" . $doc;

        return [
            'reply'    => $reply,
            'summary'  => $ov,
            'by_week'  => $byWeek,
            'by_month' => $byMonth,
            'avg_week' => $avgWeek,
            'peak'     => $peak,
            'rate'     => $rate,
            'level'    => $level,
            'doc'      => $doc,
        ];
    }

    // ============================================================
    // 3. AI 数据问答
    // ============================================================

    public static function qa($userId, $text, $input = [])
    {
        $term   = Term::current();
        $termId = $term ? $term->id : 0;

        // --- 某月课时 / 课酬 ---
        if (preg_match('/(\d{4})\s*[-年]\s*(\d{1,2})\s*月?/u', $text, $m)) {
            $ym    = sprintf('%04d-%02d', intval($m[1]), intval($m[2]));
            $scope = ['teacher_id' => $userId, 'month' => $ym];
            $row   = Lesson::scopeQuery($scope)
                ->field('COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt')
                ->find();
            $reply = "{$ym} 你共记录 {$row['cnt']} 条课时，合计 {$row['periods']} 节，";
            $reply .= "课酬 ¥" . number_format($row['amount'], 2) . "。";
            if ($row['cnt'] == 0) $reply = "{$ym} 没有查到你的课时记录。";
            return ['reply' => $reply, 'month' => $ym, 'data' => [
                'periods' => (float)$row['periods'],
                'amount'  => (float)$row['amount'],
                'cnt'     => (int)$row['cnt'],
            ]];
        }

        // --- 峰值周 ---
        if (preg_match('/(峰值|最多|最忙|哪周)/u', $text)) {
            $byWeek = Stats::byWeek($userId, $termId);
            if (empty($byWeek)) return ['reply' => '当前学期还没有课时记录，无法计算峰值周。'];
            $peak = null;
            foreach ($byWeek as $w) {
                if ($peak === null || $w['periods'] > $peak['periods']) $peak = $w;
            }
            return ['reply' => "课时峰值出现在第 {$peak['week']} 周，共 {$peak['periods']} 节，课酬 ¥" . number_format($peak['amount'], 2) . "。",
                    'peak' => $peak];
        }

        // --- 某课程课时 ---
        $courses = Lesson::scopeQuery(['teacher_id' => $userId, 'term_id' => $termId])
            ->group('course_name')->column('course_name');
        foreach ($courses as $cname) {
            if ($cname !== '' && mb_strpos($text, $cname, 0, 'UTF-8') !== false) {
                $row = Lesson::scopeQuery(['teacher_id' => $userId, 'term_id' => $termId])
                    ->where('course_name', $cname)
                    ->field('COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt')
                    ->find();
                $reply = "课程《{$cname}》本学期共 {$row['cnt']} 次、{$row['periods']} 节，";
                $reply .= "课酬 ¥" . number_format($row['amount'], 2) . "。";
                return ['reply' => $reply, 'course' => $cname];
            }
        }

        // --- 总课酬 ---
        if (preg_match('/(课酬|课时费|工资|多少钱)/u', $text)) {
            $ov = Stats::overview($userId, $termId);
            return ['reply' => "本学期你共 {$ov['periods']} 节，预估课酬 ¥" . number_format($ov['amount'], 2) . "。",
                    'data' => $ov];
        }

        // --- 兜底：给总览 ---
        $ov      = Stats::overview($userId, $termId);
        $monthly = Stats::byMonth($userId, $termId);
        $reply   = "本学期你共记录 {$ov['cnt']} 条课时，{$ov['periods']} 节，";
        $reply  .= "课酬 ¥" . number_format($ov['amount'], 2) . "。\n\n逐月分布：\n";
        foreach ($monthly as $m) {
            $reply .= "· {$m['month']}：{$m['periods']} 节 / ¥" . number_format($m['amount'], 2) . "\n";
        }
        if (empty($monthly)) $reply .= "（暂无带日期的记录）";
        return ['reply' => trim($reply), 'data' => $ov, 'by_month' => $monthly];
    }

    // ============================================================
    // 4. 课时证明文案生成
    // ============================================================

    public static function proof($userId, $input = [])
    {
        $term   = Term::current();
        $termId = $term ? $term->id : 0;
        $ov     = Stats::overview($userId, $termId);
        $t      = Teacher::get($userId);

        $month = isset($input['month']) ? $input['month'] : '';
        if ($month) {
            $ov = Stats::overview($userId, $termId, $month);
        }

        $upper   = Stats::rmbUpper($ov['amount']);
        $termName = $term ? $term->name : '本学期';
        $scopeTxt = $month ? "{$month} 期间" : "{$termName}";
        $date     = date('Y年m月d日');

        $text  = "课 时 证 明\n\n";
        $text .= "兹证明 {$t->name} 老师（{$t->departmentName()}，{$t->position}）";
        $text .= "于 {$scopeTxt} 承担我校教学任务，";
        $text .= "累计完成课时 {$ov['periods']} 节，共 {$ov['cnt']} 次授课，";
        $text .= "应发课酬合计人民币 ¥" . number_format($ov['amount'], 2) . "（大写：{$upper}）。\n\n";

        $courseTxt = [];
        foreach ($ov['by_course'] as $c) {
            $courseTxt[] = "《{$c['course_name']}》{$c['periods']} 节";
        }
        if ($courseTxt) {
            $text .= "课程明细：" . implode('；', $courseTxt) . "。\n\n";
        }

        $text .= "以上课时数据来源于本校课时管理系统，经核对无误，特此证明。\n\n";
        $text .= "                              " . Setting::get('school_name', 'XX中等职业学校') . "\n";
        $text .= "                              {$date}\n";

        return ['reply' => $text, 'text' => $text, 'amount_upper' => $upper, 'stat' => [
            'periods' => $ov['periods'], 'amount' => $ov['amount'], 'cnt' => $ov['cnt'],
        ]];
    }

    // ============================================================
    // 5. 兜底对话
    // ============================================================

    public static function chat($userId, $text)
    {
        $reply  = "我可以帮你做这几件事：\n\n";
        $reply .= "1. 课表文本识别批量建课 —— 直接粘贴课表文字，例如：\n";
        $reply .= "   「工业机器人导论 机器人2301班 1-16周 周一 1-2节」\n\n";
        $reply .= "2. 工作量评估 —— 回复「工作量评估」，生成学期饱和度与教务处说明文案\n\n";
        $reply .= "3. 数据问答 —— 例如「2026-10 多少课时」「峰值周」「本学期课酬」\n\n";
        $reply .= "4. 课时证明 —— 回复「课时证明」，生成可直接打印的证明材料\n";
        return ['reply' => $reply, 'engine' => 'local'];
    }

    // ============================================================
    // 6. 大模型分析与统一调用
    // ============================================================

    /**
     * 将本地规则结果交给大模型润色或补充分析。
     * 课表解析不走此路径，避免模型生成不可校验的入库结构。
     */
    private static function callLLMForIntent($userId, $intent, $text, $result)
    {
        $teacher = Teacher::get($userId);
        $term = Term::current();
        $context = [
            'teacher' => $teacher ? ['name' => $teacher->name, 'position' => $teacher->position] : [],
            'term' => $term ? $term->name : '当前学期',
            'result' => self::limitContext($result),
        ];
        $instructions = [
            'evaluate' => '请根据给定统计结果，输出简洁、准确的工作量分析，并保留所有关键数字。不要编造数据。',
            'qa' => '请直接回答教师的问题。只能使用给定统计结果，若数据不足请明确说不知道。',
            'proof' => '请根据给定统计结果生成正式、通顺的课时证明文案，不要修改姓名、课时和金额数字。',
            'chat' => '请回答教师关于课时管理的问题。只能依据给定的本人数据，不回答无关问题，不编造数据。',
        ];
        $system = isset($instructions[$intent]) ? $instructions[$intent] : $instructions['chat'];
        $payload = [
            'user_id' => $userId,
            'role' => $teacher ? $teacher->role : 'teacher',
            'intent' => $intent,
            'messages' => [
                ['role' => 'system', 'content' => '你是职业学校课时管理助手。' . $system],
                ['role' => 'user', 'content' => trim($text) === '' ? '请分析当前结果。' : $text],
                ['role' => 'user', 'content' => '以下是服务端按当前教师权限计算出的数据，只能依据这些数据作答：' . json_encode($context, JSON_UNESCAPED_UNICODE)],
            ],
            'context' => $context,
        ];
        return self::callLLM($userId, $payload);
    }

    private static function limitContext($value)
    {
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);
            if ($json !== false && strlen($json) > 12000) {
                return ['summary' => mb_substr($json, 0, 12000, 'UTF-8')];
            }
            return $value;
        }
        return mb_substr((string)$value, 0, 12000, 'UTF-8');
    }

    /**
     * 调用 OpenAI Chat Completions 兼容接口。
     * 失败、超时、格式不识别或未配置时均返回 null，由本地规则结果继续响应。
     */
    private static function callLLM($userId, $payload)
    {
        $config = AiConfig::effective($userId);
        if (!AiConfig::isConfigured($config) || !function_exists('curl_init')) return null;
        if (!preg_match('#^https?://#i', $config['url'])) return null;

        $body = json_encode([
            'model' => $config['model'],
            'messages' => $payload['messages'],
            'temperature' => 0.3,
            'user' => 'user_' . intval($userId),
        ], JSON_UNESCAPED_UNICODE);
        if ($body === false) return null;

        try {
            $ch = curl_init($config['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $config['key'],
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_errno($ch);
            curl_close($ch);

            if ($err || $httpCode < 200 || $httpCode >= 300 || !$resp) return null;
            $json = json_decode($resp, true);
            if (!is_array($json)) return null;
            if (isset($json['choices'][0]['message']['content'])) return trim((string)$json['choices'][0]['message']['content']);
            if (isset($json['result']) && is_string($json['result'])) return trim($json['result']);
            if (isset($json['output']['text']) && is_string($json['output']['text'])) return trim($json['output']['text']);
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }
}
