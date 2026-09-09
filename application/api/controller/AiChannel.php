<?php
namespace app\api\controller;

use app\common\controller\Base;
// 控制器类名与模型类名同为 AiChannel，模型必须起别名
use app\common\model\AiChannel as AiChannelModel;
use app\common\model\AiModel;
use app\common\service\AiConfig;

/**
 * AI 渠道与模型管理（仅管理员）
 *
 * 渠道 = 一个上游厂商的一套凭证（OpenAI / Claude / 通义 / DeepSeek / 自定义 …）
 * 模型 = 计费倍率表，决定「1 个 token 折算多少点数」
 */
class AiChannel extends Base
{
    protected function initialize()
    {
        parent::initialize();
        $this->requireAdmin();
    }

    /** 渠道列表 + 模型倍率列表 */
    public function index()
    {
        $channels = [];
        foreach (AiChannelModel::order('priority desc, id asc')->select() as $c) {
            $channels[] = $c->toArrayLite();
        }

        $models = [];
        foreach (AiModel::order('model_key asc')->select() as $m) {
            $models[] = $m->toArrayLite();
        }

        return $this->ok([
            'channels' => $channels,
            'models'   => $models,
            'types'    => ['openai', 'claude', 'qwen', 'deepseek', 'gemini', 'custom'],
        ]);
    }

    /** 新增 / 编辑渠道 */
    public function save()
    {
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $name = trim((string)(isset($data['name']) ? $data['name'] : ''));
        if ($name === '') return $this->fail('请填写渠道名称');
        if (mb_strlen($name) > 64) return $this->fail('渠道名称不能超过 64 个字符');

        $type = trim((string)(isset($data['type']) ? $data['type'] : 'openai'));
        if (!in_array($type, ['openai', 'claude', 'qwen', 'deepseek', 'gemini', 'custom'], true)) {
            $type = 'custom';
        }

        $baseUrl = trim((string)(isset($data['base_url']) ? $data['base_url'] : ''));
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            return $this->fail('接口地址必须填写，且以 http:// 或 https:// 开头');
        }
        if (strlen($baseUrl) > 500) return $this->fail('接口地址不能超过 500 个字符');

        $row = $id > 0 ? AiChannelModel::get($id) : null;
        if ($id > 0 && !$row) return $this->fail('渠道不存在');

        if (!$row) {
            $row = new AiChannel();
            $row->status   = 1;
            $row->priority = 10;
        }

        $row->name     = $name;
        $row->type     = $type;
        $row->base_url = $baseUrl;
        $row->priority = intval(isset($data['priority']) ? $data['priority'] : 10);
        $row->status   = !empty($data['status']) ? 1 : 0;

        // Key：传了非空才更新（留空=保留原 Key）
        $key = trim((string)(isset($data['api_key']) ? $data['api_key'] : ''));
        if ($key !== '') {
            $row->setPlainKey($key);
        }

        // 模型白名单：数组；空数组表示不限制
        $models = isset($data['models']) && is_array($data['models']) ? $data['models'] : [];
        $row->setModelList($models);

        $row->save();
        $this->log($id > 0 ? '编辑AI渠道' : '新增AI渠道', 'ai_channel', $row->id, $name);

        return $this->ok($row->toArrayLite(), '已保存');
    }

    /** 删除渠道 */
    public function delete()
    {
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);
        $row  = $id > 0 ? AiChannelModel::get($id) : null;
        if (!$row) return $this->fail('渠道不存在');

        $name = (string)$row->getData('name');
        $row->delete();
        $this->log('删除AI渠道', 'ai_channel', $id, $name);
        return $this->ok(null, '已删除');
    }

    /** 启用 / 禁用 */
    public function toggle()
    {
        $data   = $this->jsonInput();
        $id     = intval(isset($data['id']) ? $data['id'] : 0);
        $status = !empty($data['status']) ? 1 : 0;
        $row    = $id > 0 ? AiChannelModel::get($id) : null;
        if (!$row) return $this->fail('渠道不存在');

        $row->status = $status;
        $row->save();
        return $this->ok(null, $status ? '已启用' : '已禁用');
    }

    /**
     * 测试渠道连通性并拉取模型列表
     * with_sync=1 时把拉到的模型同时登记进模型倍率表、写回渠道白名单
     */
    public function test()
    {
        $data = $this->jsonInput();

        $baseUrl = trim((string)(isset($data['base_url']) ? $data['base_url'] : ''));
        $key     = trim((string)(isset($data['api_key']) ? $data['api_key'] : ''));
        $id      = intval(isset($data['id']) ? $data['id'] : 0);

        // 编辑场景下未重填 Key，则取库里已保存的那把
        if ($key === '' && $id > 0) {
            $row = AiChannelModel::get($id);
            if ($row) $key = $row->plainKey();
        }

        $res = AiConfig::fetchModels($baseUrl, $key);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }

        $added = 0;
        if (!empty($data['with_sync'])) {
            $added = AiModel::syncFromList($res['models']);
            if ($id > 0) {
                $row = AiChannelModel::get($id);
                if ($row) {
                    $row->setModelList($res['models']);
                    $row->markOk();
                }
            }
        }

        return $this->ok([
            'models' => $res['models'],
            'added'  => $added,
        ], '连通正常，共拉取到 ' . count($res['models']) . ' 个模型');
    }

    /** 新增 / 编辑模型倍率 */
    public function modelSave()
    {
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $modelKey = trim((string)(isset($data['model_key']) ? $data['model_key'] : ''));
        if ($modelKey === '') return $this->fail('请填写模型标识');
        if (strlen($modelKey) > 100) return $this->fail('模型标识不能超过 100 个字符');

        $row = $id > 0 ? AiModel::get($id) : null;
        if ($id > 0 && !$row) return $this->fail('模型不存在');

        // 新增时检查唯一性
        if (!$row) {
            $exists = AiModel::where('model_key', $modelKey)->find();
            if ($exists) return $this->fail('该模型标识已存在');
            $row = new AiModel();
            $row->enabled = 1;
        }

        $row->model_key        = $modelKey;
        $row->display_name     = trim((string)(isset($data['display_name']) ? $data['display_name'] : $modelKey));
        $row->prompt_ratio     = round(floatval(isset($data['prompt_ratio']) ? $data['prompt_ratio'] : 1), 4);
        $row->completion_ratio = round(floatval(isset($data['completion_ratio']) ? $data['completion_ratio'] : 1), 4);
        $row->enabled          = !empty($data['enabled']) ? 1 : 0;
        $row->save();

        return $this->ok($row->toArrayLite(), '已保存');
    }

    /** 删除模型倍率 */
    public function modelDelete()
    {
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);
        $row  = $id > 0 ? AiModel::get($id) : null;
        if (!$row) return $this->fail('模型不存在');

        $row->delete();
        return $this->ok(null, '已删除');
    }
}
