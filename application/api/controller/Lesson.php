<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Course;
use app\common\model\Lesson as LessonModel;
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
     * 恢复软删除
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
        if ($section < 1 || $section > 6) {
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

        return true;
    }
}
