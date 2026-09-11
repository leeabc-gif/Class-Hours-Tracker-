<?php
namespace app\common\service;

use app\common\model\StudentAiQuota;
use app\common\model\AiUsageLog;

/**
 * 学生 AI 额度服务
 *
 * 结构完全对齐教师 QuotaService，但操作独立的 ks_student_ai_quota 表。
 */
class StudentQuotaService
{
    public static function get($studentId)
    {
        return StudentAiQuota::forStudent(intval($studentId));
    }

    public static function preCheck($studentId)
    {
        $q = StudentAiQuota::forStudent(intval($studentId));
        if ((float)$q->balance <= 0) {
            return ['ok' => false, 'msg' => 'AI 额度已用尽，请联系老师分配', 'quota' => $q];
        }
        if ($q->daily_limit > 0 && (float)$q->daily_used >= (float)$q->daily_limit) {
            return ['ok' => false, 'msg' => '今日 AI 额度已用完，请明天再试', 'quota' => $q];
        }
        if ($q->weekly_limit > 0 && (float)$q->weekly_used >= (float)$q->weekly_limit) {
            return ['ok' => false, 'msg' => '本周 AI 额度已用完', 'quota' => $q];
        }
        if ($q->monthly_limit > 0 && (float)$q->monthly_used >= (float)$q->monthly_limit) {
            return ['ok' => false, 'msg' => '本月 AI 额度已用完', 'quota' => $q];
        }
        return ['ok' => true, 'msg' => '', 'quota' => $q];
    }

    public static function settle($studentId, $points)
    {
        $q = StudentAiQuota::forStudent(intval($studentId));
        $ok = $q->consume((float)$points);
        return ['ok' => $ok, 'quota' => $q];
    }

    public static function assign($studentId, $amount)
    {
        $q = StudentAiQuota::forStudent(intval($studentId));
        if ((float)$amount != 0) $q->adjust((float)$amount);
        return $q;
    }

    public static function summary($studentId)
    {
        $q = StudentAiQuota::forStudent(intval($studentId));
        return $q->toArrayLite();
    }
}