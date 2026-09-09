<?php
namespace app\common\model;

use think\Model;

/**
 * 教师 AI 额度：总余额 + 日/周/月 周期限额
 *
 * 周期语义：
 *   - limit = 0 表示该维度「不限」
 *   - *_reset_at 是「下次重置时间戳」，到达即把对应 used 清零并推进到下一周期
 *   - 每次取额度时顺带 resetIfNeeded()，无需定时任务
 *
 * 该表没有 created_at，只有 updated_at，因此 createTime 关闭、updateTime 开启。
 */
class AiQuota extends Model
{
    protected $table    = 'ks_ai_quota';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = false;
    protected $updateTime = 'updated_at';

    /**
     * 取（或初始化）某教师的额度行，并顺带处理周期重置
     */
    public static function forTeacher($teacherId)
    {
        $row = self::where('teacher_id', (int)$teacherId)->find();
        if (!$row) {
            $row = new self();
            $row->teacher_id       = (int)$teacherId;
            $row->balance          = 0;
            $row->total_used       = 0;
            $row->daily_limit      = 0;
            $row->weekly_limit     = 0;
            $row->monthly_limit    = 0;
            $row->daily_used       = 0;
            $row->weekly_used      = 0;
            $row->monthly_used     = 0;
            $row->daily_reset_at   = self::nextDay();
            $row->weekly_reset_at  = self::nextWeek();
            $row->monthly_reset_at = self::nextMonth();
            $row->save();
            return $row;
        }
        $row->resetIfNeeded();
        return $row;
    }

    /** 周期到点则清零 used 并推进重置时间 */
    public function resetIfNeeded()
    {
        $now   = time();
        $dirty = false;

        if ($this->daily_reset_at > 0 && $now >= $this->daily_reset_at) {
            $this->daily_used     = 0;
            $this->daily_reset_at = self::nextDay($now);
            $dirty = true;
        }
        if ($this->weekly_reset_at > 0 && $now >= $this->weekly_reset_at) {
            $this->weekly_used     = 0;
            $this->weekly_reset_at = self::nextWeek($now);
            $dirty = true;
        }
        if ($this->monthly_reset_at > 0 && $now >= $this->monthly_reset_at) {
            $this->monthly_used     = 0;
            $this->monthly_reset_at = self::nextMonth($now);
            $dirty = true;
        }
        if ($dirty) {
            $this->save();
        }
        return $this;
    }

    /**
     * 该教师是否已被纳入「额度管理」
     *
     * 背景：新建额度行默认余额为 0。若一刀切按余额拦截，
     * 升级后所有教师的站内 AI 助手会立刻不可用（管理员还没来得及分配）。
     * 因此约定：只有「分配过余额 / 产生过消耗 / 设过周期上限」才算受控。
     * 未受控的教师在站内使用不受阻，操练场与外部 API 仍严格校验。
     */
    public function isManaged()
    {
        return (float)$this->balance > 0
            || (float)$this->total_used > 0
            || (float)$this->daily_limit > 0
            || (float)$this->weekly_limit > 0
            || (float)$this->monthly_limit > 0;
    }

    /**
     * 本次还能消耗多少点数（取「余额」与「各周期剩余」的最小值）
     * @return float 允许上限；-1 表示不限
     */
    public function allowable($expected = 0)
    {
        $limit = null;

        // 总余额：硬约束
        $balance = (float)$this->balance;
        $limit   = $balance;

        $caps = [
            ['limit' => (float)$this->daily_limit,   'used' => (float)$this->daily_used],
            ['limit' => (float)$this->weekly_limit,  'used' => (float)$this->weekly_used],
            ['limit' => (float)$this->monthly_limit, 'used' => (float)$this->monthly_used],
        ];
        foreach ($caps as $c) {
            if ($c['limit'] <= 0) continue;          // 0 = 不限
            $left = $c['limit'] - $c['used'];
            if ($left < 0) $left = 0;
            if ($limit === null || $left < $limit) $limit = $left;
        }

        return $limit === null ? -1 : $limit;
    }

    /**
     * 判断是否能消耗 $points
     * @return array ['ok'=>bool,'msg'=>'']
     */
    public function canConsume($points)
    {
        $points = (float)$points;
        if ($points <= 0) return ['ok' => true, 'msg' => ''];

        if ((float)$this->balance < $points) {
            return ['ok' => false, 'msg' => 'AI 额度不足（余额 ' . $this->balance . '，本次需 ' . round($points, 4) . '），请联系管理员分配额度'];
        }
        if ($this->daily_limit > 0 && ((float)$this->daily_used + $points) > (float)$this->daily_limit) {
            return ['ok' => false, 'msg' => '已超出今日 AI 额度上限（' . $this->daily_limit . '），请明天再试'];
        }
        if ($this->weekly_limit > 0 && ((float)$this->weekly_used + $points) > (float)$this->weekly_limit) {
            return ['ok' => false, 'msg' => '已超出本周 AI 额度上限（' . $this->weekly_limit . '），请下周再试'];
        }
        if ($this->monthly_limit > 0 && ((float)$this->monthly_used + $points) > (float)$this->monthly_limit) {
            return ['ok' => false, 'msg' => '已超出本月 AI 额度上限（' . $this->monthly_limit . '），请下月再试'];
        }
        return ['ok' => true, 'msg' => ''];
    }

    /**
     * 扣减（调用成功后执行）。用「带条件的原子 UPDATE」防并发超扣。
     * @return bool 是否扣减成功
     */
    public function consume($points)
    {
        $points = (float)$points;
        if ($points <= 0) return true;

        $affected = self::where('id', (int)$this->id)
            ->where('balance', '>=', $points)
            ->update([
                'balance'      => ['exp', 'balance-' . $points],
                'total_used'   => ['exp', 'total_used+' . $points],
                'daily_used'   => ['exp', 'daily_used+' . $points],
                'weekly_used'  => ['exp', 'weekly_used+' . $points],
                'monthly_used' => ['exp', 'monthly_used+' . $points],
                'updated_at'   => time(),
            ]);

        if (!$affected) return false;
        $this->refreshRow();
        return true;
    }

    /** 管理员分配/回收额度（正数充值，负数扣减） */
    public function adjust($amount)
    {
        $amount = (float)$amount;
        if ($amount == 0) return $this;

        if ($amount < 0) {
            self::where('id', (int)$this->id)
                ->where('balance', '>=', abs($amount))
                ->update([
                    'balance'    => ['exp', 'balance-' . abs($amount)],
                    'updated_at' => time(),
                ]);
        } else {
            self::where('id', (int)$this->id)->update([
                'balance'    => ['exp', 'balance+' . $amount],
                'updated_at' => time(),
            ]);
        }
        $this->refreshRow();
        return $this;
    }

    /** 设置周期上限（0=不限） */
    public function setLimits($daily, $weekly, $monthly)
    {
        $this->daily_limit   = max(0, (float)$daily);
        $this->weekly_limit  = max(0, (float)$weekly);
        $this->monthly_limit = max(0, (float)$monthly);
        $this->save();
        return $this;
    }

    /**
     * 原子 UPDATE 之后把最新值同步回当前对象
     * 不用 $this->data($fresh->getData()) 是因为不同小版本 Model::data() 语义有差异，
     * 显式逐字段赋值最稳。
     */
    private function refreshRow()
    {
        $fresh = self::where('id', (int)$this->id)->find();
        if (!$fresh) return $this;

        $this->balance      = $fresh->balance;
        $this->total_used   = $fresh->total_used;
        $this->daily_used   = $fresh->daily_used;
        $this->weekly_used  = $fresh->weekly_used;
        $this->monthly_used = $fresh->monthly_used;
        return $this;
    }

    // ---------------- 周期计算 ----------------

    private static function nextDay($from = null)
    {
        $from = $from ?: time();
        return strtotime(date('Y-m-d 00:00:00', $from) . ' +1 day');
    }

    private static function nextWeek($from = null)
    {
        $from = $from ?: time();
        return strtotime('next monday 00:00:00', $from);
    }

    private static function nextMonth($from = null)
    {
        $from = $from ?: time();
        return strtotime('first day of next month 00:00:00', $from);
    }

    public function toArrayLite()
    {
        return [
            'teacher_id'       => (int)$this->teacher_id,
            'balance'          => (float)$this->balance,
            'total_used'       => (float)$this->total_used,
            'daily_limit'      => (float)$this->daily_limit,
            'weekly_limit'     => (float)$this->weekly_limit,
            'monthly_limit'    => (float)$this->monthly_limit,
            'daily_used'       => (float)$this->daily_used,
            'weekly_used'      => (float)$this->weekly_used,
            'monthly_used'     => (float)$this->monthly_used,
            'daily_reset_at'   => (int)$this->daily_reset_at,
            'weekly_reset_at'  => (int)$this->weekly_reset_at,
            'monthly_reset_at' => (int)$this->monthly_reset_at,
        ];
    }
}
