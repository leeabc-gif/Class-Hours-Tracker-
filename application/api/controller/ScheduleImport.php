<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Lesson;
use app\common\model\Term;
use app\common\service\ScheduleParser;

/**
 * 外部课表导入（CSV / Excel）
 *
 * 流程：
 *   1) preview  上传文件 → 服务端解析归一化 → 返回预览行（含错误/缺字段标记）
 *   2) import   前端把预览行（剔除错误行后）发回 → 服务端按当前学期写入 ks_lesson，
 *               来源记为 source='import'；与已有「同教师+学期+周+星期+节次」冲突自动跳过
 *
 * 教师只能导入自己的课表；管理员可指定 teacher_id 替某教师导入。
 */
class ScheduleImport extends Base
{
    public function preview()
    {
        $this->requireLogin();
        $file = $this->request->file('file');
        if (empty($file) || !method_exists($file, 'isValid') || !$file->isValid()) {
            return $this->fail('未收到文件，或文件上传失败（请检查服务器 upload_max_filesize）');
        }

        $name = $file->getInfo('name');
        $res  = ScheduleParser::parseFile($file->getRealPath(), (string)$name);
        if (!$res['ok']) {
            return $this->fail($res['error'] ?: '解析失败');
        }
        if ($res['stats']['total'] <= 0) {
            return $this->fail('文件中没有可解析的数据行');
        }

        return $this->ok([
            'headers' => $res['headers'],
            'rows'    => $res['rows'],
            'stats'   => $res['stats'],
        ], '解析完成，请核对后导入');
    }

    /**
     * 确认导入
     * 入参（JSON）：
     *   term_id        目标学期
     *   rows[]         预览行（course_name/classes/week/(teach_date)/weekday/section/periods/price/type/remark）
     *   default_price  缺省单价（行未给单价时填）
     *   default_type   缺省类型（行未给类型时填）
     *   skip_conflict  是否跳过冲突（默认 true）
     *   teacher_id     管理员替某教师导入时填
     */
    public function import()
    {
        $this->requireLogin();
        $data = $this->jsonInput();

        $termId = intval(isset($data['term_id']) ? $data['term_id'] : 0);
        $term   = Term::get($termId);
        if (!$term) {
            return $this->fail('请选择有效的目标学期');
        }

        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        if (empty($rows)) {
            return $this->fail('没有可导入的行');
        }

        $teacherId = $this->user->isAdmin() && !empty($data['teacher_id'])
            ? intval($data['teacher_id'])
            : (int)$this->user->id;

        $defaultPrice = floatval(isset($data['default_price']) ? $data['default_price'] : 0);
        $defaultType  = in_array((string)(isset($data['default_type']) ? $data['default_type'] : ''), ['normal', 'makeup', 'swap', 'training'], true)
            ? (string)$data['default_type'] : 'normal';
        $skipConflict = !isset($data['skip_conflict']) || !empty($data['skip_conflict']);

        $created = [];
        $skippedConflict = [];
        $skippedInvalid  = [];

        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $courseName = trim((string)(isset($r['course_name']) ? $r['course_name'] : ''));
            if ($courseName === '') {
                $skippedInvalid[] = ['reason' => '缺少课程名称', 'raw' => $r];
                continue;
            }
            $weekday = intval(isset($r['weekday']) ? $r['weekday'] : 0);
            $section = intval(isset($r['section']) ? $r['section'] : 0);
            if ($weekday < 1 || $weekday > 7) {
                $skippedInvalid[] = ['reason' => '星期非法', 'raw' => $r];
                continue;
            }
            if ($section < 1 || $section > Lesson::SECTION_MAX) {
                $skippedInvalid[] = ['reason' => '节次非法', 'raw' => $r];
                continue;
            }
            $week = intval(isset($r['week']) ? $r['week'] : 0);
            $teachDate = trim((string)(isset($r['teach_date']) ? $r['teach_date'] : ''));
            if ($week <= 0) {
                if ($teachDate !== '') {
                    $week = Term::weekOf($term, $teachDate);
                }
                if ($week <= 0) {
                    $skippedInvalid[] = ['reason' => '缺少有效周次', 'raw' => $r];
                    continue;
                }
            }
            if ($week < (int)$term->start_week || $week > (int)$term->end_week) {
                $skippedInvalid[] = ['reason' => sprintf('周次应在第%d~%d周', (int)$term->start_week, (int)$term->end_week), 'raw' => $r];
                continue;
            }

            // 防重复
            if (Lesson::findDup($teacherId, $termId, $week, $weekday, $section)) {
                if ($skipConflict) {
                    $skippedConflict[] = $courseName . ' 第' . $week . '周 ' . Lesson::WEEKDAYS[$weekday] . ' ' . Lesson::SECTIONS[$section];
                    continue;
                }
            }

            $price = floatval(isset($r['price']) ? $r['price'] : 0);
            if ($price <= 0) $price = $defaultPrice;
            $periods = floatval(isset($r['periods']) ? $r['periods'] : 2);
            if ($periods <= 0) $periods = 2;
            $type = in_array((string)(isset($r['type']) ? $r['type'] : ''), ['normal', 'makeup', 'swap', 'training'], true)
                ? (string)$r['type'] : $defaultType;

            if ($teachDate === '' && $term->start_date) {
                $teachDate = Term::dateOf($term, $week, $weekday);
            }

            $build = Lesson::buildData([
                'term_id'     => $termId,
                'course_name' => $courseName,
                'classes'     => trim(isset($r['classes']) ? (string)$r['classes'] : ''),
                'week'        => $week,
                'weekday'     => $weekday,
                'section'     => $section,
                'teach_date'  => $teachDate,
                'periods'     => $periods,
                'price'       => $price,
                'type'        => $type,
                'remark'      => trim(isset($r['remark']) ? (string)$r['remark'] : ''),
                'source'      => 'import',
            ], $teacherId);

            $lesson = Lesson::create($build);
            if ($lesson && $lesson->id) {
                $created[] = (int)$lesson->id;
            }
        }

        $msg = '导入完成：新增 ' . count($created) . ' 条';
        if ($skippedConflict) $msg .= '，跳过 ' . count($skippedConflict) . ' 条冲突';
        if ($skippedInvalid)  $msg .= '，' . count($skippedInvalid) . ' 条无效被忽略';

        if ($created) {
            $this->log('导入课表', 'lesson', 0, $msg . '（学期#' . $termId . '）');
        }

        return $this->ok([
            'created'         => count($created),
            'skipped_conflict'=> $skippedConflict,
            'skipped_invalid' => $skippedInvalid,
        ], $msg);
    }

    /** 下载 CSV 模板 */
    public function template()
    {
        $csv = ScheduleParser::templateCsv();
        return \think\Response::create($csv, 'html', 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="kebiao_template.csv"',
        ]);
    }
}
