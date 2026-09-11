<?php
namespace app\common\model;

use think\Model;

/**
 * 学生 AI 额度（独立于教师 ks_ai_quota）
 *
 * 周期语义、原子扣减结构与教师 AiQuota 完全一致。
 */
class StudentAiQuota extends Model
{
    protected $table    = 'ks_student_ai_quota';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = false;
    protected $updateTime = 'updated_at';

    public static function forStudent($studentId)
    {
        $row = self::where('student_id', (int)$studentId)->find();
        if (!$row) {
            $row = new self();
            $row->student_id       = (int)$studentId;
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

    public function resetIfNeeded()
    {
        $now   = time();
        $dirty = false;
        if ($this->daily_reset_at > 0 && $now >= $this->daily_reset_at) {
            $this->daily_used = 0;
            $this->daily_reset_at = self::nextDay($now);
            $dirty = true;
        }
        if ($this->weekly_reset_at > 0 && $now >= $this->weekly_reset_at) {
            $this->weekly_used = 0;
            $this->weekly_reset_at = self::nextWeek($now);
            $dirty = true;
        }
        if ($this->monthly_reset_at > 0 && $now >= $this->monthly_reset_at) {
            $this->monthly_used = 0;
            $this->monthly_reset_at = self::nextMonth($now);
            $dirty = true;
        }
        if ($dirty) $this->save();
        return $this;
    }

    public function consume($points)
    {
        $points = (float)$points;
        if ($points <= 0) return true;

        $affected = self::where('id', (int)$this->id)
            ->where('balance', '>=', $points)
            ->update([
                'balance'      => ['dec', $points],
                'total_used'   => ['inc', $points],
                'daily_used'   => ['inc', $points],
                'weekly_used'  => ['inc', $points],
                'monthly_used' => ['inc', $points],
                'updated_at'   => time(),
            ]);

        if (!$affected) return false;
        $this->refreshRow();
        return true;
    }

    public function adjust($amount)
    {
        $amount = (float)$amount;
        if ($amount == 0) return $this;
        if ($amount < 0) {
            self::where('id', (int)$this->id)
                ->where('balance', '>=', abs($amount))
                ->update(['balance' => ['dec', abs($amount)], 'updated_at' => time()]);
        } else {
            self::where('id', (int)$this->id)
                ->update(['balance' => ['inc', $amount], 'updated_at' => time()]);
        }
        $this->refreshRow();
        return $this;
    }

    public function toArrayLite()
    {
        return [
            'student_id'       => (int)$this->student_id,
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
}