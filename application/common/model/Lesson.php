<?php
namespace app\common\model;

use think\Model;
use think\Db;

/**
 * 课时记录（核心表）
 *
 * 节次映射 section：
 *   1=1-4节  2=5-8节  3=1-2节  4=2-4节  5=5-6节  6=5-8节
 * 上课类型 type：
 *   normal=常规课 makeup=补课 swap=调课 training=实训课
 */
class Lesson extends Model
{
    protected $table    = 'ks_lesson';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 节次选项（下标即 section 值） */
    const SECTIONS = [
        1 => '1-4节',
        2 => '5-8节',
        3 => '1-2节',
        4 => '2-4节',
        5 => '5-6节',
        6 => '5-8节',
    ];

    /** 星期选项 */
    const WEEKDAYS = [
        1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四',
        5 => '周五', 6 => '周六', 7 => '周日',
    ];

    /** 上课类型 */
    const TYPES = [
        'normal'   => '常规课',
        'makeup'   => '补课',
        'swap'     => '调课',
        'training' => '实训课',
    ];

    /** 数据来源 */
    const SOURCES = [
        'manual' => '手动',
        'batch'  => '批量生成',
        'ai'     => 'AI解析',
        'scan'   => '扫码',
    ];

    // -------------------- 读取辅助 --------------------

    public function sectionText()
    {
        return isset(self::SECTIONS[$this->section]) ? self::SECTIONS[$this->section] : '—';
    }

    public function weekdayText()
    {
        return isset(self::WEEKDAYS[$this->weekday]) ? self::WEEKDAYS[$this->weekday] : '—';
    }

    /**
     * 上课类型文案
     *
     * 注意：必须用 getData('type') 而不是 $this->type。
     * think\model\concern\Attribute 里声明了 protected $type = []（字段类型转换配置），
     * 在模型子类内部访问 $this->type 会直接命中这个框架属性，
     * 拿到的是空数组而不是数据库里的 normal/makeup 等类型值。
     */
    public function typeText()
    {
        $t = $this->getData('type');
        return isset(self::TYPES[$t]) ? self::TYPES[$t] : $t;
    }

    public function sourceText()
    {
        return isset(self::SOURCES[$this->source]) ? self::SOURCES[$this->source] : $this->source;
    }

    /**
     * 授课教师姓名
     * 同样用 getData() 读取，原因见 Teacher::departmentName() 的注释
     */
    public function teacherName()
    {
        $t = Teacher::get($this->teacher_id);
        return $t ? $t->getData('name') : '—';
    }

    /**
     * 输出给前端的数组（含派生文本字段）
     */
    public function toFullArray()
    {
        return [
            'id'          => $this->id,
            'teacher_id'  => $this->teacher_id,
            'term_id'     => $this->term_id,
            'course_id'   => $this->course_id,
            'course_name' => $this->course_name,
            'classes'     => $this->classes,
            'week'        => (int)$this->week,
            'weekday'     => (int)$this->weekday,
            'weekday_text' => $this->weekdayText(),
            'section'     => (int)$this->section,
            'section_text' => $this->sectionText(),
            'teach_date'  => $this->teach_date,
            'periods'     => (float)$this->periods,
            'price'       => (float)$this->price,
            'amount'      => (float)$this->amount,
            'type'        => $this->getData('type'),
            'type_text'   => $this->typeText(),
            'remark'      => $this->remark,
            'source'      => $this->source,
            'source_text' => $this->sourceText(),
            'created_at'  => (int)$this->created_at,
            'updated_at'  => (int)$this->updated_at,
        ];
    }

    /**
     * 基础查询：排除软删除记录
     */
    public static function alive()
    {
        return self::where('deleted_at', 0);
    }

    // -------------------- 防重复校验 --------------------

    /**
     * 查找冲突记录：同一教师 + 同一学期 + 同一周 + 同一星期 + 同一节次
     * @param int $exceptId 编辑时排除自身
     */
    public static function findDup($teacherId, $termId, $week, $weekday, $section, $exceptId = 0)
    {
        $q = self::where('teacher_id', $teacherId)
            ->where('term_id', $termId)
            ->where('week', $week)
            ->where('weekday', $weekday)
            ->where('section', $section)
            ->where('deleted_at', 0);
        if ($exceptId) {
            $q->where('id', '<>', $exceptId);
        }
        return $q->find();
    }

    // -------------------- 写入 --------------------

    /**
     * 由表单数据组装一条待写入记录
     * 统一在这里算金额，避免各处重复计算
     */
    public static function buildData($input, $teacherId)
    {
        $periods = isset($input['periods']) ? (float)$input['periods'] : 2;
        $price   = isset($input['price']) ? (float)$input['price'] : 0;
        return [
            'teacher_id'  => $teacherId,
            'term_id'     => intval($input['term_id']),
            'course_id'   => intval($input['course_id']),
            'course_name' => trim($input['course_name']),
            'classes'     => trim(isset($input['classes']) ? $input['classes'] : ''),
            'week'        => intval($input['week']),
            'weekday'     => intval($input['weekday']),
            'section'     => intval($input['section']),
            'teach_date'  => !empty($input['teach_date']) ? $input['teach_date'] : null,
            'periods'     => $periods,
            'price'       => $price,
            'amount'      => round($periods * $price, 2),
            'type'        => isset($input['type']) ? $input['type'] : 'normal',
            'remark'      => trim(isset($input['remark']) ? $input['remark'] : ''),
            'source'      => isset($input['source']) ? $input['source'] : 'manual',
            'deleted_at'  => 0,
        ];
    }

    /**
     * 批量按周生成：同一课程铺满 startWeek ~ endWeek
     * 已存在的时段自动跳过；日期缺省时用学期开学日推算
     *
     * @return array ['created'=>[], 'skipped'=>[]]
     */
    public static function batchCreate($input, $teacherId, $term)
    {
        $created = [];
        $skipped = [];
        $startWeek = intval($input['start_week']);
        $endWeek   = intval($input['end_week']);
        if ($endWeek < $startWeek) {
            $tmp = $startWeek; $startWeek = $endWeek; $endWeek = $tmp;
        }
        // 周期步进：默认每周，可传 step（如 2 = 单周/双周）
        $step = max(1, intval(isset($input['step']) ? $input['step'] : 1));

        for ($w = $startWeek; $w <= $endWeek; $w += $step) {
            $row = $input;
            $row['week'] = $w;
            if (empty($row['teach_date']) && $term) {
                $row['teach_date'] = Term::dateOf($term, $w, intval($row['weekday']));
            }
            // 防重复
            if (self::findDup($teacherId, intval($row['term_id']), $w, intval($row['weekday']), intval($row['section']))) {
                $skipped[] = $w;
                continue;
            }
            $data            = self::buildData($row, $teacherId);
            $data['source']  = 'batch';
            $lesson          = self::create($data);
            $created[]       = $lesson->id;
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    // -------------------- 查询作用域 --------------------

    /**
     * 构造带筛选条件的查询
     * $scope 中若含 teacher_id 则强制数据隔离
     */
    public static function scopeQuery($scope)
    {
        $q = self::where('deleted_at', 0);
        if (!empty($scope['teacher_id'])) {
            $q->where('teacher_id', $scope['teacher_id']);
        }
        if (!empty($scope['term_id'])) {
            $q->where('term_id', $scope['term_id']);
        }
        if (!empty($scope['course_id'])) {
            $q->where('course_id', $scope['course_id']);
        }
        if (!empty($scope['type'])) {
            $q->where('type', $scope['type']);
        }
        if (!empty($scope['week'])) {
            $q->where('week', $scope['week']);
        }
        if (!empty($scope['weekday'])) {
            $q->where('weekday', $scope['weekday']);
        }
        if (!empty($scope['month'])) {
            // month 格式 YYYY-MM，按实际授课日期过滤
            $q->where('teach_date', 'like', $scope['month'] . '%');
        }
        if (!empty($scope['keyword'])) {
            $kw = '%' . $scope['keyword'] . '%';
            $q->where(function ($sq) use ($kw) {
                $sq->where('course_name', 'like', $kw)
                   ->whereOr('classes', 'like', $kw)
                   ->whereOr('remark', 'like', $kw);
            });
        }
        if (!empty($scope['start_date']) && !empty($scope['end_date'])) {
            $q->where('teach_date', 'between', [$scope['start_date'], $scope['end_date']]);
        }
        return $q;
    }

    /**
     * 软删除
     */
    public function softDelete()
    {
        $this->deleted_at = time();
        return $this->save();
    }

    /**
     * 恢复软删除
     */
    public function restore()
    {
        $this->deleted_at = 0;
        return $this->save();
    }

    /**
     * 批量软删除：把符合 $scope 条件的记录全部打上 deleted_at=time()
     * 用于「按当前查询条件一键删除全部」
     *
     * 不用 $this->update() 是因为 update() 不会经过自动时间戳；
     * 用 Db::name() 拼 SQL，对 InnoDB 大表 1 次更新最快。
     *
     * @return int 影响行数
     */
    public static function batchSoftDeleteByScope(array $scope)
    {
        // scopeQuery 已经是「排除 deleted_at=0」的活查询，
        // 直接把它当 update 目标即可；同时忽略 delete_time=0
        $q = self::scopeQuery($scope);
        $now = time();
        return $q->update(['deleted_at' => $now]);
    }

    /**
     * 批量恢复：把 deleted_at>0 的 + 匹配 scope 条件的，全部回到 0
     * 用于「一键恢复全部」 / 「恢复某教师某学期全部」
     */
    public static function batchRestoreByScope(array $scope)
    {
        // scopeQuery 不接受"包含已删"——直接拼一个对应的包含分支
        $q = self::where('deleted_at', '>', 0);
        if (!empty($scope['teacher_id'])) {
            $q->where('teacher_id', $scope['teacher_id']);
        }
        if (!empty($scope['term_id'])) {
            $q->where('term_id', $scope['term_id']);
        }
        if (!empty($scope['course_id'])) {
            $q->where('course_id', $scope['course_id']);
        }
        if (!empty($scope['type'])) {
            $q->where('type', $scope['type']);
        }
        if (!empty($scope['keyword'])) {
            $kw = '%' . $scope['keyword'] . '%';
            $q->where(function ($sq) use ($kw) {
                $sq->where('course_name', 'like', $kw)
                   ->whereOr('classes', 'like', $kw)
                   ->whereOr('remark', 'like', $kw);
            });
        }
        if (!empty($scope['start_date']) && !empty($scope['end_date'])) {
            $q->where('teach_date', 'between', [$scope['start_date'], $scope['end_date']]);
        }
        if (!empty($scope['month'])) {
            $q->where('teach_date', 'like', $scope['month'] . '%');
        }
        return $q->update(['deleted_at' => 0]);
    }
}
