<?php
namespace app\common\model;

use think\Model;

/**
 * 通知公告
 *
 * 可见范围 scope：all=全体 / teacher=仅教师 / admin=仅管理员
 * ThinkPHP 陷阱：`type` 是 Attribute trait 的字段类型转换配置，
 * 因此读取本表的 type / scope 必须用 getData()。
 */
class Notice extends Model
{
    protected $table    = 'ks_notice';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 某角色可见的已发布公告查询（置顶优先，其次按时间倒序） */
    public static function visibleTo($role)
    {
        $scope = ($role === 'admin') ? 'admin' : 'teacher';
        return self::where('status', 1)
            ->where(function ($q) use ($scope) {
                $q->where('scope', 'all')->whereOr('scope', $scope);
            })
            ->order('pinned desc, created_at desc');
    }

    /** 未读条数 */
    public static function unreadCount($userId, $role)
    {
        $ids = self::visibleTo($role)->column('id');
        if (!$ids) return 0;

        $readIds = NoticeRead::where('user_id', intval($userId))
            ->where('notice_id', 'in', $ids)
            ->column('notice_id');

        $readMap = array_fill_keys(array_map('intval', $readIds), true);
        $n = 0;
        foreach ($ids as $id) {
            if (!isset($readMap[(int)$id])) $n++;
        }
        return $n;
    }

    public function publisherName()
    {
        $t = \app\common\model\Teacher::get((int)$this->publisher_id);
        return $t ? $t->getData('name') : '系统';
    }

    public function toArrayLite($isRead = false)
    {
        return [
            'id'            => (int)$this->id,
            'title'         => (string)$this->title,
            'content'       => (string)$this->content,
            'type'          => (string)$this->getData('type'),
            'scope'         => (string)$this->getData('scope'),
            'pinned'        => (int)$this->pinned,
            'publisher_id'  => (int)$this->publisher_id,
            'publisher'     => $this->publisherName(),
            'status'        => (int)$this->status,
            'read'          => $isRead ? 1 : 0,
            'created_at'    => (int)$this->getData('created_at'),
            'updated_at'    => (int)$this->getData('updated_at'),
        ];
    }
}
