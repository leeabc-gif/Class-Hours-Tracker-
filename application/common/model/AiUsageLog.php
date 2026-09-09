<?php
namespace app\common\model;

use think\Model;
use think\Db;

/**
 * AI 用量日志：计费、审计、统计的唯一依据
 *
 * 该表只有 created_at，没有 updated_at，因此 updateTime 关闭。
 */
class AiUsageLog extends Model
{
    protected $table    = 'ks_ai_usage_log';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = false;

    /**
     * 记一条调用日志
     *
     * @param array $data teacher_id/token_id/channel_id/model/prompt_tokens/
     *                    completion_tokens/points/latency_ms/status/error_msg/source/ip
     */
    public static function record(array $data)
    {
        $row = new self();
        $row->teacher_id        = intval(isset($data['teacher_id']) ? $data['teacher_id'] : 0);
        $row->token_id          = intval(isset($data['token_id']) ? $data['token_id'] : 0);
        $row->channel_id        = intval(isset($data['channel_id']) ? $data['channel_id'] : 0);
        $row->model             = substr((string)(isset($data['model']) ? $data['model'] : ''), 0, 100);
        $row->prompt_tokens     = intval(isset($data['prompt_tokens']) ? $data['prompt_tokens'] : 0);
        $row->completion_tokens = intval(isset($data['completion_tokens']) ? $data['completion_tokens'] : 0);
        $row->points            = round(floatval(isset($data['points']) ? $data['points'] : 0), 4);
        $row->latency_ms        = intval(isset($data['latency_ms']) ? $data['latency_ms'] : 0);
        $row->status            = !empty($data['status']) ? 1 : 0;
        $row->error_msg         = substr((string)(isset($data['error_msg']) ? $data['error_msg'] : ''), 0, 500);
        $row->source            = in_array((string)(isset($data['source']) ? $data['source'] : 'chat'), ['chat', 'playground', 'api'], true)
            ? (string)$data['source'] : 'chat';
        $row->ip                = substr((string)(isset($data['ip']) ? $data['ip'] : ''), 0, 45);
        $row->created_at        = time();
        $row->save();
        return $row;
    }

    /**
     * 教师维度的用量汇总
     * @return array 调用次数 / 成功 / 失败 / 总点数 / token 合计 / 平均耗时
     */
    public static function summary($teacherId, $startTs = 0, $endTs = 0)
    {
        $q = self::where('teacher_id', intval($teacherId));
        if ($startTs > 0) $q->where('created_at', '>=', intval($startTs));
        if ($endTs > 0)   $q->where('created_at', '<=', intval($endTs));

        $row = $q->field([
            'COUNT(*) AS cnt',
            'SUM(IF(status=1,1,0)) AS ok_cnt',
            'SUM(IF(status=0,1,0)) AS fail_cnt',
            'IFNULL(SUM(points),0) AS points',
            'IFNULL(SUM(prompt_tokens),0) AS prompt_tokens',
            'IFNULL(SUM(completion_tokens),0) AS completion_tokens',
            'IFNULL(AVG(latency_ms),0) AS avg_latency',
        ])->find();

        return [
            'count'             => intval($row->cnt),
            'success'           => intval($row->ok_cnt),
            'failed'            => intval($row->fail_cnt),
            'points'            => round((float)$row->points, 4),
            'prompt_tokens'     => intval($row->prompt_tokens),
            'completion_tokens' => intval($row->completion_tokens),
            'avg_latency_ms'    => intval($row->avg_latency),
        ];
    }

    /**
     * 管理员看板：各教师用量排行
     */
    public static function rankByTeacher($startTs = 0, $endTs = 0, $limit = 50)
    {
        $where = [];
        if ($startTs > 0) $where[] = ['created_at', '>=', intval($startTs)];
        if ($endTs > 0)   $where[] = ['created_at', '<=', intval($endTs)];

        $q = self::where('teacher_id', '>', 0);
        foreach ($where as $w) $q->where($w[0], $w[1], $w[2]);

        $rows = $q->field([
            'teacher_id',
            'COUNT(*) AS cnt',
            'IFNULL(SUM(points),0) AS points',
            'IFNULL(SUM(prompt_tokens),0) AS prompt_tokens',
            'IFNULL(SUM(completion_tokens),0) AS completion_tokens',
        ])->group('teacher_id')->order('points desc')->limit(intval($limit))->select();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'teacher_id'        => intval($r->teacher_id),
                'count'             => intval($r->cnt),
                'points'            => round((float)$r->points, 4),
                'prompt_tokens'     => intval($r->prompt_tokens),
                'completion_tokens' => intval($r->completion_tokens),
            ];
        }
        return $out;
    }

    public function toArrayLite()
    {
        return [
            'id'                => (int)$this->id,
            'teacher_id'        => (int)$this->teacher_id,
            'token_id'          => (int)$this->token_id,
            'channel_id'        => (int)$this->channel_id,
            'model'             => (string)$this->model,
            'prompt_tokens'     => (int)$this->prompt_tokens,
            'completion_tokens' => (int)$this->completion_tokens,
            'points'            => (float)$this->points,
            'latency_ms'        => (int)$this->latency_ms,
            'status'            => (int)$this->status,
            'error_msg'         => (string)$this->error_msg,
            'source'            => (string)$this->getData('source'),
            'created_at'        => (int)$this->created_at,
        ];
    }
}
