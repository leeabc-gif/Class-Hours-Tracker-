<?php
namespace app\common\service;

use app\common\model\Course;
use app\common\model\CourseFavorite;
use app\common\model\Lesson;
use app\common\model\Notice;
use app\common\model\SchoolClass;
use app\common\model\Setting;
use app\common\model\Term;
use app\common\service\AiConfig;
use app\common\service\QuotaService;

/**
 * 前端启动数据服务
 *
 * 每个页面渲染时，把用户、枚举、学期、课程、配置一次性注入到
 * window.__BOOT__，前端首屏不再额外发请求。
 * 这样既少一轮往返，也保证了「枚举值只有后端一个来源」。
 */
class Boot
{
    /**
     * 公共启动数据
     */
    public static function common($user)
    {
        $term = Term::current();
        return [
            'user' => $user->toSafeArray(),
            'enums' => [
                'sections' => Lesson::SECTIONS,
                'weekdays' => Lesson::WEEKDAYS,
                'types'    => Lesson::TYPES,
                'sources'  => Lesson::SOURCES,
            ],
            'terms'           => self::terms(),
            'current_term_id' => $term ? (int)$term->id : 0,
            'current_term'    => $term ? self::termArray($term) : null,
            'settings'        => [
                'school_name'           => Setting::get('school_name', 'XX中等职业学校'),
                'global_price'          => (float)Setting::get('global_price', 60),
                'week_standard_periods' => (float)Setting::get('week_standard_periods', 12),
                'ai_enabled'            => (int)Setting::get('ai_enabled', 1),
                'ai'                    => AiConfig::publicForUser($user->id),
            ],
            'courses' => self::courses($user),
            'classes' => self::classes(),
            // AI 额度与未读公告：随首屏下发，页面无需再单独请求
            'ai_quota'      => QuotaService::summary($user->id),
            'notice_unread' => Notice::unreadCount($user->id, $user->getData('role')),
        ];
    }

    /**
     * 班级列表（仅返回启用的，按院系+排序）
     */
    public static function classes()
    {
        $out = [];
        foreach (SchoolClass::where('status', 1)->order('sort asc, id asc')->select() as $c) {
            $out[] = [
                'id'            => (int)$c->id,
                'name'          => $c->getData('name'),
                'department_id' => (int)$c->department_id,
            ];
        }
        return $out;
    }

    /**
     * 学期列表
     */
    public static function terms()
    {
        $out = [];
        foreach (Term::order('is_current desc, id desc')->select() as $t) {
            $out[] = self::termArray($t);
        }
        return $out;
    }

    public static function termArray($t)
    {
        return [
            'id'         => (int)$t->id,
            'name'       => $t->getData('name'),
            'start_week' => (int)$t->start_week,
            'end_week'   => (int)$t->end_week,
            'start_date' => $t->start_date,
            'is_current' => (int)$t->is_current,
        ];
    }

    /**
     * 当前用户可用课程（公共课程 + 本人私有课程，收藏排前面）
     *
     * @param \app\common\model\Teacher $user
     */
    public static function courses($user)
    {
        $list = Course::forTeacher($user->id, $user->isAdmin());
        $fav  = array_map('intval', CourseFavorite::idsOf($user->id));

        $out = [];
        foreach ($list as $c) {
            $out[] = [
                'id'        => (int)$c->id,
                'name'      => $c->getData('name'),
                'classes'   => $c->classes,
                'price'     => (float)$c->price,
                'owner'     => (int)$c->getData('teacher_id'),
                'favorited' => in_array((int)$c->id, $fav, true),
            ];
        }

        // 收藏优先，其次按课程名排序，快速录入时下拉更好找
        usort($out, function ($a, $b) {
            if ($a['favorited'] !== $b['favorited']) {
                return $a['favorited'] ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $out;
    }
}
