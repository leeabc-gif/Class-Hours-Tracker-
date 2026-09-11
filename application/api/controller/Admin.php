<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\model\Course as CourseModel;
use app\common\model\CourseFavorite;
use app\common\model\Department;
use app\common\model\Lesson as LessonModel;
use app\common\model\OperationLog;
use app\common\model\SchoolClass;
use app\common\model\Setting;
use app\common\model\TeacherAiConfig;
use app\common\model\Teacher as TeacherModel;
use app\common\model\Term as TermModel;
use app\common\service\Stats;
use app\common\service\AiConfig;
use app\common\service\UpdateService;

/**
 * 管理员接口
 *
 * 全部接口统一 requireAdmin()，把权限判断集中在一处，
 * 避免以后加新接口时漏写校验。
 */
class Admin extends Base
{
    protected function initialize()
    {
        parent::initialize();
        // 所有管理员接口统一在这里拦截，新增接口时不会漏掉权限校验
        $this->requireAdmin();
    }

    // ============================================================
    // 教师账号管理
    // ============================================================

    /**
     * 教师账号列表（含课时统计）
     */
    public function teachers()
    {
        $termId = intval($this->input('term_id', 0));
        $stats  = [];
        foreach (Stats::byTeacher($termId) as $r) {
            $stats[$r['teacher_id']] = $r;
        }

        $list = [];
        foreach (TeacherModel::order('role asc, id asc')->select() as $t) {
            $row = $t->toSafeArray();
            $row['periods'] = isset($stats[$t->id]) ? $stats[$t->id]['periods'] : 0;
            $row['amount']  = isset($stats[$t->id]) ? $stats[$t->id]['amount'] : 0;
            $row['cnt']     = isset($stats[$t->id]) ? $stats[$t->id]['cnt'] : 0;
            $row['is_self'] = ($t->id == $this->user->id);
            $list[] = $row;
        }
        return $this->ok($list);
    }

    /**
     * 新增 / 编辑教师账号
     */
    public function teacherSave()
    {
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $username = trim(isset($data['username']) ? $data['username'] : '');
        $name     = trim(isset($data['name']) ? $data['name'] : '');
        $role     = isset($data['role']) ? $data['role'] : 'teacher';
        $deptId   = intval(isset($data['department_id']) ? $data['department_id'] : 0);
        $position = trim(isset($data['position']) ? $data['position'] : '');

        if ($username === '') {
            return $this->fail('请填写登录账号');
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            return $this->fail('登录账号只能由字母、数字、下划线组成，长度 3-32 位');
        }
        if ($name === '') {
            return $this->fail('请填写姓名');
        }
        if (!in_array($role, ['admin', 'teacher'], true)) {
            return $this->fail('角色不合法');
        }

        $exist = TeacherModel::where('username', $username)->where('id', '<>', $id)->find();
        if ($exist) {
            return $this->fail('该登录账号已存在');
        }

        if ($id > 0) {
            $t = TeacherModel::get($id);
            if (!$t) {
                return $this->fail('账号不存在');
            }
            // 不允许把自己从管理员降级，防止系统失去唯一管理员
            if ($t->id == $this->user->id && $t->isAdmin() && $role !== 'admin') {
                return $this->fail('不能把自己的管理员权限降级');
            }
            $t->username      = $username;
            $t->name          = $name;
            $t->department_id = $deptId;
            $t->position      = $position;
            $t->role          = $role;
            $t->save();
            $newId  = $id;
            $action = 'update';
            $msg    = '账号已更新';
        } else {
            $password = trim(isset($data['password']) ? $data['password'] : '');
            if (strlen($password) < 6) {
                return $this->fail('初始密码至少 6 位');
            }
            $t = TeacherModel::create([
                'username'      => $username,
                'password'      => TeacherModel::hashPassword($password),
                'name'          => $name,
                'department_id' => $deptId,
                'position'      => $position,
                'role'          => $role,
                'status'        => 1,
            ]);
            $newId  = $t->id;
            $action = 'create';
            $msg    = '账号已创建';
        }

        $this->log($action, 'teacher', $newId,
            ($action === 'create' ? '新增' : '修改') . "账号：{$name}（{$username}，{$role}）");

        return $this->ok(['id' => $newId], $msg);
    }

    /**
     * 启用 / 禁用账号
     */
    public function teacherToggle()
    {
        $data = $this->jsonInput();
        $t    = TeacherModel::get(intval($data['id']));
        if (!$t) {
            return $this->fail('账号不存在');
        }
        if ($t->id == $this->user->id) {
            return $this->fail('不能禁用自己的账号');
        }

        $t->status = $t->status ? 0 : 1;
        $t->save();

        $this->log('toggle', 'teacher', $t->id,
            ($t->status ? '启用' : '禁用') . "账号：{$t->getData('name')}");

        return $this->ok(['status' => (int)$t->status], $t->status ? '已启用' : '已禁用');
    }

    /**
     * 重置密码（管理员初始化 / 找回）
     */
    public function teacherResetPwd()
    {
        $data = $this->jsonInput();
        $t    = TeacherModel::get(intval($data['id']));
        if (!$t) {
            return $this->fail('账号不存在');
        }
        $pwd = trim(isset($data['password']) ? $data['password'] : '');
        if (strlen($pwd) < 6) {
            return $this->fail('新密码至少 6 位');
        }
        $t->password = TeacherModel::hashPassword($pwd);
        $t->save();

        $this->log('reset_pwd', 'teacher', $t->id, "重置密码：{$t->getData('name')}");

        return $this->ok(null, '密码已重置，请通过安全渠道告知用户。');
    }

    /**
     * 删除账号（已有课时记录的账号只允许禁用，不允许删除）
     */
    public function teacherDelete()
    {
        $data = $this->jsonInput();
        $t    = TeacherModel::get(intval($data['id']));
        if (!$t) {
            return $this->fail('账号不存在');
        }
        if ($t->id == $this->user->id) {
            return $this->fail('不能删除自己的账号');
        }
        $used = LessonModel::where('teacher_id', $t->id)->count();
        if ($used > 0) {
            return $this->fail("该教师已有 {$used} 条课时记录，删除会导致数据失去归属，请改为「禁用」");
        }

        $name = $t->getData('name');
        TeacherAiConfig::where('teacher_id', $t->id)->delete();
        $t->delete();
        $this->log('delete', 'teacher', $t->id, "删除账号：{$name}");

        return $this->ok(null, '已删除');
    }

    // ============================================================
    // 课时全表（管理员视角）
    // ============================================================

    /**
     * 全校课时列表：分页 + 多维筛选（教师维度不再是数据隔离前提）
     *
     * 几乎复用 Lesson::index 的 buildScope，但 teacher_id 允许 0（=全校）
     */
    public function lessons()
    {
        $scope = [];
        $tid = intval($this->input('teacher_id', 0));
        if ($tid) {
            $scope['teacher_id'] = $tid;
        }
        foreach (['term_id', 'course_id', 'week', 'weekday', 'section'] as $k) {
            $v = intval($this->input($k, 0));
            if ($v) $scope[$k] = $v;
        }
        foreach (['type', 'month', 'keyword'] as $k) {
            $v = trim((string) $this->input($k, ''));
            if ($v !== '') $scope[$k] = $v;
        }
        $sd = trim((string) $this->input('start_date', ''));
        $ed = trim((string) $this->input('end_date', ''));
        if ($sd !== '' && $ed !== '') {
            $scope['start_date'] = $sd;
            $scope['end_date']   = $ed;
        }
        $includeDeleted = intval($this->input('include_deleted', 0)) === 1;

        $page  = max(1, intval($this->input('page', 1)));
        $limit = min(200, max(1, intval($this->input('limit', 20))));

        $baseQ = $includeDeleted
            ? LessonModel::where('deleted_at', '>=', 0)
            : LessonModel::where('deleted_at', 0);

        // 复用一个工厂函数构造 query
        $make = function () use ($scope, $baseQ) {
            $q = clone $baseQ;
            if (!empty($scope['teacher_id']))  $q->where('teacher_id',  $scope['teacher_id']);
            if (!empty($scope['term_id']))     $q->where('term_id',     $scope['term_id']);
            if (!empty($scope['course_id']))   $q->where('course_id',   $scope['course_id']);
            if (!empty($scope['type']))        $q->where('type',        $scope['type']);
            if (!empty($scope['week']))        $q->where('week',        $scope['week']);
            if (!empty($scope['weekday']))     $q->where('weekday',     $scope['weekday']);
            if (!empty($scope['section']))     $q->where('section',     $scope['section']);
            if (!empty($scope['month']))       $q->where('teach_date', 'like', $scope['month'] . '%');
            if (!empty($scope['keyword'])) {
                $kw = '%' . $scope['keyword'] . '%';
                $q->where(function ($sq) use ($kw) {
                    $sq->where('course_name', 'like', $kw)
                       ->whereOr('classes', 'like', $kw)
                       ->whereOr('remark', 'like', $kw);
                });
            }
            if (!empty($scope['start_date']) && !empty($scope['end_date'])) {
                $q->where('teach_date', 'between', [$scope['start_date'], $scope['end_date']]);
            }
            return $q;
        };

        $total = $make()->count();
        $rows  = $make()
            ->order('deleted_at asc, week desc, weekday asc, section asc')
            ->page($page, $limit)
            ->select();

        $list = [];
        foreach ($rows as $r) {
            $row = $r->toFullArray();
            $row['teacher_name'] = $r->teacherName();
            $row['is_deleted']   = $r->getData('deleted_at') > 0 ? 1 : 0;
            $list[] = $row;
        }

        $sum = $make()
            ->field('COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt')
            ->find();

        return $this->ok([
            'list'  => $list,
            'total' => (int)$total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => (int)ceil($total / $limit),
            'summary' => [
                'cnt'     => (int)$sum['cnt'],
                'periods' => (float)$sum['periods'],
                'amount'  => (float)$sum['amount'],
            ],
        ]);
    }

    /**
     * 管理员一键批量删除课时
     *
     * payload 与 Lesson::batchDelete 一致，
     * 只是 scope 里的 teacher_id 允许为 0（=全校），
     * 仍然要求非空 scope（不允许"无任何条件全删整个学校"
     * 这种一个误操作就把数据库打光的活）。
     */
    public function lessonBatchDelete()
    {
        $data = $this->jsonInput();
        $ids  = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
        $mode = isset($data['mode']) ? trim(strval($data['mode'])) : '';
        $note = isset($data['note']) ? trim(strval($data['note'])) : '';

        if ($mode !== 'all') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) {
                return $i > 0;
            })));
            if (empty($ids)) {
                return $this->fail('请先勾选记录');
            }
            if (count($ids) > 1000) {
                return $this->fail('单次最多删除 1000 条');
            }
            $q = LessonModel::where('id', 'in', $ids)->where('deleted_at', 0);
            $rows = $q->select();
            $count = 0;
            foreach ($rows as $r) {
                $r->softDelete();
                $count++;
            }
            $this->log('batch_delete', 'lesson', 0, sprintf(
                '管理员批量删除课时 %d 条（ids 共 %d 个，note=%s）',
                $count, count($ids), $note
            ));
            return $this->ok(['deleted' => $count], "已删除 {$count} 条");
        }

        // 全量：必须带至少一个过滤条件
        $scope = [];
        $tid = intval(isset($data['teacher_id']) ? $data['teacher_id'] : 0);
        if ($tid) $scope['teacher_id'] = $tid;
        foreach (['term_id', 'course_id', 'week', 'weekday', 'section'] as $k) {
            $v = intval(isset($data[$k]) ? $data[$k] : 0);
            if ($v) $scope[$k] = $v;
        }
        foreach (['type', 'month', 'keyword'] as $k) {
            $v = trim((string) (isset($data[$k]) ? $data[$k] : ''));
            if ($v !== '') $scope[$k] = $v;
        }
        $sd = trim((string) (isset($data['start_date']) ? $data['start_date'] : ''));
        $ed = trim((string) (isset($data['end_date']) ? $data['end_date'] : ''));
        if ($sd !== '' && $ed !== '') {
            $scope['start_date'] = $sd;
            $scope['end_date']   = $ed;
        }
        if (empty($scope)) {
            return $this->fail('一键删除全部必须至少带一个筛选条件（教师/学期/课程/日期…），以防误操作');
        }

        $count = LessonModel::batchSoftDeleteByScope($scope);
        $this->log('batch_delete', 'lesson', 0, sprintf(
            '管理员按筛选条件批量删除课时 %d 条（scope=%s, note=%s）',
            $count,
            json_encode($scope, JSON_UNESCAPED_UNICODE),
            $note
        ));
        return $this->ok(['deleted' => $count], "已删除 {$count} 条");
    }

    /**
     * 管理员一键批量恢复
     */
    public function lessonBatchRestore()
    {
        $data = $this->jsonInput();
        $ids  = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
        $mode = isset($data['mode']) ? trim(strval($data['mode'])) : '';

        if ($mode !== 'all') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) {
                return $i > 0;
            })));
            if (empty($ids)) {
                return $this->fail('请先勾选要恢复的记录');
            }
            $q = LessonModel::where('id', 'in', $ids)->where('deleted_at', '>', 0);
            $rows = $q->select();
            $restored = 0;
            $conflict = 0;
            foreach ($rows as $r) {
                $dup = LessonModel::findDup(
                    $r->teacher_id, $r->term_id,
                    $r->week, $r->weekday, $r->section,
                    $r->id
                );
                if ($dup) {
                    $conflict++;
                    continue;
                }
                $r->restore();
                $restored++;
            }
            $this->log('batch_restore', 'lesson', 0, sprintf(
                '管理员批量恢复课时：成功 %d 条，跳过 %d 条（冲突）',
                $restored, $conflict
            ));
            $msg = "已恢复 {$restored} 条";
            if ($conflict > 0) {
                $msg .= "，{$conflict} 条因时段冲突未恢复";
            }
            return $this->ok(['restored' => $restored, 'conflict' => $conflict], $msg);
        }

        $scope = [];
        $tid = intval(isset($data['teacher_id']) ? $data['teacher_id'] : 0);
        if ($tid) $scope['teacher_id'] = $tid;
        foreach (['term_id', 'course_id'] as $k) {
            $v = intval(isset($data[$k]) ? $data[$k] : 0);
            if ($v) $scope[$k] = $v;
        }
        foreach (['type', 'month', 'keyword'] as $k) {
            $v = trim((string) (isset($data[$k]) ? $data[$k] : ''));
            if ($v !== '') $scope[$k] = $v;
        }
        $sd = trim((string) (isset($data['start_date']) ? $data['start_date'] : ''));
        $ed = trim((string) (isset($data['end_date']) ? $data['end_date'] : ''));
        if ($sd !== '' && $ed !== '') {
            $scope['start_date'] = $sd;
            $scope['end_date']   = $ed;
        }
        if (empty($scope)) {
            return $this->fail('一键恢复全部必须至少带一个筛选条件');
        }

        $count = LessonModel::batchRestoreByScope($scope);
        $this->log('batch_restore', 'lesson', 0, sprintf(
            '管理员按筛选条件批量恢复课时 %d 条（scope=%s）',
            $count,
            json_encode($scope, JSON_UNESCAPED_UNICODE)
        ));
        return $this->ok(['restored' => $count], "已恢复 {$count} 条");
    }

    /**
     * 管理员删除前预估数量（双确认弹窗用）
     */
    public function lessonBatchDeletePreview()
    {
        $data = $this->jsonInput();
        $ids  = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
        $mode = isset($data['mode']) ? trim(strval($data['mode'])) : '';

        if ($mode !== 'all') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) {
                return $i > 0;
            })));
            if (empty($ids)) {
                return $this->fail('请先勾选记录');
            }
            $q = LessonModel::where('id', 'in', $ids)->where('deleted_at', 0);
            $count = $q->count();
            $sum   = $q->field('COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount')->find();
            return $this->ok([
                'count'   => (int)$count,
                'periods' => (float)$sum['periods'],
                'amount'  => (float)$sum['amount'],
                'mode'    => 'ids',
            ]);
        }

        $scope = [];
        $tid = intval(isset($data['teacher_id']) ? $data['teacher_id'] : 0);
        if ($tid) $scope['teacher_id'] = $tid;
        foreach (['term_id', 'course_id', 'week', 'weekday', 'section'] as $k) {
            $v = intval(isset($data[$k]) ? $data[$k] : 0);
            if ($v) $scope[$k] = $v;
        }
        foreach (['type', 'month', 'keyword'] as $k) {
            $v = trim((string) (isset($data[$k]) ? $data[$k] : ''));
            if ($v !== '') $scope[$k] = $v;
        }
        $sd = trim((string) (isset($data['start_date']) ? $data['start_date'] : ''));
        $ed = trim((string) (isset($data['end_date']) ? $data['end_date'] : ''));
        if ($sd !== '' && $ed !== '') {
            $scope['start_date'] = $sd;
            $scope['end_date']   = $ed;
        }
        if (empty($scope)) {
            return $this->ok(['count' => 0, 'periods' => 0, 'amount' => 0, 'mode' => 'all', 'need_filter' => true]);
        }
        $q = LessonModel::scopeQuery($scope);
        $count = $q->count();
        $sum   = $q->field('COALESCE(SUM(periods),0) AS periods, COALESCE(SUM(amount),0) AS amount')->find();
        return $this->ok([
            'count'   => (int)$count,
            'periods' => (float)$sum['periods'],
            'amount'  => (float)$sum['amount'],
            'mode'    => 'all',
        ]);
    }

    // ============================================================
    // 院系
    // ============================================================

    public function departments()
    {
        $list = [];
        foreach (Department::order('sort asc, id asc')->select() as $d) {
            $list[] = [
                'id'            => (int)$d->id,
                'name'          => $d->getData('name'),
                'sort'          => (int)$d->sort,
                'status'        => (int)$d->status,
                'teacher_count' => $d->teacherCount(),
            ];
        }
        return $this->ok($list);
    }

    public function departmentSave()
    {
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);
        $name = trim(isset($data['name']) ? $data['name'] : '');
        $sort = intval(isset($data['sort']) ? $data['sort'] : 0);

        if ($name === '') {
            return $this->fail('请填写院系名称');
        }
        $exist = Department::where('name', $name)->where('id', '<>', $id)->find();
        if ($exist) {
            return $this->fail('该院系已存在');
        }

        if ($id > 0) {
            $d = Department::get($id);
            if (!$d) return $this->fail('院系不存在');
            $d->name = $name;
            $d->sort = $sort;
            $d->save();
            $newId = $id;
        } else {
            $d = Department::create(['name' => $name, 'sort' => $sort, 'status' => 1]);
            $newId = $d->id;
        }

        $this->log($id > 0 ? 'update' : 'create', 'setting', $newId, "维护院系：{$name}");
        return $this->ok(['id' => $newId], '已保存');
    }

    public function departmentDelete()
    {
        $data = $this->jsonInput();
        $d    = Department::get(intval($data['id']));
        if (!$d) {
            return $this->fail('院系不存在');
        }
        $used = TeacherModel::where('department_id', $d->id)->count();
        if ($used > 0) {
            return $this->fail("该院系下还有 {$used} 位教师，不能删除");
        }
        $name = $d->getData('name');
        $d->delete();
        $this->log('delete', 'setting', $d->id, "删除院系：{$name}");
        return $this->ok(null, '已删除');
    }

    // ============================================================
    // 班级
    // ============================================================

    public function classes()
    {
        $list = [];
        foreach (SchoolClass::order('sort asc, id asc')->select() as $c) {
            $list[] = [
                'id'            => (int)$c->id,
                'name'          => $c->getData('name'),
                'department_id' => (int)$c->department_id,
                'department'    => $c->departmentName(),
                'year'          => (int)$c->year,
                'join_code'     => (string)$c->getData('join_code'),
                'status'        => (int)$c->status,
                'sort'          => (int)$c->sort,
                'remark'        => $c->getData('remark'),
                'lesson_count'  => $c->lessonCount(),
            ];
        }
        return $this->ok($list);
    }

    public function classSave()
    {
        $data         = $this->jsonInput();
        $id           = intval(isset($data['id']) ? $data['id'] : 0);
        $name         = trim(isset($data['name']) ? $data['name'] : '');
        $departmentId = intval(isset($data['department_id']) ? $data['department_id'] : 0);
        $year         = intval(isset($data['year']) ? $data['year'] : 0);
        $joinCode     = trim(isset($data['join_code']) ? $data['join_code'] : '');
        $status       = isset($data['status']) ? (int)$data['status'] : 1;
        $sort         = intval(isset($data['sort']) ? $data['sort'] : 0);
        $remark       = trim(isset($data['remark']) ? $data['remark'] : '');

        if ($name === '') {
            return $this->fail('请填写班级名称');
        }
        if (mb_strlen($name) > 32) {
            return $this->fail('班级名称不能超过 32 个字符');
        }
        // 口令：留空=不开放自助注册；非空限 4-16 位字母数字
        if ($joinCode !== '' && !preg_match('/^[A-Za-z0-9]{4,16}$/', $joinCode)) {
            return $this->fail('班级注册口令须为 4-16 位字母或数字，留空则不开放');
        }
        if ($departmentId > 0 && !Department::get($departmentId)) {
            return $this->fail('所选院系不存在');
        }
        $exist = SchoolClass::where('name', $name)->where('id', '<>', $id)->find();
        if ($exist) {
            return $this->fail('该班级名称已存在');
        }

        if ($id > 0) {
            $c = SchoolClass::get($id);
            if (!$c) return $this->fail('班级不存在');
            $c->name          = $name;
            $c->department_id = $departmentId;
            $c->year          = $year;
            $c->join_code     = $joinCode;
            $c->status        = $status;
            $c->sort          = $sort;
            $c->remark        = $remark;
            $c->save();
            $newId = $id;
        } else {
            $c = SchoolClass::create([
                'name'          => $name,
                'department_id' => $departmentId,
                'year'          => $year,
                'join_code'     => $joinCode,
                'status'        => $status,
                'sort'          => $sort,
                'remark'        => $remark,
            ]);
            $newId = $c->id;
        }
        $this->log($id > 0 ? 'update' : 'create', 'setting', $newId, "维护班级：{$name}");
        return $this->ok(['id' => $newId], '已保存');
    }

    public function classDelete()
    {
        $data = $this->jsonInput();
        $c    = SchoolClass::get(intval($data['id']));
        if (!$c) {
            return $this->fail('班级不存在');
        }
        $name = $c->getData('name');
        $c->delete();
        $this->log('delete', 'setting', $c->id, "删除班级：{$name}");
        return $this->ok(null, '已删除');
    }

    // ============================================================
    // 学期
    // ============================================================

    public function terms()
    {
        $list = [];
        foreach (TermModel::order('is_current desc, id desc')->select() as $t) {
            $row = \app\common\service\Boot::termArray($t);
            $row['lesson_count'] = LessonModel::where('term_id', $t->id)->where('deleted_at', 0)->count();
            $list[] = $row;
        }
        return $this->ok($list);
    }

    public function termSave()
    {
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $name      = trim(isset($data['name']) ? $data['name'] : '');
        $startWeek = intval(isset($data['start_week']) ? $data['start_week'] : 1);
        $endWeek   = intval(isset($data['end_week']) ? $data['end_week'] : 20);
        $startDate = trim(isset($data['start_date']) ? $data['start_date'] : '');
        $isCurrent = !empty($data['is_current']) ? 1 : 0;

        if ($name === '') {
            return $this->fail('请填写学期名称');
        }
        if ($startWeek < 1 || $endWeek > 52 || $endWeek < $startWeek) {
            return $this->fail('周次范围不合法（起始周 ≥ 1，结束周 ≤ 52 且不早于起始周）');
        }
        if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return $this->fail('开学日期格式应为 YYYY-MM-DD');
        }

        $payload = [
            'name'       => $name,
            'start_week' => $startWeek,
            'end_week'   => $endWeek,
            'start_date' => $startDate !== '' ? $startDate : null,
        ];

        if ($id > 0) {
            $t = TermModel::get($id);
            if (!$t) return $this->fail('学期不存在');
            $t->save($payload);
            $newId = $id;
        } else {
            $t = TermModel::create($payload);
            $newId = $t->id;
        }

        if ($isCurrent) {
            TermModel::setCurrent($newId);
        }

        $this->log($id > 0 ? 'update' : 'create', 'term', $newId,
            "维护学期：{$name}（第{$startWeek}-{$endWeek}周" . ($isCurrent ? '，已设为当前学期' : '') . '）');

        return $this->ok(['id' => $newId], '已保存');
    }

    public function termSetCurrent()
    {
        $data = $this->jsonInput();
        $t    = TermModel::get(intval($data['id']));
        if (!$t) {
            return $this->fail('学期不存在');
        }
        TermModel::setCurrent($t->id);
        $this->log('update', 'term', $t->id, "设为当前学期：{$t->getData('name')}");
        return $this->ok(null, '已设为当前学期');
    }

    public function termDelete()
    {
        $data = $this->jsonInput();
        $t    = TermModel::get(intval($data['id']));
        if (!$t) {
            return $this->fail('学期不存在');
        }
        $used = LessonModel::where('term_id', $t->id)->count();
        if ($used > 0) {
            return $this->fail("该学期下已有 {$used} 条课时记录，不能删除");
        }
        $name = $t->getData('name');
        $t->delete();
        $this->log('delete', 'term', $t->id, "删除学期：{$name}");
        return $this->ok(null, '已删除');
    }

    // ============================================================
    // 全校课程库
    // ============================================================

    public function courses()
    {
        $list = [];
        foreach (CourseModel::order('teacher_id asc, id asc')->select() as $c) {
            $owner = (int)$c->getData('teacher_id');
            $teacher = $owner ? TeacherModel::get($owner) : null;
            $list[] = [
                'id'          => (int)$c->id,
                'name'        => $c->getData('name'),
                'classes'     => $c->classes,
                'price'       => (float)$c->price,
                'status'      => (int)$c->status,
                'teacher_id'  => $owner,
                'teacher'     => $teacher ? $teacher->getData('name') : '全校公共',
                'lesson_count' => LessonModel::where('course_id', $c->id)->where('deleted_at', 0)->count(),
            ];
        }
        return $this->ok($list);
    }

    public function courseSave()
    {
        $data = $this->jsonInput();
        $id   = intval(isset($data['id']) ? $data['id'] : 0);

        $name    = trim(isset($data['name']) ? $data['name'] : '');
        $classes = trim(isset($data['classes']) ? $data['classes'] : '');
        $price   = floatval(isset($data['price']) ? $data['price'] : 0);
        $owner   = intval(isset($data['teacher_id']) ? $data['teacher_id'] : 0);

        if ($name === '') {
            return $this->fail('请填写课程名称');
        }
        if ($price < 0 || $price > 100000) {
            return $this->fail('课时单价不合法');
        }
        $exist = CourseModel::findByName($name, $owner);
        if ($exist && $exist->id != $id) {
            return $this->fail($owner == 0 ? '公共课程库已存在同名课程' : '该教师已创建过同名课程');
        }

        $payload = [
            'teacher_id' => $owner,
            'name'       => $name,
            'classes'    => $classes,
            'price'      => $price,
            'status'     => 1,
        ];

        if ($id > 0) {
            $c = CourseModel::get($id);
            if (!$c) return $this->fail('课程不存在');
            $c->save($payload);
            $newId = $id;
        } else {
            $c = CourseModel::create($payload);
            $newId = $c->id;
        }

        $this->log($id > 0 ? 'update' : 'create', 'course', $newId,
            ($id > 0 ? '修改' : '新增') . "课程：{$name}（{$price} 元/节）");

        return $this->ok(['id' => $newId], '已保存');
    }

    public function courseDelete()
    {
        $data = $this->jsonInput();
        $c    = CourseModel::get(intval($data['id']));
        if (!$c) {
            return $this->fail('课程不存在');
        }
        // 只统计未软删除的引用（与课程列表 lesson_count 口径一致），避免课时已删课程仍无法删除
        $used = LessonModel::where('course_id', $c->id)->where('deleted_at', 0)->count();
        if ($used > 0) {
            return $this->fail("该课程已被 {$used} 条课时记录引用，不能删除");
        }
        $name = $c->getData('name');
        CourseFavorite::where('course_id', $c->id)->delete(); // 同步清理收藏关系，避免孤儿行
        $c->delete();
        $this->log('delete', 'course', $c->id, "删除课程：{$name}");
        return $this->ok(null, '已删除');
    }

    // ============================================================
    // 系统配置
    // ============================================================

    /** 允许管理员直接修改的配置键（白名单，避免写入任意配置） */
    const SETTING_KEYS = [
        'school_name', 'global_price', 'week_standard_periods',
        'ai_enabled', 'ai_provider', 'ai_api_url', 'ai_api_key', 'ai_model',
        'update_manifest_url', 'update_source', 'update_github_token',
    ];

    /**
     * GitHub Releases 默认 URL（v1.0.2 起的默认更新源）
     *
     * 走 GitHub 公开 REST API 的 "latest release" 接口：
     *   https://api.github.com/repos/<owner>/<repo>/releases/latest
     * UpdateService::check 会先 GET 该 API 拿 tag_name，再去
     *   https://github.com/<owner>/<repo>/releases/download/<tag>/manifest.json
     * 拉真正的 manifest。这样公开仓库匿名也能用，避开 404。
     */
    public static function defaultGithubManifestUrl()
    {
        return 'https://api.github.com/repos/leeabc-gif/Class-Hours-Tracker-/releases/latest';
    }

    /**
     * CNB 官方 Release 默认 URL
     *
     * v1.0.8 曾写死为具体 tag（.../download/v1.0.8/manifest.json），因为当时
     * CNB 的 latest/download 直链在 is_latest=false 时会 404。
     * v1.1.2 起改回 latest 入口：UpdateService::resolveCnbLatest() 会抓
     * <owner>/<repo>/-/releases 列表（匿名可访问、返回 JSON）自动挑出最大版本号 tag，
     * 再拼成 .../download/<tag>/manifest.json。这样发新版无需再改这里的硬编码，
     * 用户后台才能"自动发现新版本 → 直接点更新"。
     */
    public static function defaultCnbManifestUrl()
    {
        return 'https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/manifest.json';
    }

    /**
     * 根据 update_source 拼出实际可用的 manifest URL
     * - github（v1.0.2 默认）：返回 GitHub Releases 默认 URL（可被管理员在 update_manifest_url 覆写）
     * - cnb（v1.0.1 兼容）：返回 CNB 官方默认 URL（可被管理员在 update_manifest_url 覆写）
     * - custom：返回 update_manifest_url 自定义值（必须由管理员手动填写）
     * - 空 / 其他：返回 update_manifest_url 现存值（兜底）
     */
    public static function resolveManifestUrl()
    {
        $source = (string) Setting::get('update_source', 'github');
        $custom = (string) Setting::get('update_manifest_url', '');
        if ($source === 'github') {
            return $custom !== '' ? $custom : self::defaultGithubManifestUrl();
        }
        if ($source === 'cnb') {
            return $custom !== '' ? $custom : self::defaultCnbManifestUrl();
        }
        if ($source === 'custom') {
            return $custom;
        }
        return $custom !== '' ? $custom : self::defaultGithubManifestUrl();
    }

    public function settings()
    {
        $all = Setting::all();
        $out = [];
        foreach (self::SETTING_KEYS as $k) {
            // 敏感字段不回显明文
            if ($k === 'ai_api_key' || $k === 'update_github_token') continue;
            $out[$k] = isset($all[$k]) ? $all[$k] : '';
        }
        $out['ai_api_key_configured']   = trim((string)Setting::get('ai_api_key', ''))       !== '';
        $out['update_github_token_configured'] = trim((string)Setting::get('update_github_token', '')) !== '';
        $out['resolved_manifest_url']   = self::resolveManifestUrl();
        return $this->ok($out);
    }

    public function settingsSave()
    {
        $data = $this->jsonInput();
        $saved = [];
        if (!empty($data['ai_api_key_clear'])) {
            Setting::set('ai_api_key', '');
            $saved[] = 'ai_api_key';
        }
        if (!empty($data['update_github_token_clear'])) {
            Setting::set('update_github_token', '');
            $saved[] = 'update_github_token';
        }
        foreach (self::SETTING_KEYS as $k) {
            if (!array_key_exists($k, $data)) continue;
            $value = trim((string)$data[$k]);
            if ($k === 'ai_api_key' && $value === '' && empty($data['ai_api_key_clear'])) continue;
            if ($k === 'ai_api_key_clear') continue;
            if ($k === 'update_github_token' && $value === '' && empty($data['update_github_token_clear'])) continue;
            if ($k === 'update_github_token_clear') continue;
            if ($k === 'ai_enabled' && !in_array($value, ['0', '1'], true)) {
                return $this->fail('AI 总开关值不合法');
            }
            if ($k === 'ai_api_url' && $value !== '' && !preg_match('#^https?://#i', $value)) {
                return $this->fail('系统模型接口地址必须以 http:// 或 https:// 开头');
            }
            if ($k === 'ai_api_url' && strlen($value) > 500) {
                return $this->fail('系统模型接口地址不能超过 500 个字符');
            }
            if ($k === 'update_source') {
                if (!in_array($value, ['github', 'cnb', 'custom'], true)) {
                    return $this->fail('更新源类型不合法（github / cnb / custom）');
                }
                // 切到 github 时把空 manifest 兜底填上默认 URL，方便小白开箱即用
                if ($value === 'github') {
                    $currentCustom = (string) Setting::get('update_manifest_url', '');
                    if ($currentCustom === '') {
                        Setting::set('update_manifest_url', self::defaultGithubManifestUrl());
                    }
                }
                Setting::set('update_source', $value);
                $saved[] = 'update_source';
                continue;
            }
            if ($k === 'update_github_token' && $value !== '') {
                // GitHub PAT 形式校验：ghp_/gho_/ghu_/ghs_/ghr_ 前缀 36+ 位；放宽到 ≥ 30 位
                if (strlen($value) < 20 || strlen($value) > 200) {
                    return $this->fail('GitHub Token 长度不合理（20-200 字符）');
                }
            }
            if ($k === 'update_manifest_url' && $value !== '') {
                // 强制 https：清单必须经签名/强校验，且 SSRF 防护会更稳
                if (!preg_match('#^https://#i', $value)) {
                    return $this->fail('更新清单地址必须以 https:// 开头');
                }
                if (strlen($value) > 1000) {
                    return $this->fail('更新清单地址不能超过 1000 个字符');
                }
                // 防止把更新包(ZIP)地址误存为清单地址
                $mp = parse_url($value, PHP_URL_PATH);
                if (is_string($mp) && stripos($mp, '.zip') !== false) {
                    return $this->fail('更新清单地址不能是更新包(ZIP)地址，请填 manifest.json 的地址（形如 .../releases/download/v<版本>/manifest.json）');
                }
                // 写入前调用 UpdateService 做一次轻量校验，避免脏数据落库
                try {
                    \app\common\service\UpdateService::validateManifestUrl($value);
                } catch (\Throwable $uv) {
                    return $this->fail('更新清单地址不安全：' . $uv->getMessage());
                }
            }
            if ($k === 'ai_model' && strlen($value) > 100) {
                return $this->fail('系统模型名称不能超过 100 个字符');
            }
            if ($k === 'ai_provider' && strlen($value) > 32) {
                return $this->fail('AI 引擎标识不能超过 32 个字符');
            }
            Setting::set($k, $value);
            $saved[] = $k;
        }
        $this->log('update', 'setting', 0, '修改系统配置：' . implode(', ', $saved));
        return $this->ok(null, '配置已保存');
    }

    // ============================================================
    // 操作日志
    // ============================================================

    public function logs()
    {
        $page  = max(1, intval($this->input('page', 1)));
        $limit = min(200, max(1, intval($this->input('limit', 20))));
        $scope = [];

        $uid = intval($this->input('user_id', 0));
        $action = trim($this->input('action', ''));
        $kw   = trim($this->input('keyword', ''));

        // 用静态调用起手，避免直接 new 模型再链式 where 的不确定性
        $q = OperationLog::where('id', '>', 0);
        if ($uid)    $q->where('user_id', $uid);
        if ($action) $q->where('action', $action);
        if ($kw)     $q->where('summary', 'like', '%' . $kw . '%');

        $total = $q->count();
        $rows  = $q->order('id desc')->page($page, $limit)->select();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'         => (int)$r->id,
                'user_id'    => (int)$r->user_id,
                'user_name'  => $r->user_name,
                'action'     => $r->action,
                'action_txt' => $r->actionText(),
                'target'     => $r->target_type,
                'target_id'  => (int)$r->target_id,
                'summary'    => $r->summary,
                'ip'         => $r->ip,
                'created_at' => date('Y-m-d H:i:s', (int)$r->getData('created_at')),
            ];
        }

        return $this->ok([
            'list'  => $list,
            'total' => (int)$total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => (int)ceil($total / $limit),
        ]);
    }

    /**
     * 拉取系统模型接口的模型列表
     * Key 未填时兜底使用已保存的系统 Key
     */
    public function aiModels()
    {
        $data = $this->jsonInput();
        $url  = trim(isset($data['api_url']) ? (string) $data['api_url'] : '');
        $key  = trim(isset($data['api_key']) ? (string) $data['api_key'] : '');
        if ($key === '') {
            $key = (string) Setting::get('ai_api_key', '');
        }
        $r = AiConfig::fetchModels($url, $key);
        if (!$r['ok']) return $this->fail($r['msg']);
        return $this->ok(['models' => $r['models']], '已拉取 ' . count($r['models']) . ' 个模型');
    }

    // ============================================================
    // 系统更新（在线更新）
    // ============================================================

    /**
     * 更新状态：当前版本 / 维护模式 / 更新源地址
     *
     * v1.1.2：支持 ?auto=1 静默自动检查。前端进入"系统更新"页时带上该参数，
     * 若距上次检查已超过 6 小时（或从未检查过），就顺手拉一次远程清单，
     * 这样用户一进页面就能看到"有新版本可用"，不必先手动点"检查更新"。
     * 自动检查失败只记录 last_check_error，绝不让 updateStatus 整体失败。
     */
    public function updateStatus()
    {
        // v1.1.3：新代码就位后，幂等补齐 v1.1.x 所需的 7 张新表
        // （v1.1.3 作为"垫脚石版本"，upgrade.sql 不含 DDL 以兼容旧 UpdateService 的事务包；
        //  建表改由新代码在首次访问更新页时惰性执行，此时新代码已到位不会再报
        //  "There is no active transaction"）
        try {
            UpdateService::ensureSchemaIfNeeded();
        } catch (\Throwable $e) {
            // 建表失败不阻塞状态接口，仅记日志
            \think\facade\Log::warning('[updateStatus] ensureSchema failed: ' . $e->getMessage());
        }

        // 自动检查（静默、失败不影响状态返回）
        if ((string) $this->input('auto', '') === '1') {
            $this->autoCheckIfStale();
        }

        $cachedLatest = (string) Setting::get('update_last_check_latest', '');
        $cachedTime   = (string) Setting::get('update_last_check_at', '');
        $cachedError  = (string) Setting::get('update_last_check_error', '');
        $current      = UpdateService::currentVersion();

        // v1.1.2：后端直接算出"是否有新版本"，前端不用自己比字符串
        $updateAvailable = false;
        if ($cachedLatest !== '' && $current !== '') {
            $updateAvailable = version_compare($cachedLatest, $current, '>');
        }

        return $this->ok([
            'current_version'   => $current,
            'maintenance'       => Base::underMaintenance(),
            'manifest_url'      => self::resolveManifestUrl(),
            'manifest_url_custom' => (string) Setting::get('update_manifest_url', ''),
            'update_source'     => (string) Setting::get('update_source', ''),
            'app_version_baseline' => (string) config('app.version', '1.0.0'),
            'can_rollback'      => UpdateService::hasFileBackup(),
            'backups'           => UpdateService::listBackups(),
            'php_version'       => PHP_VERSION,
            // L-4：上次检查缓存的最新版本（首页刷新也能看到，不需点"检查更新"）
            'latest_version'    => $cachedLatest,
            'last_check_at'     => $cachedTime,
            // v1.0.3：失败原因，让顶部直接显示为什么「最新版本」是 —
            'last_check_error'  => $cachedError,
            // v1.1.2：给前端渲染"有新版本可用"横幅用
            'update_available'  => $updateAvailable,
        ]);
    }

    /**
     * 距上次检查超过 TTL 就静默拉一次远程清单，用于进页面自动提示新版本。
     * 任何异常都吞掉（只写 last_check_error），不能影响 updateStatus 主流程。
     */
    protected function autoCheckIfStale($ttlSeconds = 21600)
    {
        try {
            $lastAt = (string) Setting::get('update_last_check_at', '');
            if ($lastAt !== '') {
                $ts = strtotime($lastAt);
                if ($ts && (time() - $ts) < $ttlSeconds) {
                    return; // 还新鲜，不重复请求远端
                }
            }
            $manifestUrl = self::resolveManifestUrl();
            if ($manifestUrl === '') return;

            UpdateService::setBearerToken((string) Setting::get('update_github_token', ''));
            $info = UpdateService::check($manifestUrl);
            Setting::set('update_last_check_latest', (string) $info['latest_version']);
            Setting::set('update_last_check_at', (string) $info['checked_at']);
            Setting::set('update_last_check_error', '');
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            if (strlen($err) > 480) $err = substr($err, 0, 480) . '…';
            Setting::set('update_last_check_at', date('Y-m-d H:i:s'));
            Setting::set('update_last_check_error', $err);
            \think\facade\Log::error('[update] auto check failed: ' . $e->getMessage());
        }
    }


    /**
     * 检查更新：拉取远程 manifest 并对比
     */
    public function updateCheck()
    {
        $manifestUrl = trim((string) $this->input('manifest_url', ''));
        if ($manifestUrl === '') {
            $manifestUrl = self::resolveManifestUrl();
        }
        if ($manifestUrl === '') {
            return $this->fail('请先在系统设置中选择更新源或填写更新清单地址（manifest_url）。');
        }
        // v1.0.2：注入管理员配置的 GitHub Token（公开仓库可空）
        UpdateService::setBearerToken((string) Setting::get('update_github_token', ''));
        // v1.0.3：无论成功失败都刷新「上次检查时间」，避免失败时卡在旧值误导用户
        $now = date('Y-m-d H:i:s');
        try {
            $info = UpdateService::check($manifestUrl);
            Setting::set('update_last_check_latest', (string) $info['latest_version']);
            Setting::set('update_last_check_at', (string) $info['checked_at']);
            Setting::set('update_last_check_error', ''); // 成功就清掉旧错误
            $this->log('update_check', 'system', 0, '检查更新：当前 ' . $info['current_version'] . '，远程 ' . $info['latest_version']);
            return $this->ok($info);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[update] check failed: ' . $e->getMessage(), [
                'manifest_url' => $manifestUrl,
            ]);
            $err = $e->getMessage();
            // v1.0.3：失败也写时间 + 错误摘要，updateStatus 端能直接显示给用户
            Setting::set('update_last_check_at', $now);
            // 截短避免 key 过长
            if (strlen($err) > 480) $err = substr($err, 0, 480) . '…';
            Setting::set('update_last_check_error', $err);
            return $this->fail('检查更新失败：' . $err);
        }
    }

    /**
     * 执行更新：check → download → backup(文件+库) → 维护 → apply → 解除维护
     */
    public function updateInstall()
    {
        $data = $this->jsonInput();
        $manifestUrl = trim(isset($data['manifest_url']) ? (string) $data['manifest_url'] : '');
        if ($manifestUrl === '') {
            $manifestUrl = self::resolveManifestUrl();
        }
        if ($manifestUrl === '') {
            return $this->fail('缺少更新清单地址。');
        }

        $lock = UpdateService::acquireLock();
        if (!$lock) {
            return $this->fail('已有更新任务正在进行，请稍后再试。');
        }
        // v1.0.2：注入 GitHub Token
        UpdateService::setBearerToken((string) Setting::get('update_github_token', ''));
        try {
            $info = UpdateService::check($manifestUrl);
            if (empty($info['files'])) {
                return $this->fail('更新清单中无可用更新包。');
            }
            if (!$info['update_available']) {
                return $this->ok(['skipped' => true, 'msg' => '已是最新版本（' . $info['current_version'] . '）。'], '已是最新版本');
            }

            // 环境预检：PHP 版本下限 / 起始版本要求，不满足拒绝升级
            UpdateService::assertEnvCompatible($info);

            $pkg = UpdateService::download($info['files'][0], $manifestUrl);
            if ((string) $pkg['version'] !== (string) $info['latest_version']) {
                throw new \RuntimeException('更新包版本与更新清单不一致，已拒绝安装。');
            }

            // 备份将被覆盖的文件 + 整库备份（含 SQL 时尤其重要）
            $fileBackup = UpdateService::backup($pkg['path']);
            $dbBackup   = UpdateService::backupDatabase();

            // 进入维护模式
            Base::setMaintenance(true);

            try {
                // 先更新代码 / SQL
                UpdateService::apply($pkg['path']);
                // 成功后写版本号
                $targetVer = $info['latest_version'];
                Setting::set('app_version', $targetVer);
                Setting::set('update_manifest_url', $manifestUrl);
                Base::setMaintenance(false);
                // 成功后收尾：裁剪旧备份、清理历史更新包，避免无限堆积（失败不影响升级结果）
                try {
                    UpdateService::pruneBackups();
                    UpdateService::cleanupPackages();
                } catch (\Throwable $ce) {
                    // 仅记录，不阻断
                }
                $this->log('update', 'system', 0,
                    '系统升级 ' . $info['current_version'] . ' → ' . $targetVer .
                    '；备份文件 ' . $fileBackup['files'] . ' 个，数据库备份 ' . $dbBackup['tables'] . ' 表');
                return $this->ok([
                    'from'    => $info['current_version'],
                    'to'      => $targetVer,
                    'backup'  => $fileBackup,
                    'db_backup' => $dbBackup,
                ], '更新成功');
            } catch (\Throwable $e) {
                // 尝试回滚文件与 SQL；回滚失败必须显式告知，不能静默
                $rollbackMsg = '';
                try {
                    UpdateService::rollback($pkg['path']);
                    $rollbackMsg = '（已自动回滚到升级前文件）';
                } catch (\Throwable $re) {
                    $rollbackMsg = '（自动回滚也失败：' . $re->getMessage() . '，请尽快用备份手工恢复）';
                }
                Base::setMaintenance(false);
                throw new \RuntimeException($e->getMessage() . $rollbackMsg, 0, $e);
            }
        } catch (\Throwable $e) {
            Base::setMaintenance(false);
            // M-3：失败信息统一走 Log::error（含 traceAsString），不再在 runtime 留裸堆栈文件
            // （运维从 ks_operation_log / runtime/log 即可定位），避免敏感路径外泄
            \think\facade\Log::error('[update] failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'manifest_url' => $manifestUrl,
            ]);
            return $this->fail('更新失败：' . $e->getMessage());
        } finally {
            UpdateService::releaseLock($lock);
        }
    }

    /**
     * 手动回滚：用最近一次文件备份还原，并按备份元信息回退版本号；
     * 若最近下载的更新包含 downgrade.sql 会一并执行。
     */
    public function updateRollback()
    {
        if (!UpdateService::hasFileBackup()) {
            return $this->fail('没有可用的文件备份，无法回滚。');
        }
        $lock = UpdateService::acquireLock();
        if (!$lock) {
            return $this->fail('已有更新任务正在进行，请稍后再试。');
        }
        try {
            $before = UpdateService::currentVersion();
            Base::setMaintenance(true);
            try {
                // 最近下载的更新包可能内含 downgrade.sql，用于回退表结构
                $r = UpdateService::rollback(UpdateService::latestPackage());
            } finally {
                Base::setMaintenance(false);
            }
            $after = UpdateService::currentVersion();
            $this->log('update_rollback', 'system', 0,
                '手动回滚：v' . $before . ' → v' . $after . '，还原文件 ' . $r['restored_files'] . ' 个；删除新文件 ' . $r['removed_new_files'] . ' 个');
            return $this->ok([
                'from'              => $before,
                'to'                => $after,
                'restored_files'    => $r['restored_files'],
                'removed_new_files' => $r['removed_new_files'],
            ], '已回滚到上一版本，请刷新页面确认。');
        } catch (\Throwable $e) {
            Base::setMaintenance(false);
            \think\facade\Log::error('[update] rollback failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('回滚失败：' . $e->getMessage());
        } finally {
            UpdateService::releaseLock($lock);
        }
    }

    /**
     * 手动进入/退出维护模式
     */
    public function maintenanceToggle()
    {
        $data  = $this->jsonInput();
        $state = isset($data['maintenance']) ? (int) $data['maintenance'] : -1;
        if ($state === 1) {
            Base::setMaintenance(true);
        } elseif ($state === 0) {
            Base::setMaintenance(false);
        } else {
            return $this->fail('参数错误');
        }
        $this->log('maintenance', 'system', 0, $state === 1 ? '开启维护模式' : '关闭维护模式');
        return $this->ok(['maintenance' => Base::underMaintenance()]);
    }

    // ============================================================
    // 一键重置示例数据（v1.0.2+）
    // ============================================================

    /**
     * 估算重置范围（不执行）
     */
    public function resetDemoDataPreview()
    {
        try {
            $tables = \app\common\service\ResetDemo::snapshot();
            return $this->ok($tables);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[reset_demo_preview] failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('无法获取重置预览：' . $e->getMessage());
        }
    }

    /**
     * 一键重置：保留 admin 账号 + 1 门示范课程 + 基础系统设置，
     * 清空其他业务表。
     *
     * 三重保险：
     *  1. 必须是 admin 角色（已由 initialize() 拦截）
     *  2. 前端必须二次确认（传 confirm='RESET'）
     *  3. 必须再输一次当前管理员密码
     */
    public function resetDemoData()
    {
        $data = $this->jsonInput();
        $confirm  = trim(isset($data['confirm'])  ? (string) $data['confirm']  : '');
        $password = trim(isset($data['password']) ? (string) $data['password'] : '');
        if ($confirm !== 'RESET') {
            return $this->fail('请在前端弹窗中输入 RESET 以确认操作');
        }
        if ($password === '') {
            return $this->fail('请输入当前管理员密码以确认身份');
        }
        if (!$this->user->checkPassword($password)) {
            return $this->fail('管理员密码错误，操作已拒绝');
        }

        // 进入维护模式，防止教师在重置过程中继续操作
        Base::setMaintenance(true);
        try {
            $r = \app\common\service\ResetDemo::run($this->user);
        } catch (\Throwable $e) {
            Base::setMaintenance(false);
            \think\facade\Log::error('[reset_demo] failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('重置失败：' . $e->getMessage());
        }
        Base::setMaintenance(false);
        $this->log('reset_demo', 'system', 0, sprintf(
            '一键重置示例数据：保留 admin=%d，保留示范课程 id=%s，清理 %d 张业务表共 %d 行；保留 ks_setting/ks_department/ks_term/ks_teacher(admin)',
            $r['admin_id'], $r['kept_course_id'], count($r['tables']), $r['rows']
        ));
        return $this->ok($r, '已重置为示例数据，系统已自动恢复运行');
    }
}
