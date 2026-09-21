<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Course;
use app\common\model\Lesson as LessonModel;
use app\common\model\Teacher;
use app\common\model\Term;

/**
 * 课时记录接口
 *
 * 数据隔离规则（全系统最重要的一条）：
 *   非管理员的所有读写，teacher_id 一律强制取当前登录用户，
 *   前端传什么都不认。管理员可以带 teacher_id 查看指定教师。
 */
class Lesson extends Base
{
    /**
     * 课时列表（分页 + 多维筛选）
     */
    public function index()
    {
        $this->requireLogin();
        $scope = $this->buildScope();
        $page  = max(1, intval($this->input('page', 1)));
        $limit = min(200, max(1, intval($this->input('limit', 20))));

        // 计数与取数分开构造查询：共用一个 Query 实例时，
        // count() 会把 field 改写成 COUNT(*)，后续 select() 就废了
        $total = LessonModel::scopeQuery($scope)->count();
        $rows  = LessonModel::scopeQuery($scope)
            ->order('week asc, weekday asc, section asc')
            ->page($page, $limit)
            ->select();

        $list = [];
        foreach ($rows as $r) {
            $row = $r->toFullArray();
            if ($this->user->isAdmin()) {
                $row['teacher_name'] = $r->teacherName();
            }
            $list[] = $row;
        }

        // 当前筛选条件下的汇总，前端表格下面直接显示合计
        $sum = LessonModel::scopeQuery($scope)
            ->field('COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt')
            ->find();

        return $this->ok([
            'list'  => $list,
            'total' => (int)$total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => (int)ceil($total / $limit),
            'summary' => [
                'cnt'     => (int)$sum['cnt'],
                'periods' => (float)$sum['periods'],
                'amount'  => (float)$sum['amount'],
            ],
        ]);
    }

    /**
     * 新增 / 修改一条课时
     */
    public function save()
    {
        $this->requireLogin();
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $check = $this->validateLesson($data);
        if ($check !== true) {
            return $this->fail($check);
        }

        $termId = intval($data['term_id']);

        if ($id > 0) {
            $lesson = LessonModel::get($id);
            if (!$lesson) {
                return $this->fail('记录不存在或已被删除');
            }
            if (!$this->user->isAdmin() && $lesson->teacher_id != $this->user->id) {
                return $this->fail('无权修改他人的课时记录');
            }
            $teacherId = $lesson->teacher_id;
        } else {
            // 新增：管理员可以给指定教师代录，普通教师只能录自己的
            $teacherId = $this->user->isAdmin() && !empty($data['teacher_id'])
                ? intval($data['teacher_id'])
                : $this->user->id;
        }

        // 防重复：同一教师 + 学期 + 周 + 星期 + 节次
        $dup = LessonModel::findDup(
            $teacherId, $termId,
            intval($data['week']), intval($data['weekday']), intval($data['section']),
            $id
        );
        if ($dup) {
            return $this->fail(sprintf(
                '第%d周 %s %s 已有课时记录（%s），不能重复录入',
                intval($data['week']),
                LessonModel::WEEKDAYS[intval($data['weekday'])],
                LessonModel::SECTIONS[intval($data['section'])],
                $dup->course_name
            ));
        }

        $row = LessonModel::buildData($data, $teacherId);

        if ($id > 0) {
            $lesson->save($row);
            $newId = $id;
            $action = 'update';
            $summary = sprintf('修改课时：%s 第%d周 %s %s',
                $row['course_name'], $row['week'],
                LessonModel::WEEKDAYS[$row['weekday']], LessonModel::SECTIONS[$row['section']]);
        } else {
            $lesson = LessonModel::create($row);
            $newId  = $lesson->id;
            $action = 'create';
            $summary = sprintf('新增课时：%s 第%d周 %s %s（%s节 × %s元）',
                $row['course_name'], $row['week'],
                LessonModel::WEEKDAYS[$row['weekday']], LessonModel::SECTIONS[$row['section']],
                $row['periods'], $row['price']);
        }

        $this->log($action, 'lesson', $newId, $summary);

        return $this->ok(['id' => $newId], $id > 0 ? '保存成功' : '新增成功');
    }

    /**
     * 批量周次生成：起始周~结束周 + 星期 + 节次，一键铺满整学期
     */
    public function batch()
    {
        $this->requireLogin();
        $data = $this->jsonInput();

        $check = $this->validateLesson($data, true);
        if ($check !== true) {
            return $this->fail($check);
        }

        $startWeek = intval($data['start_week']);
        $endWeek   = intval($data['end_week']);
        $term      = Term::get(intval($data['term_id']));
        if (!$term) {
            return $this->fail('学期不存在');
        }
        if ($startWeek < $term->start_week || $endWeek > $term->end_week) {
            return $this->fail(sprintf('周次范围应在第%d周 ~ 第%d周之间', $term->start_week, $term->end_week));
        }

        // 批量生成时以「第1周」的星期节次为准，周次由循环覆盖
        $data['week'] = $startWeek;

        $teacherId = $this->user->isAdmin() && !empty($data['teacher_id'])
            ? intval($data['teacher_id'])
            : $this->user->id;

        $result = LessonModel::batchCreate($data, $teacherId, $term);

        $created = count($result['created']);
        $skipped = count($result['skipped']);
        $msg = "批量生成完成：新增 {$created} 条";
        if ($skipped > 0) {
            $msg .= "，跳过 {$skipped} 条（第" . implode('、', $result['skipped']) . "周已存在记录）";
        }

        $this->log('batch', 'lesson', 0, sprintf('批量生成课时：%s 第%d-%d周 %s %s，新增%d条跳过%d条',
            $data['course_name'], $startWeek, $endWeek,
            LessonModel::WEEKDAYS[intval($data['weekday'])], LessonModel::SECTIONS[intval($data['section'])],
            $created, $skipped));

        return $this->ok([
            'created' => $created,
            'skipped' => $skipped,
            'skipped_weeks' => $result['skipped'],
        ], $msg);
    }

    /**
     * 软删除
     */
    public function delete()
    {
        $this->requireLogin();
        $data   = $this->jsonInput();
        $lesson = LessonModel::get(intval($data['id']));
        if (!$lesson) {
            return $this->fail('记录不存在');
        }
        if (!$this->user->isAdmin() && $lesson->teacher_id != $this->user->id) {
            return $this->fail('无权删除他人的课时记录');
        }
        $lesson->softDelete();

        $this->log('delete', 'lesson', $lesson->id, sprintf('删除课时：%s 第%d周 %s %s',
            $lesson->course_name, $lesson->week,
            $lesson->weekdayText(), $lesson->sectionText()));

        return $this->ok(null, '已删除');
    }

    /**
     * 批量删除
     *
     * 两种调用方式：
     *   1) 勾选若干条：ids=[12,34,56]
     *   2) 按当前查询条件一键删全部：mode=all，附带 term_id/course_id/type/...
     *
     * 强制数据隔离：
     *   - 非管理员的 ids 必须属于 teacher_id = 当前用户，
     *     否则拒绝整批（避免"传个别人的 id 数组过来测一下"）
     *   - mode=all 时，scope['teacher_id'] 强制写为当前用户
     */
    public function batchDelete()
    {
        $this->requireLogin();
        $data = $this->jsonInput();

        $ids   = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
        $mode  = isset($data['mode']) ? trim(strval($data['mode'])) : '';
        $note  = isset($data['note']) ? trim(strval($data['note'])) : '';  // 可选二次确认填的备注

        // 1) 勾选模式
        if ($mode !== 'all') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) {
                return $i > 0;
            })));
            if (empty($ids)) {
                return $this->fail('请先勾选要删除的记录');
            }
            if (count($ids) > 500) {
                return $this->fail('单次最多删除 500 条，请缩小范围或分批操作');
            }

            $q = LessonModel::where('id', 'in', $ids)->where('deleted_at', 0);
            // 非管理员：限定 teacher_id = 自己
            if (!$this->user->isAdmin()) {
                $q->where('teacher_id', $this->user->id);
            }
            $rows = $q->select();

            // 找出"传过来但数据库里没有的"——明确告诉前端
            $existed = [];
            foreach ($rows as $r) {
                $existed[(int)$r->id] = $r;
            }
            $missing = array_values(array_diff($ids, array_keys($existed)));
            if ($missing) {
                return $this->fail('以下记录不存在或已被删除：' . implode(',', $missing));
            }

            $count = 0;
            foreach ($rows as $r) {
                $r->softDelete();
                $count++;
            }

            $this->log('batch_delete', 'lesson', 0, sprintf(
                '批量删除课时 %d 条%s',
                $count,
                $note !== '' ? '（备注：' . $note . '）' : ''
            ));

            return $this->ok(['deleted' => $count], "已删除 {$count} 条");
        }

        // 2) 全量模式：按当前筛选条件
        $scope = $this->buildScope();
        // 非管理员：scope.teacher_id 强制设为自己（不管前端传啥）
        if (!$this->user->isAdmin()) {
            $scope['teacher_id'] = $this->user->id;
        }
        $count = LessonModel::batchSoftDeleteByScope($scope);

        $this->log('batch_delete', 'lesson', 0, sprintf(
            '按筛选条件批量删除课时 %d 条（scope=%s）%s',
            $count,
            json_encode($scope, JSON_UNESCAPED_UNICODE),
            $note !== '' ? '（备注：' . $note . '）' : ''
        ));

        return $this->ok(['deleted' => $count], "已删除 {$count} 条");
    }

    /**
     * 批量恢复
     *
     * 同 batchDelete 的两种模式 + 数据隔离规则。
     * 恢复是"高危反向操作"，所以也走软删除的日志通道，方便审计。
     */
    public function batchRestore()
    {
        $this->requireLogin();
        $data = $this->jsonInput();

        $ids  = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
        $mode = isset($data['mode']) ? trim(strval($data['mode'])) : '';

        if ($mode !== 'all') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) {
                return $i > 0;
            })));
            if (empty($ids)) {
                return $this->fail('请先勾选要恢复的记录');
            }
            if (count($ids) > 500) {
                return $this->fail('单次最多恢复 500 条');
            }

            $q = LessonModel::where('id', 'in', $ids)->where('deleted_at', '>', 0);
            if (!$this->user->isAdmin()) {
                $q->where('teacher_id', $this->user->id);
            }
            $rows = $q->select();

            $restored = 0;
            $conflict = 0;
            foreach ($rows as $r) {
                // 恢复前先查冲突
                $dup = LessonModel::findDup(
                    $r->teacher_id, $r->term_id,
                    $r->week, $r->weekday, $r->section,
                    $r->id
                );
                if ($dup) {
                    $conflict++;
                    continue;
                }
                $r->restore();
                $restored++;
            }

            $this->log('batch_restore', 'lesson', 0, sprintf(
                '批量恢复课时：成功 %d 条，跳过 %d 条（冲突）',
                $restored, $conflict
            ));

            $msg = "已恢复 {$restored} 条";
            if ($conflict > 0) {
                $msg .= "，{$conflict} 条因时段冲突未恢复";
            }
            return $this->ok(['restored' => $restored, 'conflict' => $conflict], $msg);
        }

        // 模式：按 scope 全量恢复
        $scope = $this->buildScope();
        if (!$this->user->isAdmin()) {
            $scope['teacher_id'] = $this->user->id;
        }
        $count = LessonModel::batchRestoreByScope($scope);
        $this->log('batch_restore', 'lesson', 0, sprintf(
            '按筛选条件批量恢复课时 %d 条（scope=%s）',
            $count,
            json_encode($scope, JSON_UNESCAPED_UNICODE)
        ));
        return $this->ok(['restored' => $count], "已恢复 {$count} 条");
    }

    /**
     * 删除前先数一数：让前端在二次确认弹窗里把数字说清楚
     *
     * 接收同 batchDelete 的 payload，返回 affected 数量。
     * 防止前端"以为删了 3 条实际删了 5000 条"的失误
     */
    public function batchDeletePreview()
    {
        $this->requireLogin();
        $data = $this->jsonInput();

        $ids  = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
        $mode = isset($data['mode']) ? trim(strval($data['mode'])) : '';

        if ($mode !== 'all') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) {
                return $i > 0;
            })));
            if (empty($ids)) {
                return $this->fail('请先勾选记录');
            }
            $q = LessonModel::where('id', 'in', $ids)->where('deleted_at', 0);
            if (!$this->user->isAdmin()) {
                $q->where('teacher_id', $this->user->id);
            }
            $count = $q->count();
            $sum   = $q->field('COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount')->find();
            return $this->ok([
                'count'   => (int)$count,
                'periods' => (float)$sum['periods'],
                'amount'  => (float)$sum['amount'],
                'mode'    => 'ids',
            ]);
        }

        $scope = $this->buildScope();
        if (!$this->user->isAdmin()) {
            $scope['teacher_id'] = $this->user->id;
        }
        $q = LessonModel::scopeQuery($scope);
        $count = $q->count();
        $sum   = $q->field('COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount')->find();
        return $this->ok([
            'count'   => (int)$count,
            'periods' => (float)$sum['periods'],
            'amount'  => (float)$sum['amount'],
            'mode'    => 'all',
        ]);
    }

    /**
     * 恢复软删除（单条）
     */
    public function restore()
    {
        $this->requireLogin();
        $data   = $this->jsonInput();
        $lesson = LessonModel::get(intval($data['id']));
        if (!$lesson) {
            return $this->fail('记录不存在');
        }
        if (!$this->user->isAdmin() && $lesson->teacher_id != $this->user->id) {
            return $this->fail('无权恢复他人的课时记录');
        }
        // 恢复前再查一次冲突，避免恢复后与新增的记录重叠
        $dup = LessonModel::findDup(
            $lesson->teacher_id, $lesson->term_id,
            $lesson->week, $lesson->weekday, $lesson->section,
            $lesson->id
        );
        if ($dup) {
            return $this->fail('该时段已有其他记录，无法恢复');
        }
        $lesson->restore();

        $this->log('restore', 'lesson', $lesson->id, sprintf('恢复课时：%s 第%d周',
            $lesson->course_name, $lesson->week));

        return $this->ok(null, '已恢复');
    }

    /**
     * 最近 N 条（首页快捷编辑卡片）
     */
    public function recent()
    {
        $this->requireLogin();
        $limit = min(20, max(1, intval($this->input('limit', 5))));
        $scope = ['teacher_id' => $this->teacherScopeId()];

        $rows = LessonModel::scopeQuery($scope)
            ->order('created_at desc, id desc')
            ->limit($limit)
            ->select();

        $list = [];
        foreach ($rows as $r) {
            $list[] = $r->toFullArray();
        }
        return $this->ok($list);
    }

    /**
     * 历史课程模板：快速录入时一键复用班级/单价
     * 按最近使用排序，去重后返回
     */
    public function templates()
    {
        $this->requireLogin();
        $scope = ['teacher_id' => $this->teacherScopeId()];

        $rows = LessonModel::scopeQuery($scope)
            ->field('course_id, course_name, classes, price, periods, MAX(id) AS last_id')
            ->group('course_name, classes, price, periods')
            ->order('last_id desc')
            ->limit(30)
            ->select();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'course_id'   => (int)$r->course_id,
                'course_name' => $r->course_name,
                'classes'     => $r->classes,
                'price'       => (float)$r->price,
                'periods'     => (float)$r->periods,
            ];
        }
        return $this->ok($list);
    }

    // ============================================================
    // 管理员：周次/星期 ↔ 授课日期 历史数据校准（以排课位置为准）
    // ============================================================

    /**
     * 校准预览：找出 teach_date 与按「周次+星期+学期开学日」推导日期不一致的记录。
     * 默认只看未软删、已填日期、常规/实训课（调课/补课允许偏离，include_special=1 才纳入）。
     */
    public function dateRepairPreview()
    {
        $this->requireLogin();
        if (!$this->user->isAdmin()) {
            return $this->fail('仅管理员可执行数据校准');
        }
        $res = $this->collectDateMismatch();
        if (is_string($res)) {
            return $this->fail($res);
        }
        [$mismatch, $scanned] = $res;

        // 预览只回传前 200 条，避免响应过大；执行仍按全部命中的 id
        $preview = array_slice($mismatch, 0, 200);
        return $this->ok([
            'total'       => count($mismatch),
            'scanned'     => $scanned,
            'ids'         => array_map(function ($m) { return $m['id']; }, $mismatch),
            'preview'     => $preview,
            'rule'        => '以周次/星期（排课位置）为准，重算授课日期 = 开学日 + (周次-1)*7 + (星期-1)',
        ], '预览完成');
    }

    /**
     * 执行校准：按预览返回的 id 列表（或同条件全量）批量把 teach_date 改成推导值。
     * 必须带 confirm=1；单次最多 1000 条。
     */
    public function dateRepairRun()
    {
        $this->requireLogin();
        if (!$this->user->isAdmin()) {
            return $this->fail('仅管理员可执行数据校准');
        }
        $data = $this->jsonInput();
        if (empty($data['confirm'])) {
            return $this->fail('缺少确认参数 confirm');
        }

        $ids = isset($data['ids']) && is_array($data['ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $data['ids']), function ($i) { return $i > 0; })))
            : [];

        if ($ids) {
            if (count($ids) > 1000) {
                return $this->fail('单次最多校准 1000 条，请缩小学期/教师范围分批处理');
            }
            $rows = LessonModel::where('id', 'in', $ids)->where('deleted_at', 0)->select();
        } else {
            // 未给 id：按当前筛选条件全量执行
            $res = $this->collectDateMismatch();
            if (is_string($res)) {
                return $this->fail($res);
            }
            [$mismatch] = $res;
            $wantIds = array_slice(array_map(function ($m) { return $m['id']; }, $mismatch), 0, 1000);
            if (empty($wantIds)) {
                return $this->ok(['updated' => 0], '没有需要校准的记录');
            }
            $rows = LessonModel::where('id', 'in', $wantIds)->where('deleted_at', 0)->select();
        }

        $updated = 0;
        $samples = [];
        foreach ($rows as $lesson) {
            $term = Term::get($lesson->term_id);
            $expected = $term ? Term::dateOf($term, (int)$lesson->week, (int)$lesson->weekday) : null;
            if ($expected === null || $lesson->teach_date === $expected || $lesson->teach_date === null || $lesson->teach_date === '') {
                continue;
            }
            $before = $lesson->teach_date;
            $lesson->teach_date = $expected;
            if ($lesson->save()) {
                $updated++;
                if (count($samples) < 10) {
                    $samples[] = '#' . $lesson->id . ' ' . $lesson->course_name . '：' . $before . ' → ' . $expected;
                }
            }
        }

        $this->log('date_repair', 'lesson', 0, sprintf(
            '校准课时周次↔日期：更新 %d 条（以排课位置为准）。示例：%s',
            $updated, implode('；', $samples)
        ));

        return $this->ok(['updated' => $updated, 'samples' => $samples], "校准完成，共修正 {$updated} 条记录");
    }

    /**
     * 收集日期不一致的记录（预览/执行共用）。
     * @return array|string  [mismatchRows, scannedCount] 或错误文案
     */
    private function collectDateMismatch()
    {
        $termId = intval($this->input('term_id', 0));
        $teacherId = intval($this->input('teacher_id', 0));
        $includeSpecial = intval($this->input('include_special', 0)) === 1;

        $q = LessonModel::where('deleted_at', 0);
        if ($termId) {
            $q->where('term_id', $termId);
        }
        if ($teacherId) {
            $q->where('teacher_id', $teacherId);
        }
        $rows = $q->order('term_id asc, week asc, weekday asc, id asc')->limit(20000)->select();

        $termCache = [];
        $mismatch = [];
        $scanned = 0;
        foreach ($rows as $r) {
            // 调课/补课默认排除
            $type = $r->getData('type');
            if (!$includeSpecial && in_array($type, ['swap', 'makeup'], true)) {
                continue;
            }
            if ($r->teach_date === null || $r->teach_date === '') {
                continue; // 未填日期的不处理（那是另一类问题）
            }
            if (!isset($termCache[$r->term_id])) {
                $termCache[$r->term_id] = Term::get($r->term_id);
            }
            $term = $termCache[$r->term_id];
            if (!$term || !$term->start_date) {
                continue; // 学期没配开学日，无法推导
            }
            $scanned++;
            $expected = Term::dateOf($term, (int)$r->week, (int)$r->weekday);
            if ($expected !== null && $r->teach_date !== $expected) {
                $mismatch[] = [
                    'id'          => (int)$r->id,
                    'term_id'     => (int)$r->term_id,
                    'teacher_id'  => (int)$r->teacher_id,
                    'course_name' => $r->course_name,
                    'classes'     => $r->classes,
                    'week'        => (int)$r->week,
                    'weekday'     => (int)$r->weekday,
                    'type'        => $type,
                    'date_now'    => $r->teach_date,
                    'date_fix'    => $expected,
                ];
            }
        }
        return [$mismatch, $scanned];
    }

    // ============================================================
    // 内部方法
    // ============================================================

    /**
     * 当前查询的 teacher_id：管理员可指定，教师恒为自己
     */
    private function teacherScopeId()
    {
        if ($this->user->isAdmin()) {
            $tid = intval($this->input('teacher_id', 0));
            return $tid; // 0 表示全校
        }
        return $this->user->id;
    }

    /**
     * 组装筛选条件
     */
    private function buildScope()
    {
        $scope = [];

        $tid = $this->teacherScopeId();
        if ($tid) {
            $scope['teacher_id'] = $tid;
        }

        foreach (['term_id' => 'term_id', 'course_id' => 'course_id'] as $key => $field) {
            $v = intval($this->input($key, 0));
            if ($v) $scope[$field] = $v;
        }

        foreach (['type' => 'type', 'month' => 'month', 'keyword' => 'keyword'] as $key => $field) {
            $v = trim($this->input($key, ''));
            if ($v !== '') $scope[$field] = $v;
        }

        foreach (['week' => 'week', 'weekday' => 'weekday', 'section' => 'section'] as $key => $field) {
            $v = intval($this->input($key, 0));
            if ($v) $scope[$field] = $v;
        }

        $sd = trim($this->input('start_date', ''));
        $ed = trim($this->input('end_date', ''));
        if ($sd !== '' && $ed !== '') {
            $scope['start_date'] = $sd;
            $scope['end_date']   = $ed;
        }

        return $scope;
    }

    /**
     * 校验课时表单
     * @return true|string
     */
    private function validateLesson($data, $isBatch = false)
    {
        $courseName = trim(isset($data['course_name']) ? $data['course_name'] : '');
        if ($courseName === '') {
            return '请填写课程名称';
        }
        if (mb_strlen($courseName, 'UTF-8') > 64) {
            return '课程名称不能超过 64 个字';
        }

        if (!intval($data['term_id'])) {
            return '请选择学期';
        }
        $term = Term::get(intval($data['term_id']));
        if (!$term) {
            return '学期不存在';
        }

        $weekday = intval(isset($data['weekday']) ? $data['weekday'] : 0);
        if ($weekday < 1 || $weekday > 7) {
            return '请选择星期';
        }

        $section = intval(isset($data['section']) ? $data['section'] : 0);
        if ($section < 1 || $section > LessonModel::SECTION_MAX) {
            return '请选择上课节次';
        }

        if ($isBatch) {
            $sw = intval(isset($data['start_week']) ? $data['start_week'] : 0);
            $ew = intval(isset($data['end_week']) ? $data['end_week'] : 0);
            if ($sw < 1 || $ew < 1) {
                return '请填写起始周与结束周';
            }
            if ($ew - $sw > 40) {
                return '单次批量生成最多 40 周，请分批操作';
            }
        } else {
            $week = intval(isset($data['week']) ? $data['week'] : 0);
            if ($week < $term->start_week || $week > $term->end_week) {
                return sprintf('周次应在第%d周 ~ 第%d周之间', $term->start_week, $term->end_week);
            }
        }

        $periods = floatval(isset($data['periods']) ? $data['periods'] : 2);
        if ($periods <= 0 || $periods > 12) {
            return '上课节数应在 0 ~ 12 之间';
        }

        $price = floatval(isset($data['price']) ? $data['price'] : 0);
        if ($price < 0 || $price > 100000) {
            return '课时单价不合法';
        }

        $type = isset($data['type']) ? $data['type'] : 'normal';
        if (!isset(LessonModel::TYPES[$type])) {
            return '上课类型不合法';
        }

        $date = trim(isset($data['teach_date']) ? $data['teach_date'] : '');
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '授课日期格式应为 YYYY-MM-DD';
        }

        // 周次/星期（排课位置）与实际授课日期一致性硬校验，杜绝再次产生
        // 「第1周周一却是 9-14」这类错位数据。仅单条录入走这里：
        //  - 批量生成/导入的日期由 Term::dateOf 推导，天然自洽；
        //  - 调课(swap)/补课(makeup) 本来就允许日期偏离排课位置，放行；
        //  - 开学日未配置或日期留空时不拦截。
        if (!$isBatch && $date !== '') {
            $typeForCheck = isset($data['type']) ? $data['type'] : 'normal';
            if (!in_array($typeForCheck, ['swap', 'makeup'], true)) {
                $consistent = Term::checkDateConsistency(
                    $term,
                    intval(isset($data['week']) ? $data['week'] : 0),
                    $weekday,
                    $date
                );
                if ($consistent !== true) {
                    return $consistent;
                }
            }
        }

        return true;
    }
}
