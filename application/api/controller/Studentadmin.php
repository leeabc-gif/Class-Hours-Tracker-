<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Student;
use app\common\model\SchoolClass;
use app\common\model\Lesson;
use app\common\model\StudentAttendance;
use app\common\model\StudentScore;

/**
 * 学生管理（管理员 + 教师）
 *
 * 路由：/api/studentadmin/xxx 自动映射到此控制器
 *
 * 权限规则：
 *   管理员：全量学生数据
 *   教师：仅能看到自己授课班级的学生（通过 ks_lesson.classes 文本字段模糊匹配）
 */
class Studentadmin extends Base
{
    protected function initialize()
    {
        parent::initialize();
        $this->requireLogin();
    }

    // ============================================================
    // 学生列表 / CRUD
    // ============================================================

    /**
     * 学生列表
     * GET /api/studentadmin/students?class_id=0&status=&keyword=&page=1
     */
    public function students()
    {
        $classId  = intval($this->request->param('class_id', 0));
        $status   = $this->request->param('status', '');
        $keyword  = trim($this->request->param('keyword', ''));
        $page     = max(1, intval($this->request->param('page', 1)));
        $pageSize = 20;

        $q = Student::order('id desc');

        if ($classId > 0) {
            $q->where('class_id', $classId);
        }
        if ($status !== '' && in_array((int)$status, [0, 1, 2], true)) {
            $q->where('status', (int)$status);
        }
        if ($keyword !== '') {
            $q->where(function($query) use ($keyword) {
                $query->where('sno', 'like', "%{$keyword}%")
                    ->whereOr('name', 'like', "%{$keyword}%");
            });
        }

        // 教师仅能看到自己教的班级
        if (!$this->user->isAdmin()) {
            $classIds = $this->teacherClassIds();
            if (empty($classIds)) {
                return $this->ok(['list' => [], 'total' => 0, 'page' => $page]);
            }
            $q->whereIn('class_id', $classIds);
        }

        $total = $q->count();
        $rows  = $q->page($page, $pageSize)->select();

        $list = [];
        foreach ($rows as $s) {
            $list[] = $s->toSafeArray();
        }

        return $this->ok([
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
        ]);
    }

    /**
     * 新增 / 编辑学生
     * POST /api/studentadmin/studentSave
     */
    public function studentSave()
    {
        if (!$this->user->isAdmin()) {
            return $this->fail('该功能仅限管理员', 403);
        }

        $data     = $this->jsonInput();
        $id       = intval(isset($data['id']) ? $data['id'] : 0);
        $sno      = trim(isset($data['sno']) ? $data['sno'] : '');
        $name     = trim(isset($data['name']) ? $data['name'] : '');
        $password = isset($data['password']) ? $data['password'] : '';
        $classId  = intval(isset($data['class_id']) ? $data['class_id'] : 0);
        $gender   = intval(isset($data['gender']) ? $data['gender'] : 0);
        $phone    = trim(isset($data['phone']) ? $data['phone'] : '');
        $year     = intval(isset($data['year']) ? $data['year'] : 0);
        $status   = isset($data['status']) ? (int)$data['status'] : 1;

        if ($sno === '' || !preg_match('/^[A-Za-z0-9_]{4,32}$/', $sno)) {
            return $this->fail('学号只能包含字母、数字和下划线，长度 4-32 位');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 32) {
            return $this->fail('姓名长度须为 2-32 个字符');
        }

        $exist = Student::where('sno', $sno)->where('id', '<>', $id)->find();
        if ($exist) return $this->fail('该学号已存在');

        if ($classId > 0 && !SchoolClass::get($classId)) {
            return $this->fail('所选班级不存在');
        }

        if ($id > 0) {
            $s = Student::get($id);
            if (!$s) return $this->fail('学生不存在');
            $s->sno      = $sno;
            $s->name     = $name;
            $s->class_id = $classId;
            $s->gender   = $gender;
            $s->phone    = $phone;
            if ($year > 0) $s->year = $year;
            if ($status >= 0) $s->status = $status;
            if ($password !== '') {
                $s->password = Student::hashPassword($password);
            }
            $s->save();
            $newId = $id;
        } else {
            $s = Student::create([
                'sno'      => $sno,
                'password' => Student::hashPassword($password !== '' ? $password : $sno),
                'name'     => $name,
                'class_id' => $classId,
                'gender'   => $gender,
                'phone'    => $phone,
                'year'     => $year > 0 ? $year : (int)date('Y'),
                'status'   => Student::STATUS_ACTIVE,
                'source'   => 'import',
            ]);
            $newId = (int)$s->id;
        }

        $this->log($id > 0 ? 'update' : 'create', 'student', $newId, "学生：{$name}（{$sno}）");
        return $this->ok(['id' => $newId], '已保存');
    }

    /**
     * 启用 / 禁用学生
     * POST /api/studentadmin/studentToggle
     */
    public function studentToggle()
    {
        if (!$this->user->isAdmin()) return $this->fail('仅限管理员', 403);

        $data   = $this->jsonInput();
        $id     = intval(isset($data['id']) ? $data['id'] : 0);
        $status = !empty($data['status']) ? Student::STATUS_ACTIVE : Student::STATUS_DISABLED;
        $s      = Student::get($id);
        if (!$s) return $this->fail('学生不存在');

        $s->status = $status;
        $s->save();
        $this->log('update', 'student', $id, ($status ? '启用' : '禁用') . '学生：' . $s->getData('name'));
        return $this->ok(null, $status ? '已启用' : '已禁用');
    }

    /**
     * 审核通过（待审核→正常）
     * POST /api/studentadmin/approveStudent
     */
    public function approveStudent()
    {
        if (!$this->user->isAdmin()) return $this->fail('仅限管理员', 403);

        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);
        $s    = Student::get($id);
        if (!$s) return $this->fail('学生不存在');
        if ((int)$s->status !== Student::STATUS_PENDING) {
            return $this->fail('该学生无需审核');
        }

        $s->status = Student::STATUS_ACTIVE;
        $s->save();
        $this->log('update', 'student', $id, '审核通过学生：' . $s->getData('name'));
        return $this->ok(null, '已审核通过');
    }

    /**
     * 待审核列表
     * GET /api/studentadmin/pendingList
     */
    public function pendingList()
    {
        if (!$this->user->isAdmin()) return $this->fail('仅限管理员', 403);

        $rows = Student::where('status', Student::STATUS_PENDING)
            ->order('id asc')
            ->select();
        $list = [];
        foreach ($rows as $s) {
            $list[] = $s->toSafeArray();
        }
        return $this->ok(['list' => $list]);
    }

    /**
     * 删除学生
     * POST /api/studentadmin/studentDelete
     */
    public function studentDelete()
    {
        if (!$this->user->isAdmin()) return $this->fail('仅限管理员', 403);

        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);
        $s    = Student::get($id);
        if (!$s) return $this->fail('学生不存在');

        $name = $s->getData('name');
        $sno  = $s->sno;
        $s->delete();
        $this->log('delete', 'student', $id, "删除学生：{$name}（{$sno}）");
        return $this->ok(null, '已删除');
    }

    // ============================================================
    // 出勤管理（教师录入）
    // ============================================================

    /**
     * 按课时批量录出勤
     * POST /api/studentadmin/recordAttendance
     * {"lesson_id":1,"students":[{"student_id":1,"status":"present","note":""},...]}
     */
    public function recordAttendance()
    {
        $data     = $this->jsonInput();
        $lessonId = intval(isset($data['lesson_id']) ? $data['lesson_id'] : 0);
        $students = isset($data['students']) && is_array($data['students']) ? $data['students'] : [];

        if (!$lessonId) return $this->fail('请指定课时');
        $lesson = Lesson::get($lessonId);
        if (!$lesson) return $this->fail('课时不存在');

        $inserted = 0;
        $now      = time();
        foreach ($students as $item) {
            $sid    = intval(isset($item['student_id']) ? $item['student_id'] : 0);
            $status = (string)(isset($item['status']) ? $item['status'] : 'present');
            $note   = trim(isset($item['note']) ? $item['note'] : '');
            if (!$sid) continue;

            $validStatuses = ['present','absent','leave','late','early'];
            if (!in_array($status, $validStatuses, true)) $status = 'present';

            // TP5.1 不支持 Model::create(..., true) replace，
            // 用先查后写模拟 upsert
            $exist = StudentAttendance::where('lesson_id', $lessonId)
                ->where('student_id', $sid)->find();
            if ($exist) {
                $exist->status    = $status;
                $exist->note      = $note;
                $exist->operator_id = (int)$this->user->id;
                $exist->updated_at  = $now;
                $exist->save();
            } else {
                StudentAttendance::create([
                    'lesson_id'   => $lessonId,
                    'student_id'  => $sid,
                    'class_id'    => 0,
                    'status'      => $status,
                    'note'        => $note,
                    'operator_id' => (int)$this->user->id,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
            $inserted++;
        }

        return $this->ok(['inserted' => $inserted], '已录入 ' . $inserted . ' 条出勤记录');
    }

    // ============================================================
    // 成绩管理
    // ============================================================

    /**
     * 录入成绩
     * POST /api/studentadmin/recordScore
     */
    public function recordScore()
    {
        $data = $this->jsonInput();

        $studentId  = intval(isset($data['student_id']) ? $data['student_id'] : 0);
        $courseId   = intval(isset($data['course_id']) ? $data['course_id'] : 0);
        $courseName = trim(isset($data['course_name']) ? $data['course_name'] : '');
        $termId     = intval(isset($data['term_id']) ? $data['term_id'] : 0);
        $title      = trim(isset($data['title']) ? $data['title'] : '');
        $score      = isset($data['score']) ? (float)$data['score'] : null;
        $grade      = trim(isset($data['grade']) ? $data['grade'] : '');
        $comment    = trim(isset($data['comment']) ? $data['comment'] : '');

        if (!$studentId) return $this->fail('请指定学生');
        if ($title === '') return $this->fail('请填写考核项名称');

        $s = Student::get($studentId);
        if (!$s) return $this->fail('学生不存在');

        $row = StudentScore::create([
            'student_id'  => $studentId,
            'class_id'    => (int)$s->class_id,
            'course_id'   => $courseId,
            'course_name' => $courseName,
            'term_id'     => $termId,
            'title'       => $title,
            'score'       => $score,
            'grade'       => $grade,
            'comment'     => $comment,
            'recorded_by' => (int)$this->user->id,
        ]);

        $this->log('create', 'student_score', (int)$row->id,
            "录入成绩：{$s->getData('name')} - {$title}");
        return $this->ok(['id' => (int)$row->id], '已记录');
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /** 教师授课班级 ID 列表 */
    private function teacherClassIds()
    {
        $tid = (int)$this->user->id;
        $lessons = Lesson::where('teacher_id', $tid)
            ->where('deleted_at', 0)
            ->field('classes')
            ->select();
        $names = [];
        foreach ($lessons as $l) {
            $parts = explode(',', (string)$l->classes);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $names[$p] = true;
            }
        }
        if (empty($names)) return [];

        $ids = [];
        foreach (array_keys($names) as $n) {
            $cls = SchoolClass::where('name', $n)->find();
            if ($cls) $ids[] = (int)$cls->id;
        }
        return array_unique($ids);
    }
}