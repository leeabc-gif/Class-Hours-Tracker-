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
}
