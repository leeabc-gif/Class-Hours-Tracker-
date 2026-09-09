<?php
namespace app\common\service;

use app\common\model\AiQuota;
use app\common\model\AiUsageLog;

/**
 * AI 额度服务
 *
 * 三段式：preCheck（调用前粗检） → 真实调用 → settle（调用后按实际点数扣减）
 * 之所以不在调用前精确扣减：输出 token 数无法预知，预扣会导致要么误拒要么欠收。
 */
class QuotaService
{
    /** 取额度行（顺带处理日/周/月周期重置） */
    public static function get($teacherId)
    {
        return AiQuota::forTeacher(intval($teacherId));
    }

    /**
     * 调用前粗检：确保「还有余额」且「各周期还有空间」
     *
     * @param int  $teacherId
     * @param bool $strict  true=严格校验（操练场 / 外部 API）
     *                      false=宽松（站内 AI 助手：该教师尚未被分配额度时不阻断）
     * @return array ['ok'=>bool,'msg'=>'','quota'=>AiQuota,'unlimited'=>bool]
     */
    public static function preCheck($teacherId, $strict = true)
    {
        $q = AiQuota::forTeacher(intval($teacherId));

        // 未纳入额度管理 + 宽松模式 → 放行（但用量照常记账，管理员可分报表看到真实消耗）
        if (!$strict && !$q->isManaged()) {
            return ['ok' => true, 'msg' => '', 'quota' => $q, 'unlimited' => true];
        }

        // 余额是硬约束（严格模式下生效）
        if ((float)$q->balance <= 0) {
            return ['ok' => false, 'msg' => 'AI 额度已用尽，请联系管理员分配额度', 'quota' => $q, 'unlimited' => false];
        }
        if ($q->daily_limit > 0 && (float)$q->daily_used >= (float)$q->daily_limit) {
            return ['ok' => false, 'msg' => '今日 AI 额度已用完，请明天再试', 'quota' => $q];
        }
        if ($q->weekly_limit > 0 && (float)$q->weekly_used >= (float)$q->weekly_limit) {
            return ['ok' => false, 'msg' => '本周 AI 额度已用完，请下周再试', 'quota' => $q];
        }
        if ($q->monthly_limit > 0 && (float)$q->monthly_used >= (float)$q->monthly_limit) {
            return ['ok' => false, 'msg' => '本月 AI 额度已用完，请下月再试', 'quota' => $q];
        }
        return ['ok' => true, 'msg' => '', 'quota' => $q];
    }

    /**
     * 调用成功后按实际点数结算（原子扣减，防并发超扣）
     * @return array ['ok'=>bool,'quota'=>AiQuota]
     */
    public static function settle($teacherId, $points)
    {
        $q = AiQuota::forTeacher(intval($teacherId));
        $ok = $q->consume((float)$points);
        return ['ok' => $ok, 'quota' => $q];
    }

    /**
     * 管理员分配额度 / 设置周期上限
     * @param float $amount 正数充值，负数回收，0=只改上限
     * @param array|null $limits ['daily'=>x,'weekly'=>y,'monthly'=>z]
     */
    public static function assign($teacherId, $amount, $limits = null)
    {
        $q = AiQuota::forTeacher(intval($teacherId));
        if ((float)$amount != 0) {
            $q->adjust((float)$amount);
        }
        if (is_array($limits)) {
            $q->setLimits(
                isset($limits['daily'])   ? $limits['daily']   : $q->daily_limit,
                isset($limits['weekly'])  ? $limits['weekly']  : $q->weekly_limit,
                isset($limits['monthly']) ? $limits['monthly'] : $q->monthly_limit
            );
        }
        return $q;
    }

    /** 给前端的额度摘要（含各周期剩余） */
    public static function summary($teacherId)
    {
        $q    = AiQuota::forTeacher(intval($teacherId));
        $data = $q->toArrayLite();

        $data['remaining_daily']   = $q->daily_limit   > 0 ? max(0, (float)$q->daily_limit   - (float)$q->daily_used)   : -1;
        $data['remaining_weekly']  = $q->weekly_limit  > 0 ? max(0, (float)$q->weekly_limit  - (float)$q->weekly_used)  : -1;
        $data['remaining_monthly'] = $q->monthly_limit > 0 ? max(0, (float)$q->monthly_limit - (float)$q->monthly_used) : -1;
        return $data;
    }

    /** 用量统计（教师自己看 / 管理员看某个教师） */
    public static function usage($teacherId, $startTs = 0, $endTs = 0)
    {
        return AiUsageLog::summary(intval($teacherId), $startTs, $endTs);
    }

    /** 管理员看板：全员用量排行 */
    public static function rank($startTs = 0, $endTs = 0, $limit = 50)
    {
        return AiUsageLog::rankByTeacher($startTs, $endTs, $limit);
    }
}
