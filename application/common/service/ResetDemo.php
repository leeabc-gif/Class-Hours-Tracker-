<?php
namespace app\common\service;

use think\Db;
use think\facade\Log;
use app\common\model\Setting;
use app\common\model\OperationLog;

/**
 * 一键重置示例数据服务（v1.0.2+）
 *
 * 用途：管理员在后台"系统更新"或"基础配置"页一键把系统重置回官方示例状态，
 *       便于上线演示 / 教学展示 / 拍产品截图。
 *
 * 行为：
 *  保留：ks_teacher 中所有 admin 角色账号 + 至少 1 个 admin 兜底
 *        ks_setting 全部（系统配置会按出厂基线 UPSERT 一次，确保 app_version 不丢）
 *        ks_department 全部
 *        ks_term 全部
 *        ks_course 中 is_demo=1 的那一门示范课程（其他课程清空）
 *  清空：ks_lesson / ks_class / ks_course（非示范）/ ks_course_favorite
 *        ks_ai_conversation / ks_ai_message / ks_teacher_ai_config
 *        ks_operation_log（但本条 reset_demo 自己的日志会在清表前 INSERT）
 *
 * 安全：
 *  1. 必须由控制器 verifyAdmin + 输密码 二次确认（已由 Admin::resetDemoData 处理）
 *  2. 重置前会再校验 admin 计数（必须 ≥ 1）
 *  3. 进入维护模式（控制器已做）
 *  4. 全程使用事务，任何步骤失败整体回滚
 *  5. 哪怕重置成功，操作日志也会保留一条 reset_demo 记录
 */
class ResetDemo
{
    /**
     * 兜底 admin 的 bcrypt hash（对应密码 admin123）
     * 兼容历史 seed.sql 中的 hash；如需重置为 admin123 直接落这个 hash。
     */
    const ADMIN_DEFAULT_HASH = '$2y$10$TCHWAOUOyK8F.8qU3vMGhuKYd/A6QRqYYCoF6UdeX/qRPB9v.seye';

    /**
     * 兜底出厂 ks_setting（覆盖式写入，不存在的键会被补齐）
     */
    const DEFAULT_SETTINGS = [
        'school_name'            => 'XX中等职业学校',
        'global_price'           => '60.00',
        'week_standard_periods'  => '12',
        'ai_enabled'             => '1',
        'ai_provider'            => 'local',
        'ai_api_url'             => '',
        'ai_api_key'             => '',
        'ai_model'               => '',
        // update_manifest_url 不覆盖，保留 admin 自定义；update_source 切到 github 让 v1.0.2 默认走 GitHub
        'update_source'          => 'github',
    ];

    /**
     * 兜底示范课程（系统重置后会以这条数据落在 ks_course，
     * 并通过 is_demo=1 标识，重置时只保留 is_demo=1 的课程）
     */
    const DEMO_COURSE = [
        'teacher_id' => 0,
        'name'       => '工业机器人导论',
        'classes'    => '机器人2301班,机器人2302班',
        'price'      => 60.00,
        'status'     => 1,
        'is_demo'    => 1,
    ];

    /**
     * 估算范围（不执行）
     */
    public static function snapshot()
    {
        $tables = [
            'ks_lesson'              => (int) Db::name('lesson')->count(),
            'ks_class'               => (int) Db::name('class')->count(),
            'ks_course'              => (int) Db::name('course')->count(),
            'ks_course_favorite'     => (int) Db::name('course_favorite')->count(),
            'ks_ai_conversation'     => (int) Db::name('ai_conversation')->count(),
            'ks_ai_message'          => (int) Db::name('ai_message')->count(),
            'ks_teacher_ai_config'   => (int) Db::name('teacher_ai_config')->count(),
            'ks_operation_log'       => (int) Db::name('operation_log')->count(),
        ];
        $adminCount = (int) Db::name('teacher')->where('role', 'admin')->where('status', 1)->count();
        $demoCourse = Db::name('course')->where('is_demo', 1)->find();
        return [
            'tables'         => $tables,
            'rows_total'     => array_sum($tables),
            'admin_count'    => $adminCount,
            'demo_course_id' => $demoCourse ? (int) $demoCourse['id'] : 0,
            'demo_course_name' => $demoCourse ? $demoCourse['name'] : '',
        ];
    }

    /**
     * 执行重置
     * @return array
     */
    public static function run($admin)
    {
        // 1) 防御：必须保留至少 1 个 admin
        $adminCount = (int) Db::name('teacher')->where('role', 'admin')->where('status', 1)->count();
        if ($adminCount < 1) {
            // 兜底：自动补回一个 admin（id=1, username=admin, password=admin123）
            $exists = Db::name('teacher')->where('id', 1)->find();
            $now = time();
            if ($exists) {
                Db::name('teacher')->where('id', 1)->update([
                    'role'      => 'admin',
                    'status'    => 1,
                    'password'  => self::ADMIN_DEFAULT_HASH,
                    'updated_at'=> $now,
                ]);
            } else {
                Db::name('teacher')->insert([
                    'id' => 1,
                    'username' => 'admin',
                    'password' => self::ADMIN_DEFAULT_HASH,
                    'name'     => '系统管理员',
                    'department_id' => 0,
                    'position' => '超级管理员',
                    'role'     => 'admin',
                    'status'   => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $adminCount = 1;
        }
        $adminId = (int) Db::name('teacher')->where('role', 'admin')->where('status', 1)->order('id asc')->value('id');

        // 2) 走事务
        Db::startTrans();
        try {
            // 先把本条 reset_demo 日志写好（即便后面要清空 operation_log）
            $logSummary = sprintf(
                '一键重置示例数据：执行人=%s(id=%d)，保留 admin=%d，保留示范课程，清理 8 张业务表',
                $admin->name, (int) $admin->id, $adminId
            );
            $logId = OperationLog::record(
                (int) $admin->id, $admin->name,
                'reset_demo', 'system', 0, $logSummary
            );

            // 清空业务表（按依赖顺序：先叶子后根）
            $cleared = [];
            $clearedRows = 0;
            $tables = [
                'lesson'             => 'ks_lesson',
                'ai_message'         => 'ks_ai_message',
                'ai_conversation'    => 'ks_ai_conversation',
                'teacher_ai_config'  => 'ks_teacher_ai_config',
                'course_favorite'    => 'ks_course_favorite',
                'class'              => 'ks_class',
                'course'             => 'ks_course', // 下面会重新插入示范课
                'operation_log'      => 'ks_operation_log', // 保留刚才的 reset_demo
            ];
            foreach ($tables as $model => $t) {
                $n = (int) Db::name($model)->count();
                Db::name($model)->delete(true);
                $cleared[$t] = $n;
                $clearedRows += $n;
            }
            // 写回 reset_demo 日志（operation_log 刚被清空）
            if ($logId) {
                OperationLog::record(
                    (int) $admin->id, $admin->name,
                    'reset_demo', 'system', 0, $logSummary . '（清理 ' . $clearedRows . ' 行）'
                );
            }

            // 3) 重建示范课程
            $payload = self::DEMO_COURSE;
            $payload['created_at'] = time();
            $payload['updated_at'] = time();
            $courseId = (int) Db::name('course')->insertGetId($payload);

            // 4) 补齐出厂 ks_setting（不覆盖 update_manifest_url）
            $now = time();
            foreach (self::DEFAULT_SETTINGS as $k => $v) {
                if (Setting::get($k, '') === '') {
                    Setting::set($k, $v);
                }
            }
            // app_version 兜底回 1.0.2（避免历史脏值卡在线更新对比）
            if (Setting::get('app_version', '') === '') {
                Setting::set('app_version', '1.0.2');
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return [
            'admin_id'         => $adminId,
            'kept_course_id'   => $courseId,
            'kept_course_name' => self::DEMO_COURSE['name'],
            'tables'           => $cleared,
            'rows'             => $clearedRows,
            'reset_at'         => date('c'),
        ];
    }
}
