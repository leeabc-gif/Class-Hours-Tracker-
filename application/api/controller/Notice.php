<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Notice as NoticeModel;
use app\common\model\NoticeRead;
use app\common\model\Teacher;

/**
 * 通知公告
 *
 * 权限：
 *   - 管理员：发布 / 编辑 / 下架 / 删除 / 置顶，可看草稿
 *   - 教师：只看「已发布」且范围匹配的公告，可标记已读
 *
 * ThinkPHP 陷阱：控制器与模型同名，模型必须用别名引入。
 */
class Notice extends Base
{
    const MAX_TITLE   = 200;
    const MAX_CONTENT = 20000;

    /**
     * 我的公告列表（含已读状态）
     * 管理员额外返回草稿，便于在同一页管理。
     */
    public function mine()
    {
        $this->requireLogin();
        $uid  = (int)$this->user->id;
        $role = (string)$this->user->getData('role');

        $rows = NoticeModel::visibleTo($role)->select();
        $ids  = [];
        foreach ($rows as $r) $ids[] = (int)$r->id;

        $readMap = array_fill_keys(NoticeRead::readIds($uid, $ids), true);

        $list = [];
        foreach ($rows as $r) {
            $item = $r->toArrayLite(isset($readMap[(int)$r->id]));
            // 列表不回传正文，避免一次拉爆；详情单独取
            unset($item['content']);
            $item['summary'] = self::summary((string)$r->content);
            $list[] = $item;
        }

        $drafts = [];
        if ($role === 'admin') {
            foreach (NoticeModel::where('status', 0)->order('id desc')->limit(50)->select() as $r) {
                $d = $r->toArrayLite(true);
                unset($d['content']);
                $d['summary'] = self::summary((string)$r->content);
                $drafts[] = $d;
            }
        }

        return $this->ok([
            'list'         => $list,
            'drafts'       => $drafts,
            'unread'       => NoticeModel::unreadCount($uid, $role),
            'is_admin'     => $role === 'admin',
        ]);
    }

    /** 公告详情（顺带标记已读） */
    public function detail()
    {
        $this->requireLogin();
        $id = intval($this->input('id', 0));
        if ($id <= 0) return $this->fail('参数错误');

        $row = NoticeModel::get($id);
        if (!$row) return $this->fail('公告不存在或已删除');

        $role = (string)$this->user->getData('role');
        if (!$this->canView($row, $role)) {
            return $this->fail('无权查看该公告');
        }

        $uid = (int)$this->user->id;
        if ((int)$row->status === 1) {
            NoticeRead::markRead($id, $uid);
        }

        $data = $row->toArrayLite(true);
        return $this->ok(['notice' => $data]);
    }

    /** 批量标记已读 */
    public function readAll()
    {
        $this->requireLogin();
        $uid  = (int)$this->user->id;
        $role = (string)$this->user->getData('role');

        $n = 0;
        foreach (NoticeModel::visibleTo($role)->select() as $r) {
            NoticeRead::markRead((int)$r->id, $uid);
            $n++;
        }
        return $this->ok(['count' => $n], '已全部标记为已读');
    }

    /** 未读数（顶部红点用；Boot 也会带一份，这里供手动刷新） */
    public function unread()
    {
        $this->requireLogin();
        return $this->ok([
            'unread' => NoticeModel::unreadCount((int)$this->user->id, (string)$this->user->getData('role')),
        ]);
    }

    // ---------------- 管理员 ----------------

    /** 新建 / 编辑 */
    public function save()
    {
        $this->requireAdmin();
        $data = $this->jsonInput();

        $id      = intval(isset($data['id']) ? $data['id'] : 0);
        $title   = trim((string)(isset($data['title']) ? $data['title'] : ''));
        $content = trim((string)(isset($data['content']) ? $data['content'] : ''));

        if ($title === '') return $this->fail('请填写标题');
        if (mb_strlen($title) > self::MAX_TITLE) return $this->fail('标题不能超过 ' . self::MAX_TITLE . ' 个字符');
        if ($content === '') return $this->fail('请填写正文');
        if (mb_strlen($content) > self::MAX_CONTENT) return $this->fail('正文过长（上限 ' . self::MAX_CONTENT . ' 字）');

        $type  = in_array((string)(isset($data['type']) ? $data['type'] : ''), ['notice', 'announce'], true)
            ? (string)$data['type'] : 'notice';
        $scope = in_array((string)(isset($data['scope']) ? $data['scope'] : ''), ['all', 'teacher', 'admin'], true)
            ? (string)$data['scope'] : 'all';
        $pinned  = !empty($data['pinned']) ? 1 : 0;
        $publish = !empty($data['publish']) ? 1 : 0;

        if ($id > 0) {
            $row = NoticeModel::get($id);
            if (!$row) return $this->fail('公告不存在');
        } else {
            $row = new NoticeModel();
            $row->publisher_id = (int)$this->user->id;
        }

        $row->title   = $title;
        $row->content = $content;
        $row->type    = $type;
        $row->scope   = $scope;
        $row->pinned  = $pinned;
        if ($id === 0) $row->status = $publish;

        // 用 data() 之外的直接赋值 + save：type/scope 是保留属性，必须走 setAttr 之外的方式
        $row->save();

        $this->log($id > 0 ? '编辑公告' : '发布公告', 'notice', (int)$row->id, $title);
        return $this->ok(['id' => (int)$row->id], $id > 0 ? '已保存' : '已发布');
    }

    /** 发布 / 下架 */
    public function toggle()
    {
        $this->requireAdmin();
        $data   = $this->jsonInput();
        $id     = intval(isset($data['id']) ? $data['id'] : 0);
        $status = !empty($data['status']) ? 1 : 0;

        $row = NoticeModel::get($id);
        if (!$row) return $this->fail('公告不存在');

        $row->status = $status;
        $row->save();
        return $this->ok(null, $status ? '已发布' : '已下架');
    }

    /** 置顶 / 取消置顶 */
    public function pin()
    {
        $this->requireAdmin();
        $data   = $this->jsonInput();
        $id     = intval(isset($data['id']) ? $data['id'] : 0);
        $pinned = !empty($data['pinned']) ? 1 : 0;

        $row = NoticeModel::get($id);
        if (!$row) return $this->fail('公告不存在');

        $row->pinned = $pinned;
        $row->save();
        return $this->ok(null, $pinned ? '已置顶' : '已取消置顶');
    }

    /** 删除（连带清掉已读回执） */
    public function delete()
    {
        $this->requireAdmin();
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $row = NoticeModel::get($id);
        if (!$row) return $this->fail('公告不存在');

        $title = (string)$row->title;
        NoticeRead::where('notice_id', $id)->delete();
        $row->delete();

        $this->log('删除公告', 'notice', $id, $title);
        return $this->ok(null, '已删除');
    }

    /** 管理员统计：某公告的已读人数 */
    public function readStat()
    {
        $this->requireAdmin();
        $id = intval($this->input('id', 0));
        if ($id <= 0) return $this->fail('参数错误');

        $total = Teacher::where('status', 1)->count();
        $read  = NoticeRead::where('notice_id', $id)->count();

        $readers = [];
        foreach (NoticeRead::where('notice_id', $id)->order('read_at desc')->limit(200)->select() as $r) {
            $t = Teacher::get((int)$r->user_id);
            $readers[] = [
                'user_id' => (int)$r->user_id,
                'name'    => $t ? (string)$t->getData('name') : '—',
                'read_at' => (int)$r->read_at,
            ];
        }
        return $this->ok(['read' => (int)$read, 'total' => (int)$total, 'readers' => $readers]);
    }

    // ---------------- 内部 ----------------

    /**
     * 公告对某角色是否可见
     * 草稿只有发布者本人（或管理员）能看，避免误操作前被教师看到。
     */
    private function canView(NoticeModel $row, $role)
    {
        if ($role === 'admin') return true;
        if ((int)$row->status !== 1) return false;
        $scope = (string)$row->getData('scope');
        return $scope === 'all' || $scope === 'teacher';
    }

    /** 列表用摘要：去标签、压空白、截断 */
    private static function summary($html)
    {
        $text = trim(strip_tags((string)$html));
        $text = preg_replace('/\s+/u', ' ', $text);
        if ($text === '') return '';
        return mb_substr($text, 0, 120);
    }
}
