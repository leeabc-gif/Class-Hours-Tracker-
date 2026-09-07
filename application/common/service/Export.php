<?php
namespace app\common\service;

use app\common\model\Lesson;
use app\common\model\Teacher;
use app\common\model\Term;

/**
 * 导出服务
 *
 * 统一生成 CSV 文本，带 UTF-8 BOM，Excel / WPS 双击打开不乱码。
 * 之所以用 CSV 而不是 xlsx：不依赖任何第三方库（PhpSpreadsheet 体积大、
 * 在 PHP 7.4 + 无 composer 网络的环境下经常装不上），CSV 在课时明细
 * 这种纯表格场景下完全够用，且行数上没有限制。
 */
class Export
{
    /**
     * 教师个人课时明细
     *
     * @param int   $teacherId 教师ID（数据隔离：只导出本人）
     * @param array $scope     筛选条件 term_id / month / course_id / type ...
     * @return array ['header'=>[], 'rows'=>[]]
     */
    public static function teacherRows($teacherId, $scope = [])
    {
        $scope['teacher_id'] = $teacherId;
        $rows = Lesson::scopeQuery($scope)
            ->order('week asc, weekday asc, section asc')
            ->select();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                '第' . $r->week . '周',
                $r->weekdayText(),
                $r->sectionText(),
                $r->teach_date ?: '',
                $r->course_name,
                $r->classes,
                $r->typeText(),
                (float)$r->periods,
                number_format((float)$r->price, 2, '.', ''),
                number_format((float)$r->amount, 2, '.', ''),
                $r->sourceText(),
                $r->remark,
            ];
        }

        return [
            'header' => ['周次', '星期', '节次', '授课日期', '课程名称', '授课班级',
                         '上课类型', '上课节数', '单价(元)', '金额(元)', '来源', '备注'],
            'rows' => $list,
        ];
    }

    /**
     * 管理员：全校课时明细（含教师姓名与院系）
     */
    public static function schoolDetailRows($scope = [])
    {
        $rows = Lesson::scopeQuery($scope)
            ->order('teacher_id asc, week asc, weekday asc, section asc')
            ->select();

        // 一次性把涉及的教师取出来，避免逐行查库（N+1）
        $teachers = self::teacherMap();

        $list = [];
        foreach ($rows as $r) {
            $t = isset($teachers[$r->teacher_id]) ? $teachers[$r->teacher_id] : null;
            $list[] = [
                $t ? $t['name'] : '（已删除）',
                $t ? $t['department'] : '—',
                '第' . $r->week . '周',
                $r->weekdayText(),
                $r->sectionText(),
                $r->teach_date ?: '',
                $r->course_name,
                $r->classes,
                $r->typeText(),
                (float)$r->periods,
                number_format((float)$r->price, 2, '.', ''),
                number_format((float)$r->amount, 2, '.', ''),
                $r->remark,
            ];
        }

        return [
            'header' => ['教师', '院系', '周次', '星期', '节次', '授课日期', '课程名称',
                         '授课班级', '上课类型', '上课节数', '单价(元)', '金额(元)', '备注'],
            'rows' => $list,
        ];
    }

    /**
     * 管理员：全校教师课时工资汇总表
     * 一行一位教师，横向拆出各上课类型课时，方便财务直接取数
     */
    public static function schoolSummaryRows($termId = 0, $departmentId = 0)
    {
        $byTeacher = Stats::byTeacher($termId, $departmentId);

        // 一次性取出每位教师的类型拆分，避免循环里重复查库
        $typeRows = Lesson::scopeQuery($termId ? ['term_id' => $termId] : [])
            ->field('teacher_id, type, COALESCE(SUM(periods),0) AS periods')
            ->group('teacher_id, type')
            ->select();

        $typeMap = [];
        foreach ($typeRows as $r) {
            $typeMap[$r->teacher_id . '_' . $r->type] = (float)$r->periods;
        }

        $header = ['工号', '教师姓名', '院系', '岗位', '记录数', '总课时',
                   '常规课', '补课', '调课', '实训课', '课酬合计(元)'];

        $list = [];
        foreach ($byTeacher as $r) {
            if ($departmentId && $r['department_id'] != $departmentId) {
                continue;
            }
            $tid = $r['teacher_id'];
            $list[] = [
                $r['username'],
                $r['name'],
                $r['department'],
                self::positionOf($tid),
                $r['cnt'],
                (float)$r['periods'],
                self::pick($typeMap, $tid, 'normal'),
                self::pick($typeMap, $tid, 'makeup'),
                self::pick($typeMap, $tid, 'swap'),
                self::pick($typeMap, $tid, 'training'),
                number_format((float)$r['amount'], 2, '.', ''),
            ];
        }

        // 合计行
        $sum = ['', '合计', '', '', 0, 0, 0, 0, 0, 0, 0];
        foreach ($list as $row) {
            for ($i = 4; $i <= 10; $i++) {
                $sum[$i] += (float)$row[$i];
            }
        }
        $sum[10] = number_format($sum[10], 2, '.', '');
        $list[] = $sum;

        return ['header' => $header, 'rows' => $list];
    }

    /**
     * 管理员：院系汇总表
     */
    public static function departmentRows($termId = 0)
    {
        $rows = Stats::byDepartment($termId);
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                $r['department'],
                $r['teacher_count'],
                $r['cnt'],
                (float)$r['periods'],
                number_format((float)$r['amount'], 2, '.', ''),
            ];
        }
        return [
            'header' => ['院系', '教师人数', '记录数', '总课时', '课酬合计(元)'],
            'rows' => $list,
        ];
    }

    /**
     * 管理员：月度课酬对账表（教师 × 月份 交叉），CSV 版
     * 行=教师，列为各月的(课时/课酬)+学期合计
     */
    public static function reconcileRows($termId = 0, $departmentId = 0)
    {
        $data = Stats::reconcileByMonth($termId, $departmentId);
        $months = $data['months'];
        $teachers = $data['teachers'];
        $total = $data['total'];

        $header = ['教师', '院系'];
        foreach ($months as $m) {
            $header[] = $m . ' 课时';
            $header[] = $m . ' 课酬';
        }
        $header[] = '学期合计课时';
        $header[] = '学期合计课酬';

        $list = [];
        foreach ($teachers as $t) {
            $row = [$t['name'], $t['department']];
            foreach ($months as $m) {
                $c = isset($t['months'][$m]) ? $t['months'][$m] : null;
                $row[] = $c ? (float)$c['periods'] : 0;
                $row[] = $c ? number_format((float)$c['amount'], 2, '.', '') : '0.00';
            }
            $row[] = (float)$t['total']['periods'];
            $row[] = number_format((float)$t['total']['amount'], 2, '.', '');
            $list[] = $row;
        }

        // 合计行
        $sum = ['合计', count($teachers) . ' 人'];
        foreach ($months as $m) {
            $p = 0; $a = 0.0;
            foreach ($teachers as $t) {
                if (isset($t['months'][$m])) { $p += $t['months'][$m]['periods']; $a += $t['months'][$m]['amount']; }
            }
            $sum[] = $p;
            $sum[] = number_format($a, 2, '.', '');
        }
        $sum[] = (float)$total['periods'];
        $sum[] = number_format((float)$total['amount'], 2, '.', '');
        $list[] = $sum;

        return ['header' => $header, 'rows' => $list];
    }

    /**
     * 把 ['header'=>[], 'rows'=>[]] 转成 CSV 字符串
     */
    public static function toCsv($header, $rows)
    {
        $out = chr(0xEF) . chr(0xBB) . chr(0xBF); // UTF-8 BOM
        $out .= self::csvLine($header);
        foreach ($rows as $r) {
            $out .= self::csvLine($r);
        }
        return $out;
    }

    /**
     * 单行 CSV：整体加引号，内部引号转义，防公式注入
     */
    private static function csvLine($cols)
    {
        $cells = [];
        foreach ($cols as $v) {
            $v = (string)$v;
            // 以 = + - @ 开头的单元格会被 Excel 当公式执行，前面补一个单引号
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $v = "'" . $v;
            }
            $cells[] = '"' . str_replace('"', '""', $v) . '"';
        }
        return implode(',', $cells) . "\r\n";
    }

    /**
     * 教师ID → [name, department, position] 映射
     */
    private static function teacherMap()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (Teacher::select() as $t) {
            $map[$t->id] = [
                'name'       => $t->getData('name'),
                'department' => $t->departmentName(),
                'position'   => $t->position,
            ];
        }
        return $map;
    }

    private static function positionOf($teacherId)
    {
        $map = self::teacherMap();
        return isset($map[$teacherId]) ? $map[$teacherId]['position'] : '—';
    }

    private static function pick($typeMap, $teacherId, $type)
    {
        $key = $teacherId . '_' . $type;
        return isset($typeMap[$key]) ? (float)$typeMap[$key] : 0;
    }
}
