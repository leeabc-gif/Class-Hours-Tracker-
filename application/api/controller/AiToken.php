<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\AiToken as AiTokenModel;
use app\common\model\Teacher;
use app\common\service\QuotaService;

/**
 * API 令牌管理（教师管自己的，管理员可看全部）
 *
 * 明文 sk- 只在「创建成功」这一次返回，之后只能看到前缀。
 */
class AiToken extends Base
{
    /** 我的令牌 + 我的额度 */
    public function mine()
    {
        $this->requireLogin();
        $uid = (int)$this->user->id;

        $list = [];
        foreach (AiTokenModel::where('teacher_id', $uid)->order('id desc')->select() as $t) {
            $list[] = $t->toArrayLite();
        }

        return $this->ok([
            'tokens' => $list,
            'quota'  => QuotaService::summary($uid),
            'usage'  => QuotaService::usage($uid),
            'gateway' => [
                'base_url' => $this->gwBaseUrl() . '/v1',
                'models'   => $this->gwBaseUrl() . '/v1/models',
                'chat'     => $this->gwBaseUrl() . '/v1/chat/completions',
            ],
        ]);
    }

    /** 创建新令牌（返回明文，仅此一次） */
    public function create()
    {
        $this->requireLogin();
        $data = $this->jsonInput();

        $name = trim((string)(isset($data['name']) ? $data['name'] : ''));
        if ($name === '') return $this->fail('请给令牌起个名字，便于日后区分用途');
        if (mb_strlen($name) > 64) return $this->fail('名称不能超过 64 个字符');

        $models = isset($data['model_limit']) && is_array($data['model_limit'])
            ? array_values(array_filter(array_map('strval', $data['model_limit'])))
            : [];

        $expiresAt = 0;
        if (!empty($data['expires_at'])) {
            $ts = intval($data['expires_at']);
            if ($ts > time()) $expiresAt = $ts;
        }

        list($plain, $row) = AiTokenModel::issue((int)$this->user->id, $name, $models, $expiresAt);
        $this->log('创建API令牌', 'ai_token', $row->id, $name);

        return $this->ok([
            'token' => $row->toArrayLite(),
            'plain' => $plain,      // ⚠️ 唯一一次明文，前端需提示用户立即复制
        ], '创建成功，请立即复制保存');
    }

    /** 停用 / 启用 */
    public function toggle()
    {
        $this->requireLogin();
        $data   = $this->jsonInput();
        $id     = intval(isset($data['id']) ? $data['id'] : 0);
        $status = !empty($data['status']) ? 1 : 0;

        $row = $id > 0 ? AiTokenModel::get($id) : null;
        if (!$row) return $this->fail('令牌不存在');
        // 教师只能操作自己的；管理员不受限
        if (!$this->user->isAdmin() && (int)$row->teacher_id !== (int)$this->user->id) {
            return $this->fail('无权操作该令牌');
        }

        $row->status = $status;
        $row->save();
        return $this->ok(null, $status ? '已启用' : '已停用');
    }

    /** 删除（吊销） */
    public function delete()
    {
        $this->requireLogin();
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $row = $id > 0 ? AiTokenModel::get($id) : null;
        if (!$row) return $this->fail('令牌不存在');
        if (!$this->user->isAdmin() && (int)$row->teacher_id !== (int)$this->user->id) {
            return $this->fail('无权操作该令牌');
        }

        $name = (string)$row->getData('name');
        $row->delete();
        $this->log('删除API令牌', 'ai_token', $id, $name);
        return $this->ok(null, '已删除');
    }

    /** 管理员：查看全部令牌 */
    public function all()
    {
        $this->requireAdmin();

        $out = [];
        foreach (AiTokenModel::order('id desc')->limit(200)->select() as $t) {
            $item = $t->toArrayLite();
            $teacher = Teacher::get((int)$t->teacher_id);
            $item['teacher_name'] = $teacher ? (string)$teacher->getData('name') : '—';
            $out[] = $item;
        }
        return $this->ok(['list' => $out]);
    }

    /** 拼出站点根地址，供前端展示网关地址 */
    private function gwBaseUrl()
    {
        $req  = request();
        $host = $req->host();
        $scheme = $req->isSsl() ? 'https' : 'http';
        return $scheme . '://' . $host;
    }
}
