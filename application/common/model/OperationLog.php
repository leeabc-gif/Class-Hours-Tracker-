<?php
namespace app\common\model;

use think\Model;
use think\facade\Request;

/**
 * 操作日志：记录新增 / 修改 / 删除课时的操作人与时间
 */
class OperationLog extends Model
{
    protected $table    = 'ks_operation_log';
    protected $pk       = 'id';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'created_at';
    protected $updateTime = false;

    /** 动作文案映射 */
    const ACTIONS = [
        'create'    => '新增',
        'update'    => '修改',
        'delete'    => '删除',
        'restore'   => '恢复',
        'batch'     => '批量生成',
        'ai_import' => 'AI解析导入',
        'login'     => '登录',
        'logout'    => '退出',
        'export'    => '导出',
        'backup'    => '备份',
        'reset_pwd' => '重置密码',
        'toggle'    => '启用/禁用',
    ];

    /**
     * 写一条日志
     * @param int    $userId   操作人ID
     * @param string $userName 操作人姓名
     * @param string $action   动作
     * @param string $targetType 目标类型 lesson/teacher/course/term/setting/system
     * @param int    $targetId   目标ID
     * @param string $summary    摘要
     */
    public static function record($userId, $userName, $action, $targetType, $targetId, $summary)
    {
        return self::create([
            'user_id'     => intval($userId),
            'user_name'   => $userName,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => intval($targetId),
            'summary'     => mb_substr($summary, 0, 900, 'UTF-8'),
            'ip'          => Request::ip(),
        ]);
    }

    public function actionText()
    {
        return isset(self::ACTIONS[$this->action]) ? self::ACTIONS[$this->action] : $this->action;
    }
}
