<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Student;
use app\common\model\SchoolClass;
use app\common\model\Lesson;
use app\common\model\StudentAttendance;
use app\common\model\StudentScore;
use app\common\model\StudentAiConversation;
use app\common\model\StudentAiMessage;
use app\common\service\AiProxy;

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
     * 学生学情摘要（教师仅限自己授课班级，管理员不限）
     * GET /api/studentadmin/learningProfile?student_id=1
     */
    public function learningProfile()
    {
        $studentId = intval($this->request->param('student_id', 0));
        if (!$studentId) return $this->fail('请指定学生');

        $student = Student::get($studentId);
        if (!$student) return $this->fail('学生不存在');
        if (!$this->user->isAdmin() &&
            !in_array((int)$student->class_id, $this->teacherClassIds(), true)) {
            return $this->fail('无权查看该学生学情', 403);
        }

        $attendance = StudentAttendance::where('student_id', $studentId)->select();
        $att = ['total' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0, 'early' => 0];
        foreach ($attendance as $row) {
            $status = (string)$row->status;
            $att['total']++;
            if (isset($att[$status])) $att[$status]++;
        }
        $att['rate'] = $att['total'] > 0 ? round($att['present'] / $att['total'], 4) : null;

        $scoreRows = StudentScore::where('student_id', $studentId)
            ->order('id desc')->limit(20)->select();
        $scoreSum = 0.0;
        $scoreCount = 0;
        $scores = [];
        foreach ($scoreRows as $row) {
            $score = $row->score;
            if ($score !== null && $score !== '') {
                $scoreSum += (float)$score;
                $scoreCount++;
            }
            $scores[] = [
                'course_name' => (string)$row->course_name,
                'title' => (string)$row->title,
                'score' => $score === null ? null : (float)$score,
                'grade' => (string)$row->grade,
                'comment' => (string)$row->comment,
                'created_at' => (int)$row->getData('created_at'),
            ];
        }

        $conversationCount = (int)StudentAiConversation::where('student_id', $studentId)->count();
        $messageCount = (int)StudentAiMessage::where('student_id', $studentId)->count();
        $questions = [];
        foreach (StudentAiMessage::where('student_id', $studentId)->where('role', 'user')
            ->order('id desc')->limit(10)->select() as $message) {
            $questions[] = [
                'content' => mb_substr((string)$message->content, 0, 200),
                'created_at' => (int)$message->getData('created_at'),
            ];
        }

        return $this->ok([
            'student' => $student->toSafeArray(),
            'attendance' => $att,
            'scores' => [
                'count' => count($scores),
                'average' => $scoreCount > 0 ? round($scoreSum / $scoreCount, 2) : null,
                'latest' => $scores,
            ],
            'ai' => [
                'conversation_count' => $conversationCount,
                'message_count' => $messageCount,
                'recent_questions' => $questions,
            ],
        ]);
    }

    /**
     * 生成学生学情建议（教师仅限自己授课班级，管理员不限）
     * POST /api/studentadmin/analyzeLearning
     * {"student_id":1}
     */
    public function analyzeLearning()
    {
        $data = $this->jsonInput();
        $studentId = intval(isset($data['student_id']) ? $data['student_id'] : 0);
        if (!$studentId) return $this->fail('请指定学生');

        $student = Student::get($studentId);
        if (!$student) return $this->fail('学生不存在');
        if (!$this->user->isAdmin() &&
            !in_array((int)$student->class_id, $this->teacherClassIds(), true)) {
            return $this->fail('无权分析该学生学情', 403);
        }

        $attendance = StudentAttendance::where('student_id', $studentId)->select();
        $att = ['total' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0, 'early' => 0];
        foreach ($attendance as $row) {
            $status = (string)$row->status;
            $att['total']++;
            if (isset($att[$status])) $att[$status]++;
        }
        $att['rate'] = $att['total'] > 0 ? round($att['present'] / $att['total'], 4) : null;

        $scores = StudentScore::where('student_id', $studentId)->order('id desc')->limit(20)->select();
        $scoreText = [];
        $sum = 0.0;
        $count = 0;
        foreach ($scores as $row) {
            $value = $row->score;
            if ($value !== null && $value !== '') {
                $sum += (float)$value;
                $count++;
            }
            $scoreText[] = [
                'course' => (string)$row->course_name,
                'item' => (string)$row->title,
                'score' => $value === null ? null : (float)$value,
                'grade' => (string)$row->grade,
            ];
        }

        $questions = [];
        foreach (StudentAiMessage::where('student_id', $studentId)->where('role', 'user')
            ->order('id desc')->limit(10)->select() as $message) {
            $questions[] = mb_substr((string)$message->content, 0, 200);
        }
        $context = '请作为中职教师的教学诊断助手，基于以下脱敏聚合数据给出简洁、可执行的学生学情建议。'
            . '输出：1.主要表现 2.可能困难 3.一周内干预建议 4.需要继续观察的指标。'
            . '不要猜测未提供的事实，不要输出隐私信息。学生姓名：' . mb_substr((string)$student->getData('name'), 0, 32)
            . '；出勤：' . json_encode($att, JSON_UNESCAPED_UNICODE)
            . '；成绩：' . json_encode(['average' => $count > 0 ? round($sum / $count, 2) : null, 'latest' => $scoreText], JSON_UNESCAPED_UNICODE)
            . '；最近提问摘要：' . json_encode($questions, JSON_UNESCAPED_UNICODE);

        try {
            $result = AiProxy::chat([
                'teacher_id' => (int)$this->user->id,
                'messages' => [
                    ['role' => 'system', 'content' => '你是严谨、克制的教学诊断助手。'],
                    ['role' => 'user', 'content' => $context],
                ],
                'source' => 'chat',
                'temperature' => 0.2,
                'ip' => $this->request->ip(),
            ]);
        } catch (\Throwable $e) {
            return $this->fail('学情分析失败，请稍后重试');
        }
        if (empty($result['ok'])) return $this->fail($result['msg'] ?: '学情分析失败');
        return $this->ok([
            'content' => (string)$result['content'],
            'model' => isset($result['model']) ? (string)$result['model'] : '',
            'points' => isset($result['points']) ? (float)$result['points'] : 0,
        ], '分析完成');
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
    // 批量导入
    // ============================================================

    /**
     * 批量导入学生
     * POST /api/studentadmin/importStudents
     * {"students":[{"sno":"...","name":"...","gender":1,"class_id":0,"year":2026,"phone":""}]}
     * 返回实际导入数 + 失败明细
     */
    public function importStudents()
    {
        if (!$this->user->isAdmin()) return $this->fail('仅限管理员', 403);

        $data = $this->jsonInput();
        $rows = isset($data['students']) && is_array($data['students']) ? $data['students'] : [];
        if (empty($rows)) return $this->fail('请提供学生数据');

        $imported = 0;
        $errors   = [];
        $year     = (int)date('Y');
        $now      = time();

        foreach ($rows as $idx => $item) {
            $sno      = trim(isset($item['sno']) ? $item['sno'] : '');
            $name     = trim(isset($item['name']) ? $item['name'] : '');
            $gender   = intval(isset($item['gender']) ? $item['gender'] : 0);
            $classId  = intval(isset($item['class_id']) ? $item['class_id'] : 0);
            $phone    = trim(isset($item['phone']) ? $item['phone'] : '');
            $syear    = intval(isset($item['year']) ? $item['year'] : 0);

            $line = $idx + 1;

            // 校验
            if ($sno === '' || !preg_match('/^[A-Za-z0-9_]{4,32}$/', $sno)) {
                $errors[] = "第{$line}行：学号格式无效（{$sno}）";
                continue;
            }
            if (mb_strlen($name) < 2 || mb_strlen($name) > 32) {
                $errors[] = "第{$line}行：姓名长度无效（{$name}）";
                continue;
            }
            if ($classId > 0 && !SchoolClass::get($classId)) {
                $errors[] = "第{$line}行：班级ID {$classId} 不存在";
                continue;
            }
            $exist = Student::where('sno', $sno)->find();
            if ($exist) {
                $errors[] = "第{$line}行：学号 {$sno} 已存在（学生ID: {$exist->id}）";
                continue;
            }

            Student::create([
                'sno'       => $sno,
                'password'  => Student::hashPassword($sno),
                'name'      => $name,
                'gender'    => $gender,
                'class_id'  => $classId,
                'phone'     => $phone,
                'year'      => $syear > 0 ? $syear : $year,
                'status'    => Student::STATUS_ACTIVE,
                'source'    => 'import',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $imported++;
        }

        $this->log('create', 'student', 0, "批量导入：成功 {$imported} 条" . ($errors ? "，失败 " . count($errors) . " 条" : ''));
        return $this->ok([
            'imported' => $imported,
            'errors'   => $errors,
        ], "导入完成：成功 {$imported} 条" . ($errors ? "，失败 " . count($errors) . " 条" : ''));
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
        if ((int)$lesson->deleted_at !== 0) return $this->fail('课时已删除');
        if (!$this->user->isAdmin() && (int)$lesson->teacher_id !== (int)$this->user->id) {
            return $this->fail('无权操作该课时', 403);
        }

        $inserted = 0;
        $now      = time();
        foreach ($students as $item) {
            $sid    = intval(isset($item['student_id']) ? $item['student_id'] : 0);
            $status = (string)(isset($item['status']) ? $item['status'] : 'present');
            $note   = trim(isset($item['note']) ? $item['note'] : '');
            if (!$sid) continue;
            $student = Student::get($sid);
            if (!$student) continue;
            if (!$this->user->isAdmin() && !$this->lessonContainsStudentClass($lesson, $student)) {
                continue;
            }

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
        if (mb_strlen($courseName) > 64) return $this->fail('课程名称不能超过 64 个字符');
        if (mb_strlen($title) > 100) return $this->fail('考核项名称不能超过 100 个字符');
        if (mb_strlen($grade) > 16) return $this->fail('等级不能超过 16 个字符');
        if (mb_strlen($comment) > 1000) return $this->fail('评语不能超过 1000 个字符');

        $s = Student::get($studentId);
        if (!$s) return $this->fail('学生不存在');
        if (!$this->user->isAdmin() && !in_array((int)$s->class_id, $this->teacherClassIds(), true)) {
            return $this->fail('无权操作该学生', 403);
        }
        if ($score !== null && !is_finite($score)) {
            return $this->fail('分数格式无效');
        }
        if ($score !== null && ($score < 0 || $score > 100)) {
            return $this->fail('分数必须在 0-100 之间');
        }

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

    /** 判断学生所属班级是否出现在该课时的班级快照中。 */
    private function lessonContainsStudentClass($lesson, $student)
    {
        $classId = (int)$student->class_id;
        if ($classId <= 0) return false;
        $class = SchoolClass::get($classId);
        if (!$class) return false;
        $className = trim((string)$class->getData('name'));
        if ($className === '') return false;
        $rawClasses = preg_split('/[,，;；\r\n]+/u', (string)$lesson->classes);
        $lessonClasses = array_filter(array_map('trim', $rawClasses));
        return in_array($className, $lessonClasses, true);
    }

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
            $parts = preg_split('/[,，;；\r\n]+/u', (string)$l->classes);
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