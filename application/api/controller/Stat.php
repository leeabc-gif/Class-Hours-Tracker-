<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Lesson as LessonModel;
use app\common\model\Term;
use app\common\service\Stats;

/**
 * 统计接口
 *
 * 计算口径全局唯一：单条费用 = 上课节数 × 课程单价，
 * 统计一律对 amount 求和，不在这里现算，避免两处口径不一致。
 */
class Stat extends Base
{
    /**
     * 个人工作台一次性取数（少一轮往返）
     */
    public function dashboard()
    {
        $this->requireLogin();
        $uid    = $this->user->id;
        $termId = intval($this->input('term_id', 0));
        if (!$termId) {
            $term = Term::current();
            $termId = $term ? $term->id : 0;
        }

        $recent = [];
        foreach (LessonModel::scopeQuery(['teacher_id' => $uid])
            ->order('created_at desc, id desc')->limit(5)->select() as $r) {
            $recent[] = $r->toFullArray();
        }

        return $this->ok([
            'overview' => Stats::overview($uid, $termId),
            'by_month' => Stats::byMonth($uid, $termId),
            'by_week'  => Stats::byWeek($uid, $termId),
            'recent'   => $recent,
            'month_now' => Stats::overview($uid, $termId, date('Y-m')),
        ]);
    }

    /**
     * 个人「待处理」：本人在当前学期里还没填实际授课日期的记录
     * 这类课时无法进入「按授课日期/月份」口径，适合单独提醒补全
     */
    public function todo()
    {
        $this->requireLogin();
        $uid    = $this->user->id;
        $termId = intval($this->input('term_id', 0));
        if (!$termId) {
            $term = Term::current();
            $termId = $term ? $term->id : 0;
        }

        $scope = ['teacher_id' => $uid, 'term_id' => $termId];

        // 独立查询构造：count 与 list 各用一份 builder，避免 count() 污染后续 select
        $mkWhere = function () use ($scope) {
            return LessonModel::scopeQuery($scope)->whereNull('teach_date');
        };
        $count = (int)$mkWhere()->count();
        $rows  = [];
        if ($count) {
            foreach ($mkWhere()->order('week asc, weekday asc, section asc')->limit(20)->select() as $r) {
                $rows[] = $r->toFullArray();
            }
        }
        return $this->ok(['count' => $count, 'list' => $rows]);
    }

    /**
     * 管理员：月度课酬对账表（教师 × 月份 交叉）
     */
    public function reconcile()
    {
        $this->requireAdmin();
        $termId = intval($this->input('term_id', 0));
        $deptId = intval($this->input('department_id', 0));
        return $this->ok(Stats::reconcileByMonth($termId, $deptId));
    }

    /**
     * 个人总览（可按学期 / 月份收窄）
     */
    public function overview()
    {
        $this->requireLogin();
        $termId = intval($this->input('term_id', 0));
        $month  = trim($this->input('month', ''));
        return $this->ok(Stats::overview($this->user->id, $termId, $month));
    }

    /**
     * 个人逐月统计
     */
    public function byMonth()
    {
        $this->requireLogin();
        return $this->ok(Stats::byMonth($this->user->id, intval($this->input('term_id', 0))));
    }

    /**
     * 个人逐周统计
     */
    public function byWeek()
    {
        $this->requireLogin();
        return $this->ok(Stats::byWeek($this->user->id, intval($this->input('term_id', 0))));
    }

    /**
     * 管理员：全校看板
     */
    public function school()
    {
        $this->requireAdmin();
        $termId = intval($this->input('term_id', 0));
        $deptId = intval($this->input('department_id', 0));

        return $this->ok([
            'overview'      => Stats::schoolOverview($termId),
            'by_teacher'    => Stats::byTeacher($termId, $deptId),
            'by_department' => Stats::byDepartment($termId),
            'by_month'      => Stats::byMonth(0, $termId),
            'by_type'       => $this->typeBreakdown($termId, $deptId),
        ]);
    }

    /**
     * 全校上课类型拆分（常规 / 补课 / 调课 / 实训）
     */
    private function typeBreakdown($termId, $deptId)
    {
        $ids   = [];
        $scope = [];
        if ($termId) $scope['term_id'] = $termId;
        if ($deptId) {
            $ids = \app\common\model\Teacher::where('department_id', $deptId)->column('id');
            if (empty($ids)) return [];
        }

        $q = LessonModel::scopeQuery($scope);
        if ($deptId) {
            $q->where('teacher_id', 'in', $ids);
        }

        $rows = $q->field('type, COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt')
            ->group('type')->select();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'type'    => $r->type,
                'text'    => isset(LessonModel::TYPES[$r->type]) ? LessonModel::TYPES[$r->type] : $r->type,
                'periods' => (float)$r->periods,
                'amount'  => (float)$r->amount,
                'cnt'     => (int)$r->cnt,
            ];
        }
        return $out;
    }
}
