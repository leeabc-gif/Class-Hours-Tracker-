<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Course as CourseModel;
use app\common\model\CourseFavorite;
use app\common\model\Lesson;

/**
 * 课程库接口
 *
 * 课程归属规则：
 *   teacher_id = 0 → 全校公共课程（管理员维护，所有教师可见）
 *   teacher_id > 0 → 教师私有课程（仅本人可见，单价可与公共库不同）
 * 教师新增课程时一律建成自己的私有课程，不会污染公共库。
 */
class Course extends Base
{
    /**
     * 当前用户可用课程列表（公共 + 本人私有，收藏置顶）
     */
    public function index()
    {
        $this->requireLogin();
        return $this->ok(\app\common\service\Boot::courses($this->user));
    }

    /**
     * 新增 / 修改课程
     */
    public function save()
    {
        $this->requireLogin();
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $name  = trim(isset($data['name']) ? $data['name'] : '');
        $price = floatval(isset($data['price']) ? $data['price'] : 0);
        $classes = trim(isset($data['classes']) ? $data['classes'] : '');

        if ($name === '') {
            return $this->fail('请填写课程名称');
        }
        if (mb_strlen($name, 'UTF-8') > 64) {
            return $this->fail('课程名称不能超过 64 个字');
        }
        if ($price < 0 || $price > 100000) {
            return $this->fail('课时单价不合法');
        }

        if ($id > 0) {
            $course = CourseModel::get($id);
            if (!$course) {
                return $this->fail('课程不存在');
            }
            // 权限：公共课程只有管理员能改，私有课程只有本人能改
            $owner = (int)$course->getData('teacher_id');
            if ($owner == 0 && !$this->user->isAdmin()) {
                return $this->fail('全校公共课程由管理员维护，如需单独定价请另建个人课程');
            }
            if ($owner > 0 && !$this->user->isAdmin() && $owner != $this->user->id) {
                return $this->fail('无权修改他人的课程');
            }
            $teacherId = $owner;
        } else {
            // 新增：管理员可建公共课程（is_public=1），教师只能建自己的
            $isPublic  = $this->user->isAdmin() && !empty($data['is_public']);
            $teacherId = $isPublic ? 0 : $this->user->id;
        }

        // 同一归属下课程名不可重复
        $exist = CourseModel::findByName($name, $teacherId);
        if ($exist && $exist->id != $id) {
            return $this->fail($teacherId == 0 ? '公共课程库已存在同名课程' : '你已创建过同名课程');
        }

        $payload = [
            'teacher_id' => $teacherId,
            'name'       => $name,
            'classes'    => $classes,
            'price'      => $price,
            'status'     => 1,
        ];

        if ($id > 0) {
            $course->save($payload);
            $newId  = $id;
            $action = 'update';
            $msg    = '课程已更新';
        } else {
            $course = CourseModel::create($payload);
            $newId  = $course->id;
            $action = 'create';
            $msg    = '课程已添加';
        }

        $actionTxt = $action === 'create' ? '新增' : '修改';
        $this->log($action, 'course', $newId, "{$actionTxt}课程：{$name}（单价 {$price} 元/节）");

        return $this->ok(['id' => $newId], $msg);
    }

    /**
     * 删除课程（有课时引用时禁止删除，避免历史记录失去课程归属）
     */
    public function delete()
    {
        $this->requireLogin();
        $data   = $this->jsonInput();
        $course = CourseModel::get(intval($data['id']));
        if (!$course) {
            return $this->fail('课程不存在');
        }
        $owner = (int)$course->getData('teacher_id');
        if ($owner == 0 && !$this->user->isAdmin()) {
            return $this->fail('全校公共课程由管理员维护');
        }
        if ($owner > 0 && !$this->user->isAdmin() && $owner != $this->user->id) {
            return $this->fail('无权删除他人的课程');
        }

        $used = Lesson::where('course_id', $course->id)->where('deleted_at', 0)->count();
        if ($used > 0) {
            return $this->fail("该课程已被 {$used} 条课时记录引用，不能删除");
        }

        $name = $course->getData('name');
        CourseFavorite::where('course_id', $course->id)->delete();
        $course->delete();

        $this->log('delete', 'course', $course->id, "删除课程：{$name}");
        return $this->ok(null, '已删除');
    }

    /**
     * 收藏 / 取消收藏
     */
    public function favorite()
    {
        $this->requireLogin();
        $data     = $this->jsonInput();
        $courseId = intval($data['course_id']);
        $course   = CourseModel::get($courseId);
        if (!$course) {
            return $this->fail('课程不存在');
        }
        if (!CourseModel::visibleTo($course, $this->user->id)) {
            return $this->fail('无权收藏该课程');
        }

        $fav = CourseFavorite::toggle($this->user->id, $courseId);
        return $this->ok(['favorited' => $fav], $fav ? '已加入常用课程' : '已取消收藏');
    }
}
