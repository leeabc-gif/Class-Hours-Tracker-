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
        $status       = isset($data['status']) ? (int)$data['status'] : 1;
        $sort         = intval(isset($data['sort']) ? $data['sort'] : 0);
        $remark       = trim(isset($data['remark']) ? $data['remark'] : '');

        if ($name === '') {
            return $this->fail('请填写班级名称');
        }
        if (mb_strlen($name) > 32) {
            return $this->fail('班级名称不能超过 32 个字符');
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
        'update_manifest_url',
    ];

    public function settings()
    {
        $all = Setting::all();
        $out = [];
        foreach (self::SETTING_KEYS as $k) {
            if ($k === 'ai_api_key') continue;
            $out[$k] = isset($all[$k]) ? $all[$k] : '';
        }
        $out['ai_api_key_configured'] = trim((string)Setting::get('ai_api_key', '')) !== '';
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
        foreach (self::SETTING_KEYS as $k) {
            if (!array_key_exists($k, $data)) continue;
            $value = trim((string)$data[$k]);
            if ($k === 'ai_api_key' && $value === '' && empty($data['ai_api_key_clear'])) continue;
            if ($k === 'ai_api_key_clear') continue;
            if ($k === 'ai_enabled' && !in_array($value, ['0', '1'], true)) {
                return $this->fail('AI 总开关值不合法');
            }
            if ($k === 'ai_api_url' && $value !== '' && !preg_match('#^https?://#i', $value)) {
                return $this->fail('系统模型接口地址必须以 http:// 或 https:// 开头');
            }
            if ($k === 'ai_api_url' && strlen($value) > 500) {
                return $this->fail('系统模型接口地址不能超过 500 个字符');
            }
            if ($k === 'update_manifest_url' && $value !== '') {
                // 强制 https：清单必须经签名/强校验，且 SSRF 防护会更稳
                if (!preg_match('#^https://#i', $value)) {
                    return $this->fail('更新清单地址必须以 https:// 开头');
                }
                if (strlen($value) > 1000) {
                    return $this->fail('更新清单地址不能超过 1000 个字符');
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
                'created_at' => date('Y-m-d H:i:s', (int)$r->created_at),
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
     */
    public function updateStatus()
    {
        $cachedLatest = (string) Setting::get('update_last_check_latest', '');
        $cachedTime   = (string) Setting::get('update_last_check_at', '');
        return $this->ok([
            'current_version'   => UpdateService::currentVersion(),
            'maintenance'       => Base::underMaintenance(),
            'manifest_url'      => (string) Setting::get('update_manifest_url', ''),
            'app_version_baseline' => (string) config('app.version', '1.0.0'),
            'can_rollback'      => UpdateService::hasFileBackup(),
            'backups'           => UpdateService::listBackups(),
            'php_version'       => PHP_VERSION,
            // L-4：上次检查缓存的最新版本（首页刷新也能看到，不需点"检查更新"）
            'latest_version'    => $cachedLatest,
            'last_check_at'     => $cachedTime,
        ]);
    }

    /**
     * 检查更新：拉取远程 manifest 并对比
     */
    public function updateCheck()
    {
        $manifestUrl = trim((string) $this->input('manifest_url', ''));
        if ($manifestUrl === '') {
            $manifestUrl = (string) Setting::get('update_manifest_url', '');
        }
        if ($manifestUrl === '') {
            return $this->fail('请先在系统设置中填写更新清单地址（manifest_url）。');
        }
        try {
            $info = UpdateService::check($manifestUrl);
            // L-4：把 check 结果缓存下来，updateStatus 也能复用
            Setting::set('update_last_check_latest', (string) $info['latest_version']);
            Setting::set('update_last_check_at', (string) $info['checked_at']);
            $this->log('update_check', 'system', 0, '检查更新：当前 ' . $info['current_version'] . '，远程 ' . $info['latest_version']);
            return $this->ok($info);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[update] check failed: ' . $e->getMessage(), [
                'manifest_url' => $manifestUrl,
            ]);
            return $this->fail('检查更新失败：' . $e->getMessage());
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
            $manifestUrl = (string) Setting::get('update_manifest_url', '');
        }
        if ($manifestUrl === '') {
            return $this->fail('缺少更新清单地址。');
        }

        $lock = UpdateService::acquireLock();
        if (!$lock) {
            return $this->fail('已有更新任务正在进行，请稍后再试。');
        }
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
}
