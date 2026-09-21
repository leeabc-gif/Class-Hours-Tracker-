<?php
namespace app\common\model;

use think\Model;

/**
 * 学期配置
 */
class Term extends Model
{
    protected $table    = 'ks_term';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /**
     * 获取当前学期
     */
    public static function current()
    {
        $t = self::where('is_current', 1)->find();
        if (!$t) {
            $t = self::order('id desc')->find();
        }
        return $t;
    }

    /**
     * 设置某学期为当前学期（同步把其他学期置为非当前）
     */
    public static function setCurrent($id)
    {
        self::where('1=1')->update(['is_current' => 0]);
        self::where('id', $id)->update(['is_current' => 1]);
    }

    /**
     * 第 N 周的日期范围（周一 ~ 周日）
     */
    public static function weekRange($term, $week)
    {
        if (!$term || !$term->start_date) {
            return [null, null];
        }
        $start = strtotime($term->start_date) + ($week - 1) * 7 * 86400;
        return [date('Y-m-d', $start), date('Y-m-d', $start + 6 * 86400)];
    }

    /**
     * 依据周次+星期推算授课日期
     */
    public static function dateOf($term, $week, $weekday)
    {
        if (!$term || !$term->start_date) {
            return null;
        }
        $ts = strtotime($term->start_date) + ($week - 1) * 7 * 86400 + ($weekday - 1) * 86400;
        return date('Y-m-d', $ts);
    }

    /**
     * 依据授课日期反推周次（导入课表时日期已给、周次缺失用）
     * @return int 1 起的整数周次；推算不出返回 0
     */
    public static function weekOf($term, $date)
    {
        if (!$term || !$term->start_date || !$date) {
            return 0;
        }
        $start = strtotime($term->start_date);
        $ts    = strtotime($date);
        if ($start === false || $ts === false) return 0;
        $diffDays = floor(($ts - $start) / 86400);
        if ($diffDays < 0) return 0;
        return intval(floor($diffDays / 7)) + 1;
    }

    /**
     * 依据授课日期反推星期（ISO-8601：1=周一 … 7=周日）
     * @return int 1..7；推算不出返回 0
     */
    public static function weekdayOf($date)
    {
        if (!$date) {
            return 0;
        }
        $ts = strtotime($date);
        if ($ts === false) return 0;
        return intval(date('N', $ts));
    }

    /**
     * 校验「周次+星期」与「实际授课日期」是否自洽。
     *
     * 约定：开学日 start_date 视为第 1 周周一。
     * 周次/星期是排课位置（也是课表网格与防重复唯一键），日期应与其一致。
     * 调课(swap)/补课(makeup) 允许实际上课日期偏离排课位置，故调用方对这两类放行。
     *
     * @return true|string  返回 true 表示一致（或无需校验）；否则返回错误文案
     */
    public static function checkDateConsistency($term, $week, $weekday, $date)
    {
        static $weekdayText = [1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四', 5 => '周五', 6 => '周六', 7 => '周日'];
        if (!$term || !$term->start_date || $date === '' || $date === null) {
            return true; // 未配置开学日或未填日期，不校验
        }
        $expected = self::dateOf($term, intval($week), intval($weekday));
        if ($expected === null) {
            return true;
        }
        if ($date !== $expected) {
            $wText = isset($weekdayText[intval($weekday)]) ? $weekdayText[intval($weekday)] : ('星期' . intval($weekday));
            return sprintf(
                '授课日期 %s 与所选「第%d周 %s」不匹配，应为 %s。请核对周次/星期或日期（调课、补课可忽略此提示）',
                $date, intval($week), $wText, $expected
            );
        }
        return true;
    }
}
