<?php
namespace app\common\model;

use think\Model;

/**
 * AI 模型与计费倍率
 *
 * 计费公式：点数 = prompt_tokens * prompt_ratio + completion_tokens * completion_ratio
 * 未登记的模型按 1:1 兜底，保证任何新模型接入都能计费而不是漏算。
 */
class AiModel extends Model
{
    protected $table    = 'ks_ai_model';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    const DEFAULT_RATIO = 1.0;

    /** 取模型倍率（未登记则 1:1） */
    public static function ratioOf($modelKey)
    {
        $row = self::where('model_key', (string)$modelKey)->find();
        if (!$row) {
            return ['prompt' => self::DEFAULT_RATIO, 'completion' => self::DEFAULT_RATIO];
        }
        return [
            'prompt'     => (float)$row->prompt_ratio,
            'completion' => (float)$row->completion_ratio,
        ];
    }

    /** 本次调用消耗点数 */
    public static function cost($modelKey, $promptTokens, $completionTokens)
    {
        $r = self::ratioOf($modelKey);
        $p = max(0, (float)$promptTokens);
        $c = max(0, (float)$completionTokens);
        return round($p * $r['prompt'] + $c * $r['completion'], 4);
    }

    public static function enabledList()
    {
        return self::where('enabled', 1)->order('model_key asc')->select();
    }

    /**
     * 批量登记模型（拉取渠道模型列表时用），已存在的不覆盖倍率
     * @return int 新增条数
     */
    public static function syncFromList(array $modelKeys)
    {
        $added = 0;
        foreach ($modelKeys as $key) {
            $key = trim((string)$key);
            if ($key === '') continue;
            if (self::where('model_key', $key)->find()) continue;
            $row = new self();
            $row->model_key        = $key;
            $row->display_name     = $key;
            $row->prompt_ratio     = self::DEFAULT_RATIO;
            $row->completion_ratio = self::DEFAULT_RATIO;
            $row->enabled          = 1;
            $row->save();
            $added++;
        }
        return $added;
    }

    public function toArrayLite()
    {
        return [
            'id'               => (int)$this->id,
            'model_key'        => (string)$this->model_key,
            'display_name'     => (string)$this->display_name,
            'prompt_ratio'     => (float)$this->prompt_ratio,
            'completion_ratio' => (float)$this->completion_ratio,
            'enabled'          => (int)$this->enabled,
        ];
    }
}
