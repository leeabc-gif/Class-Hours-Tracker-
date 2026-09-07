<?php
namespace app\common\service;

use app\common\model\Lesson;
use app\common\model\Teacher;
use app\common\model\Department;
use think\Db;

/**
 * 统计服务
 *
 * 计算规则（全局唯一来源）：
 *   单条课时费用 = 上课节数 × 课程单价
 * 这里所有统计都直接对 amount 求和，amount 在写入时由 Lesson::buildData() 算好，
 * 避免「有的地方按 periods*price 现算、有的地方取 amount」导致口径不一致。
 */
class Stats
{
    /**
     * 个人总览：总课时、总课酬、记录数、按类型拆分
     */
    public static function overview($teacherId, $termId = 0, $month = '')
    {
        $scope = ['teacher_id' => $teacherId];
        if ($termId) $scope['term_id'] = $termId;
        if ($month)  $scope['month'] = $month;

        $q = Lesson::scopeQuery($scope);

        $row = $q->field('
            COUNT(*)                        AS cnt,
            COALESCE(SUM(periods),0)        AS periods,
            COALESCE(SUM(amount),0)         AS amount
        ')->find();

        // 按上课类型拆分
        $byType = [];
        $typeRows = Lesson::scopeQuery($scope)
            ->field('type, COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt')
            ->group('type')
            ->select();
        foreach ($typeRows as $r) {
            $byType[$r->type] = [
                'periods' => (float)$r->periods,
                'amount'  => (float)$r->amount,
                'cnt'     => (int)$r->cnt,
                'text'    => isset(Lesson::TYPES[$r->type]) ? Lesson::TYPES[$r->type] : $r->type,
            ];
        }

        // 按课程拆分
        $byCourse = [];
        $courseRows = Lesson::scopeQuery($scope)
            ->field('course_name, COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt')
            ->group('course_name')
            ->order('periods desc')
            ->select();
        foreach ($courseRows as $r) {
            $byCourse[] = [
                'course_name' => $r->course_name,
                'periods'     => (float)$r->periods,
                'amount'      => (float)$r->amount,
                'cnt'         => (int)$r->cnt,
            ];
        }

        return [
            'cnt'      => (int)$row['cnt'],
            'periods'  => (float)$row['periods'],
            'amount'   => (float)$row['amount'],
            'by_type'  => $byType,
            'by_course' => $byCourse,
        ];
    }

    /**
     * 逐月统计（用于月度柱状图 / 课酬趋势折线图）
     * @param int $teacherId 0 表示全部（管理员）
     */
    public static function byMonth($teacherId, $termId = 0)
    {
        $scope = [];
        if ($teacherId) $scope['teacher_id'] = $teacherId;
        if ($termId)    $scope['term_id'] = $termId;

        $rows = Lesson::scopeQuery($scope)
            ->where('teach_date', 'not null')
            ->field("
                DATE_FORMAT(teach_date,'%Y-%m') AS ym,
                COALESCE(SUM(periods),0)        AS periods,
                COALESCE(SUM(amount),0)         AS amount,
                COUNT(*)                        AS cnt
            ")
            ->group('ym')
            ->order('ym asc')
            ->select();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'month'   => $r['ym'],
                'periods' => (float)$r->periods,
                'amount'  => (float)$r->amount,
                'cnt'     => (int)$r->cnt,
            ];
        }
        return $out;
    }

    /**
     * 逐周统计（用于找课时峰值周）
     */
    public static function byWeek($teacherId, $termId = 0)
    {
        $scope = [];
        if ($teacherId) $scope['teacher_id'] = $teacherId;
        if ($termId)    $scope['term_id'] = $termId;

        $rows = Lesson::scopeQuery($scope)
            ->field('
                week,
                COALESCE(SUM(periods),0) AS periods,
                COALESCE(SUM(amount),0)  AS amount,
                COUNT(*)                 AS cnt
            ')
            ->group('week')
            ->order('week asc')
            ->select();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'week'    => (int)$r->week,
                'periods' => (float)$r->periods,
                'amount'  => (float)$r->amount,
                'cnt'     => (int)$r->cnt,
            ];
        }
        return $out;
    }

    /**
     * 管理员：按教师汇总
     */
    public static function byTeacher($termId = 0, $departmentId = 0)
    {
        $scope = [];
        if ($termId) $scope['term_id'] = $termId;

        $q = Lesson::scopeQuery($scope)
            ->field('
                teacher_id,
                COALESCE(SUM(periods),0) AS periods,
                COALESCE(SUM(amount),0)  AS amount,
                COUNT(*)                 AS cnt
            ')
            ->group('teacher_id');

        if ($departmentId) {
            $ids = Teacher::where('department_id', $departmentId)->column('id');
            if (empty($ids)) return [];
            $q->where('teacher_id', 'in', $ids);
        }

        $rows = $q->select();

        $out = [];
        foreach ($rows as $r) {
            $t = Teacher::get($r->teacher_id);
            if (!$t) continue;
            $out[] = [
                'teacher_id'    => (int)$r->teacher_id,
                'name'          => $t->name,
                'username'      => $t->username,
                'department'    => $t->departmentName(),
                'department_id' => (int)$t->department_id,
                'periods'       => (float)$r->periods,
                'amount'        => (float)$r->amount,
                'cnt'           => (int)$r->cnt,
            ];
        }
        // 按课酬降序
        usort($out, function ($a, $b) {
            return $b['amount'] <=> $a['amount'];
        });
        return $out;
    }

    /**
     * 管理员：按院系汇总
     */
    public static function byDepartment($termId = 0)
    {
        $byTeacher = self::byTeacher($termId);
        $depts     = Department::where('status', 1)->order('sort asc')->select();

        $map = [];
        foreach ($depts as $d) {
            $map[$d->id] = [
                'department_id'   => (int)$d->id,
                'department'      => $d->name,
                'teacher_count'   => 0,
                'periods'         => 0.0,
                'amount'          => 0.0,
                'cnt'             => 0,
            ];
        }

        foreach ($byTeacher as $r) {
            $did = $r['department_id'];
            if (!isset($map[$did])) {
                $map[$did] = [
                    'department_id' => $did,
                    'department'    => $r['department'],
                    'teacher_count' => 0,
                    'periods'       => 0.0,
                    'amount'        => 0.0,
                    'cnt'           => 0,
                ];
            }
            $map[$did]['teacher_count']++;
            $map[$did]['periods'] += $r['periods'];
            $map[$did]['amount']  += $r['amount'];
            $map[$did]['cnt']     += $r['cnt'];
        }
        return array_values($map);
    }

    /**
     * 管理员：全校总览
     */
    public static function schoolOverview($termId = 0)
    {
        $scope = [];
        if ($termId) $scope['term_id'] = $termId;
        $row = Lesson::scopeQuery($scope)
            ->field('
                COUNT(*)                 AS cnt,
                COALESCE(SUM(periods),0) AS periods,
                COALESCE(SUM(amount),0)  AS amount
            ')->find();

        return [
            'teacher_count' => Teacher::where('role', 'teacher')->count(),
            'active_count'  => Teacher::where('role', 'teacher')->where('status', 1)->count(),
            'lesson_count'  => (int)$row['cnt'],
            'periods'       => (float)$row['periods'],
            'amount'        => (float)$row['amount'],
        ];
    }

    /**
     * 管理员：月度课酬对账表（教师 × 月份 交叉）
     *
     * 只统计 teach_date 非空的课时（按授课日期归月）。
     * 返回结构：
     *   months:    ['2026-09','2026-10',...]  本学期出现的月份（升序）
     *   teachers:  [{ teacher_id, name, department, months:{'2026-09':{periods,amount,cnt},...}, total:{periods,amount,cnt} }]
     *   total:     学期合计 {periods, amount, cnt}
     */
    public static function reconcileByMonth($termId = 0, $departmentId = 0)
    {
        $scope = [];
        if ($termId) $scope['term_id'] = $termId;

        $q = Lesson::scopeQuery($scope)
            ->where('teach_date', 'not null')
            ->field("
                teacher_id,
                DATE_FORMAT(teach_date,'%Y-%m') AS ym,
                COALESCE(SUM(periods),0)        AS periods,
                COALESCE(SUM(amount),0)         AS amount,
                COUNT(*)                        AS cnt
            ")
            ->group('teacher_id, ym')
            ->order('teacher_id asc, ym asc')
            ->select();

        if ($departmentId) {
            $ids = Teacher::where('department_id', $departmentId)->column('id');
            if (empty($ids)) {
                return ['months' => [], 'teachers' => [], 'total' => ['periods' => 0.0, 'amount' => 0.0, 'cnt' => 0]];
            }
            // 上面已 select 完，这里在结果上按院系过滤
            $idSet = array_flip($ids);
            $q = array_filter($q, function ($r) use ($idSet) { return isset($idSet[$r->teacher_id]); });
        }

        $months = [];
        $byTeacher = [];
        foreach ($q as $r) {
            $tid = (int)$r->teacher_id;
            if (!isset($byTeacher[$tid])) {
                $t = Teacher::get($tid);
                if (!$t) continue;
                $byTeacher[$tid] = [
                    'teacher_id' => $tid,
                    'name'       => $t->name,
                    'department' => $t->departmentName(),
                    'months'     => [],
                    'total'      => ['periods' => 0.0, 'amount' => 0.0, 'cnt' => 0],
                ];
            }
            $ym = $r['ym'];
            if (!in_array($ym, $months, true)) $months[] = $ym;
            $byTeacher[$tid]['months'][$ym] = [
                'periods' => (float)$r->periods,
                'amount'  => (float)$r->amount,
                'cnt'     => (int)$r->cnt,
            ];
            $byTeacher[$tid]['total']['periods'] += (float)$r->periods;
            $byTeacher[$tid]['total']['amount']  += (float)$r->amount;
            $byTeacher[$tid]['total']['cnt']     += (int)$r->cnt;
        }

        sort($months);

        $total = ['periods' => 0.0, 'amount' => 0.0, 'cnt' => 0];
        foreach ($byTeacher as $t) {
            $total['periods'] += $t['total']['periods'];
            $total['amount']  += $t['total']['amount'];
            $total['cnt']     += $t['total']['cnt'];
        }

        // 按学期合计课酬降序
        $teachers = array_values($byTeacher);
        usort($teachers, function ($a, $b) {
            return $b['total']['amount'] <=> $a['total']['amount'];
        });

        return ['months' => $months, 'teachers' => $teachers, 'total' => $total];
    }

    /**
     * 金额转人民币大写（用于课时证明）
     */
    public static function rmbUpper($amount)
    {
        $amount   = round(floatval($amount), 2);
        if ($amount == 0) return '零元整';
        if ($amount < 0)  return '负' . self::rmbUpper(abs($amount));

        $digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        $units  = ['', '拾', '佰', '仟'];
        $bigs   = ['', '万', '亿', '万亿'];

        $intPart = floor($amount);
        $decPart = (int)round(($amount - $intPart) * 100);

        $str = (string)$intPart;
        $res = '';
        $len = strlen($str);
        $zero = false;
        // 从高位到低位，四位一组
        $groups = [];
        while ($len > 0) {
            $n = min(4, $len);
            $groups[] = substr($str, $len - $n, $n);
            $len -= $n;
        }
        $groups = array_reverse($groups);
        $gCount = count($groups);

        foreach ($groups as $gi => $g) {
            $gStr   = '';
            $gZero  = false;
            $gLen   = strlen($g);
            $isLastGroup = ($gi === $gCount - 1);
            for ($i = 0; $i < $gLen; $i++) {
                $d = (int)$g[$i];
                $pos = $gLen - $i - 1;
                if ($d === 0) {
                    $gZero = true;
                } else {
                    if ($gZero && $gStr !== '') $gStr .= $digits[0];
                    $gStr .= $digits[$d] . $units[$pos];
                    $gZero = false;
                }
            }
            // 组与组之间的「零」处理：本组不足四位且非最后一组，需要补零
            if ($gStr !== '') {
                $res .= $gStr . $bigs[$gCount - 1 - $gi];
            } elseif ($gi < $gCount - 1 && $res !== '' && substr($res, -3) !== '零') {
                // 整组为0，用零占位，但要避免重复
                if (mb_substr($res, -1, 1, 'UTF-8') !== '零') $res .= '零';
            }
        }
        $res = $res === '' ? '' : $res . '元';

        // 角分
        if ($decPart === 0) {
            $res .= '整';
        } else {
            $jiao = intdiv($decPart, 10);
            $fen  = $decPart % 10;
            if ($jiao > 0) $res .= $digits[$jiao] . '角';
            elseif ($fen > 0 && $res !== '') $res .= '零';
            if ($fen > 0)  $res .= $digits[$fen] . '分';
            else $res .= '整';
        }
        return $res;
    }
}
