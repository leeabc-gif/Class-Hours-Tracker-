<?php
namespace app\api\controller;

use app\common\controller\Base;
// 控制器类名与模型类名同为 AiQuota，模型必须起别名
use app\common\model\AiQuota as AiQuotaModel;
use app\common\model\Teacher;
use app\common\service\QuotaService;

/**
 * AI 额度：教师查自己的、管理员分配与查看全员
 *
 * 权限是混合的（教师看自己的 / 管理员管所有人），
 * 因此不在 initialize 里统一拦截，改为每个方法内按需 requireLogin/requireAdmin。
 */
class AiQuota extends Base
{
    /** 教师：我的额度 + 我的用量 */
    public function mine()
    {
        $this->requireLogin();
        $uid = (int)$this->user->id;

        return $this->ok([
            'quota' => QuotaService::summary($uid),
            'usage' => QuotaService::usage($uid),
        ]);
    }

    /** 教师：我的用量明细（按时间范围） */
    public function myUsage()
    {
        $this->requireLogin();
        $uid   = (int)$this->user->id;
        $start = intval($this->input('start', 0));
        $end   = intval($this->input('end', 0));

        return $this->ok([
            'summary' => QuotaService::usage($uid, $start, $end),
        ]);
    }

    /**
     * 管理员：全员额度一览
     * 返回教师列表（姓名/账号/院系）+ 各自额度，未分配过的显示 0
     */
    public function all()
    {
        $this->requireAdmin();

        $kw = trim((string)$this->input('keyword', ''));
        $q  = Teacher::where('status', 1);
        if ($kw !== '') {
            $q->where(function ($sq) use ($kw) {
                $like = '%' . $kw . '%';
                $sq->where('username', 'like', $like)->whereOr('name', 'like', $like);
            });
        }

        $out = [];
        foreach ($q->order('id asc')->select() as $t) {
            $quota = AiQuotaModel::forTeacher((int)$t->id);
            $out[] = [
                'teacher_id'   => (int)$t->id,
                'username'     => (string)$t->username,
                'name'         => (string)$t->getData('name'),
                'role'         => (string)$t->getData('role'),
                'department'   => $t->departmentName(),
                'quota'        => $quota->toArrayLite(),
            ];
        }

        return $this->ok(['list' => $out]);
    }

    /**
     * 管理员：分配 / 回收额度，并可同时设置日周月上限
     * amount > 0 充值，< 0 回收，= 0 表示只改上限
     */
    public function assign()
    {
        $this->requireAdmin();
        $data = $this->jsonInput();

        $teacherId = intval(isset($data['teacher_id']) ? $data['teacher_id'] : 0);
        $teacher   = $teacherId > 0 ? Teacher::get($teacherId) : null;
        if (!$teacher) return $this->fail('教师不存在');

        $amount = floatval(isset($data['amount']) ? $data['amount'] : 0);
        $limits = null;
        if (isset($data['daily_limit']) || isset($data['weekly_limit']) || isset($data['monthly_limit'])) {
            $limits = [
                'daily'   => floatval(isset($data['daily_limit']) ? $data['daily_limit'] : 0),
                'weekly'  => floatval(isset($data['weekly_limit']) ? $data['weekly_limit'] : 0),
                'monthly' => floatval(isset($data['monthly_limit']) ? $data['monthly_limit'] : 0),
            ];
        }

        if ($amount == 0 && $limits === null) {
            return $this->fail('请填写充值的额度，或设置周期上限');
        }

        // 回收不能超过余额
        if ($amount < 0) {
            $cur = AiQuotaModel::forTeacher($teacherId);
            if ((float)$cur->balance < abs($amount)) {
                return $this->fail('回收额度不能大于当前余额（当前 ' . $cur->balance . '）');
            }
        }

        $quota = QuotaService::assign($teacherId, $amount, $limits);

        $action = $amount > 0 ? '分配AI额度' : ($amount < 0 ? '回收AI额度' : '设置AI额度上限');
        $this->log($action, 'teacher', $teacherId,
            (string)$teacher->getData('name') . ' ' . ($amount != 0 ? ($amount > 0 ? '+' : '') . $amount : '仅改上限'));

        return $this->ok($quota->toArrayLite(), '已保存');
    }

    /** 管理员：某教师的用量统计 */
    public function usage()
    {
        $this->requireAdmin();
        $teacherId = intval($this->input('teacher_id', 0));
        if ($teacherId <= 0) return $this->fail('请指定教师');

        return $this->ok([
            'summary' => QuotaService::usage(
                $teacherId,
                intval($this->input('start', 0)),
                intval($this->input('end', 0))
            ),
        ]);
    }

    /** 管理员：全员用量排行 */
    public function rank()
    {
        $this->requireAdmin();
        return $this->ok([
            'list' => QuotaService::rank(
                intval($this->input('start', 0)),
                intval($this->input('end', 0)),
                intval($this->input('limit', 50))
            ),
        ]);
    }
}
