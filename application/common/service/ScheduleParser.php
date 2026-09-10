<?php
namespace app\common\service;

use app\common\model\Lesson;

/**
 * 外部课表解析（CSV / Excel .xlsx）
 *
 * 设计要点：
 *   - 不依赖 PhpSpreadsheet，XLSX 用原生 ZipArchive + SimpleXML 解析，零额外包
 *   - CSV 兼容 GBK/UTF-8（无 BOM / 有 BOM），自动识别分隔符 , ; \t
 *   - 表头用「中文别名 + 英文别名」双匹配，列顺序无所谓
 *   - 只做字段归一化与单条行的合法性初判，真实的写入/防重由 ScheduleImport 控制器负责
 *
 * 行归一化字段：course_name, classes, week, weekday, section, teach_date,
 *              periods, price, type, remark, error
 */
class ScheduleParser
{
    const MAX_ROWS = 1000;

    /** 表头别名 → 内部字段 */
    private static $HEADER_ALIAS = [
        'course_name' => ['课程', '课程名称', '课名', '科目', 'course', 'course_name', 'subject', 'lesson'],
        'classes'     => ['班级', '教学班', '行政班', '上课班级', '班级名称', 'class', 'classes', 'klass'],
        'week'        => ['周次', '周', '教学周', 'week', 'week_no', 'weekno'],
        'weekday'     => ['星期', '周几', '星期几', '礼拜', '星期x', 'day', 'weekday'],
        'section'     => ['节次', '节', '时间段', '上课节次', '节段', 'section', 'period', 'periods'],
        'teach_date'  => ['日期', '上课日期', '授课日期', '具体日期', 'date', 'teach_date', 'lesson_date'],
        'periods'     => ['课时', '节数', '学时', 'periods', 'hours', 'period'],
        'price'       => ['单价', '课酬', '课时费', '单价元', 'price', 'rate', 'fee'],
        'type'        => ['类型', '上课类型', '课型', 'type'],
        'remark'      => ['备注', '说明', '备注说明', 'remark', 'note', 'memo'],
    ];

    /**
     * 解析文件
     * @param string $path 服务器临时文件路径
     * @param string $origName 原始文件名（用于判断 xlsx）
     * @return array ['ok'=>bool,'error'=>'','headers'=>[],'rows'=>[],'stats'=>[]]
     */
    public static function parseFile($path, $origName)
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => '文件不存在', 'headers' => [], 'rows' => [], 'stats' => []];
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $head = file_get_contents($path, false, null, 0, 2);
        $isXlsx = ($ext === 'xlsx') || ($head === 'PK');

        if ($isXlsx) {
            $matrix = self::parseXlsx($path);
        } else {
            $matrix = self::parseCsv($path);
        }
        if ($matrix === null) {
            return ['ok' => false, 'error' => '无法解析该文件，请确认是 .csv 或 .xlsx 格式', 'headers' => [], 'rows' => [], 'stats' => []];
        }

        return self::matrixToRows($matrix);
    }

    // -------------------- XLSX --------------------

    private static function parseXlsx($path)
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $shared = [];
        if (($s = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $shared = self::readSharedStrings($s);
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            // 有的导出 sheet 命名带序号
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml') ?: $zip->getFromName('xl/worksheets/sheet2.xml');
        }
        $zip->close();
        if ($sheet === false) {
            return null;
        }
        return self::readSheet($sheet, $shared);
    }

    private static function readSharedStrings($xml)
    {
        $out = [];
        $sx = @simplexml_load_string($xml);
        if (!$sx) return $out;
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        foreach ($sx->children($ns)->si as $si) {
            $out[] = self::cellText($si, $ns);
        }
        return $out;
    }

    /** 从一个 <c>/<si> 节点取文本（兼容 <t> 与富文本 <r><t>） */
    private static function cellText($node, $ns)
    {
        $ch = $node->children($ns);
        $txt = '';
        if (count($ch->t)) {
            $txt = (string)$ch->t;
        } else {
            foreach ($ch->r as $r) {
                $rt = $r->children($ns);
                if (count($rt->t)) $txt .= (string)$rt->t;
            }
        }
        return $txt;
    }

    private static function readSheet($xml, array $shared)
    {
        $sx = @simplexml_load_string($xml);
        if (!$sx) return null;
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = [];
        foreach ($sx->children($ns)->sheetData->children($ns)->row as $row) {
            $cells = [];
            $col = 0;
            foreach ($row->children($ns)->c as $c) {
                $ca  = $c->attributes();
                $ref = (string)$ca['r']; // 如 A1（用 attributes() 取，避免无前缀属性在命名空间下读空）
                if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                    $col = self::colIndex($m[1]);
                }
                $t = (string)$ca['t'];
                $val = '';
                if ($t === 's') {
                    $idx = (int)(string)$c->children($ns)->v;
                    $val = isset($shared[$idx]) ? $shared[$idx] : '';
                } elseif ($t === 'inlineStr') {
                    $val = self::cellText($c->children($ns)->is, $ns);
                } else {
                    $val = (string)$c->children($ns)->v;
                }
                $cells[$col] = $val;
                $col++;
            }
            ksort($cells);
            $rows[] = array_values($cells);
        }
        return $rows;
    }

    /** 列字母 → 0 基序号（A=0, Z=25, AA=26） */
    private static function colIndex($letters)
    {
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $n - 1;
    }

    // -------------------- CSV --------------------

    private static function parseCsv($path)
    {
        $raw = file_get_contents($path);
        if ($raw === false) return null;
        // 去 BOM
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }
        // 编码：优先当 UTF-8；含非法序列则按 GBK 转
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'GBK');
        }

        // 探测分隔符
        $firstLine = strtok($raw, "\n");
        $delim = ',';
        foreach ([',', ';', "\t"] as $d) {
            if (substr_count($firstLine, $d) > substr_count($firstLine, $delim)) {
                $delim = $d;
            }
        }

        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($line = fgetcsv($fh, 0, $delim)) !== false) {
            if ($line === [null]) continue;
            $rows[] = $line;
        }
        fclose($fh);
        return $rows;
    }

    // -------------------- 归一化 --------------------

    private static function matrixToRows(array $matrix)
    {
        if (empty($matrix)) {
            return ['ok' => true, 'error' => '', 'headers' => [], 'rows' => [], 'stats' => ['total' => 0, 'parsed' => 0, 'invalid' => 0]];
        }
        // 表头行
        $header = array_map('trim', $matrix[0]);
        $fieldOfCol = [];
        foreach ($header as $i => $h) {
            $f = self::matchHeader($h);
            if ($f) $fieldOfCol[$i] = $f;
        }

        if (empty($fieldOfCol)) {
            return ['ok' => false, 'error' => '未识别到有效表头，请使用包含「课程/班级/周次/星期/节次」等列的表格', 'headers' => $header, 'rows' => [], 'stats' => []];
        }

        $mappedHeaders = [];
        foreach ($header as $i => $h) {
            $mappedHeaders[$i] = isset($fieldOfCol[$i]) ? $fieldOfCol[$i] : ('（忽略：' . $h . '）');
        }

        $rows = [];
        $parsed = 0;
        $invalid = 0;
        $data = array_slice($matrix, 1);
        foreach ($data as $line) {
            if (empty($line) || (count($line) === 1 && trim($line[0]) === '')) continue;
            if (count($rows) >= self::MAX_ROWS) break;

            $rec = array_fill_keys(array_keys(self::$HEADER_ALIAS), '');
            foreach ($fieldOfCol as $i => $f) {
                $rec[$f] = isset($line[$i]) ? trim((string)$line[$i]) : '';
            }
            $row = self::normalizeRow($rec);
            if ($row['error'] === '') $parsed++; else $invalid++;
            $rows[] = $row;
        }

        return [
            'ok'      => true,
            'error'   => '',
            'headers' => $mappedHeaders,
            'rows'    => $rows,
            'stats'   => ['total' => count($rows), 'parsed' => $parsed, 'invalid' => $invalid],
        ];
    }

    private static function matchHeader($h)
    {
        $h = trim((string)$h);
        if ($h === '') return '';
        $hl = mb_strtolower($h, 'UTF-8');
        // 先把括号和多余字去掉便于匹配（如「上课日期(必填)」）
        $hbase = preg_replace('/[\(（][^\)）]*[\)）]/u', '', $hl);
        foreach (self::$HEADER_ALIAS as $field => $aliases) {
            foreach ($aliases as $a) {
                $al = mb_strtolower($a, 'UTF-8');
                if ($hl === $al || $hbase === $al || strpos($hl, $al) !== false) {
                    return $field;
                }
            }
        }
        return '';
    }

    /** 把一条原始记录归一化；weekday/section 解析失败则记 error */
    private static function normalizeRow(array $rec)
    {
        $weekday = self::parseWeekday($rec['weekday']);
        $section = self::parseSection($rec['section']);
        $week    = intval($rec['week']);
        $periods = self::parseNumber($rec['periods'], 2);
        $price   = self::parseNumber($rec['price'], 0);
        $type    = in_array($rec['type'], ['normal', 'makeup', 'swap', 'training'], true)
            ? $rec['type']
            : (self::mapType($rec['type']) ?: 'normal');
        $teachDate = self::parseDate($rec['teach_date']);

        $err = '';
        if ($rec['course_name'] === '') {
            $err = '缺少课程名称';
        } elseif ($weekday === 0) {
            $err = '星期无法识别（请填 周一~周日 或 1~7）';
        } elseif ($section === 0) {
            $err = '节次无法识别（请填 1-2节 / 3-4节 … 或 1-8节 等）';
        } elseif ($week <= 0 && $teachDate === '') {
            $err = '缺少周次或上课日期';
        }

        return [
            'course_name' => $rec['course_name'],
            'classes'     => $rec['classes'],
            'week'        => $week,
            'weekday'     => $weekday,
            'weekday_text'=> $weekday ? Lesson::WEEKDAYS[$weekday] : '',
            'section'     => $section,
            'section_text'=> $section ? Lesson::SECTIONS[$section] : '',
            'teach_date'  => $teachDate,
            'periods'     => $periods,
            'price'       => $price,
            'type'        => $type,
            'remark'      => $rec['remark'],
            'error'       => $err,
        ];
    }

    /** 星期文本 → 1..7 */
    private static function parseWeekday($s)
    {
        $s = trim((string)$s);
        if ($s === '') return 0;
        $map = [
            '1' => 1, '一' => 1, '周一' => 1, '星期一' => 1, '礼拜一' => 1, 'mon' => 1, 'monday' => 1,
            '2' => 2, '二' => 2, '周二' => 2, '星期二' => 2, '礼拜二' => 2, 'tue' => 2, 'tuesday' => 2,
            '3' => 3, '三' => 3, '周三' => 3, '星期三' => 3, '礼拜三' => 3, 'wed' => 3, 'wednesday' => 3,
            '4' => 4, '四' => 4, '周四' => 4, '星期四' => 4, '礼拜四' => 4, 'thu' => 4, 'thursday' => 4,
            '5' => 5, '五' => 5, '周五' => 5, '星期五' => 5, '礼拜五' => 5, 'fri' => 5, 'friday' => 5,
            '6' => 6, '六' => 6, '周六' => 6, '星期六' => 6, '礼拜六' => 6, 'sat' => 6, 'saturday' => 6,
            '7' => 7, '日' => 7, '天' => 7, '周日' => 7, '星期日' => 7, '周天' => 7, '礼拜日' => 7, '礼拜天' => 7, 'sun' => 7, 'sunday' => 7,
        ];
        $k = mb_strtolower($s, 'UTF-8');
        if (isset($map[$k])) return $map[$k];
        // 取数字
        if (preg_match('/([0-9])/', $s, $m)) {
            $n = intval($m[1]);
            if ($n >= 1 && $n <= 7) return $n;
        }
        return 0;
    }

    /**
     * 节次文本 → section 值（1..10）
     * 支持：1-2节 / 1-2 / 1~2 / 第1-2节 / 1-2(上午) / 1-4 / 5-8 / 1-8 等；
     *       单数字按起点归并到对应跨节段。
     */
    private static function parseSection($s)
    {
        $s = trim((string)$s);
        if ($s === '') return 0;
        $s = preg_replace('/[第\(（].*?[\)）]|节|节次|上课|时间段|时段/u', '', $s);
        $s = trim($s);

        if (preg_match('/^(\d+)\s*[-~－—至]\s*(\d+)$/', $s, $m)) {
            $key = intval($m[1]) . '-' . intval($m[2]);
            $map = [
                '1-2' => 1, '3-4' => 2, '5-6' => 3, '7-8' => 4, '9-10' => 5, '11-12' => 6,
                '1-4' => 7, '2-4' => 8, '5-8' => 9, '1-8' => 10,
            ];
            if (isset($map[$key])) return $map[$key];
            // 未知跨节组合：按起点近似
            return self::sectionByStart(intval($m[1]));
        }
        if (preg_match('/^(\d+)$/', $s, $m)) {
            return self::sectionByStart(intval($m[1]));
        }
        // 含"上午/下午"但没数字的情况放弃
        return 0;
    }

    /** 单节起点 → 跨节段（1,2→1-2节；3,4→3-4节 …） */
    private static function sectionByStart($n)
    {
        if ($n < 1) return 0;
        if ($n <= 2) return 1;
        if ($n <= 4) return 2;
        if ($n <= 6) return 3;
        if ($n <= 8) return 4;
        if ($n <= 10) return 5;
        if ($n <= 12) return 6;
        return 0;
    }

    private static function parseNumber($s, $default)
    {
        $s = trim((string)$s);
        if ($s === '' || !is_numeric($s)) return $default;
        return floatval($s);
    }

    private static function mapType($s)
    {
        $s = trim((string)$s);
        if ($s === '') return '';
        $map = [
            '常规' => 'normal', '常规课' => 'normal', '普通' => 'normal', 'normal' => 'normal',
            '补课' => 'makeup', '补' => 'makeup', 'makeup' => 'makeup',
            '调课' => 'swap', '调' => 'swap', 'swap' => 'swap',
            '实训' => 'training', '实训课' => 'training', 'training' => 'training',
        ];
        return isset($map[mb_strtolower($s, 'UTF-8')]) ? $map[mb_strtolower($s, 'UTF-8')] : '';
    }

    /** 日期文本 → YYYY-MM-DD；容错 2026/9/1、2026.9.1 等 */
    private static function parseDate($s)
    {
        $s = trim((string)$s);
        if ($s === '') return '';
        $s = preg_replace('/[年月]/', '-', $s);
        $s = str_replace(['/', '.', '日', '号'], ['-', '-', '', ''], $s);
        $s = trim($s, '- ');
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            $y = intval($m[1]); $mo = intval($m[2]); $d = intval($m[3]);
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        // 尝试 strtotime
        $ts = strtotime($s);
        if ($ts !== false) return date('Y-m-d', $ts);
        return '';
    }

    /** 下载用的 CSV 表头模板 */
    public static function templateCsv()
    {
        return "课程名称,班级,周次,星期,节次,上课日期,课时,单价,类型,备注\n"
            . "工业机器人基础,23机器人1班,1,周一,1-2节,2026-09-01,2,80,normal,理论课\n"
            . "PLC应用,23机器人1班,1,周三,5-8节,,4,100,training,实训\n";
    }
}
