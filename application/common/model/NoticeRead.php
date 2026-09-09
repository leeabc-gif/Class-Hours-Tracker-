<?php
namespace app\common\model;

use think\Model;

/**
 * 公告已读回执
 * 该表只有 read_at，无 created_at/updated_at，因此关闭自动时间戳。
 */
class NoticeRead extends Model
{
    protected $table    = 'ks_notice_read';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = false;

    /** 标记已读（已读过则忽略，靠 uk_nr 唯一键兜底） */
    public static function markRead($noticeId, $userId)
    {
        $noticeId = intval($noticeId);
        $userId   = intval($userId);
        if ($noticeId <= 0 || $userId <= 0) return false;

        $exists = self::where('notice_id', $noticeId)->where('user_id', $userId)->find();
        if ($exists) return true;

        $row = new self();
        $row->notice_id = $noticeId;
        $row->user_id   = $userId;
        $row->read_at   = time();
        $row->save();
        return true;
    }

    /** 一批公告里，该用户已读的 id 集合 */
    public static function readIds($userId, array $noticeIds)
    {
        $noticeIds = array_values(array_filter(array_map('intval', $noticeIds)));
        if (!$noticeIds) return [];

        $rows = self::where('user_id', intval($userId))
            ->where('notice_id', 'in', $noticeIds)
            ->column('notice_id');

        return array_map('intval', $rows);
    }
}
