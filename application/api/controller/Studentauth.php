<?php
namespace app\api\controller;

use app\common\model\Student;
use app\common\service\StudentAuth;
use app\common\service\Boot;
use think\facade\Session;

/**
 * 学生端登录 / 登出 / 当前身份 / 自助注册
 *
 * 路由：/api/studentauth/xxx 自动映射到此控制器
 */
class Studentauth extends StudentBase
{
    /**
     * 学生登录
     * POST /api/studentauth/login
     * {"sno":"2025001","password":"xxx"}
     */
    public function login()
    {
        $data     = $this->jsonInput();
        $sno      = trim(isset($data['sno']) ? $data['sno'] : '');
        $password = isset($data['password']) ? $data['password'] : '';

        $res = StudentAuth::attempt($sno, $password);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }

        return $this->ok([
            'student' => $res['student']->toSafeArray(),
        ], '登录成功');
    }

    /**
     * 退出登录
     * POST /api/studentauth/logout
     */
    public function logout()
    {
        StudentAuth::logout();
        return $this->ok(null, '已退出登录');
    }

    /**
     * 当前登录身份
     * GET /api/studentauth/me
     */
    public function me()
    {
        if (!$this->student) {
            return $this->fail('未登录', 401);
        }
        return $this->ok([
            'student' => $this->student->toSafeArray(),
        ]);
    }

    /**
     * 自助注册
     * POST /api/studentauth/register
     * {"sno":"2025xxxx","password":"xxx","name":"张三","class_join_code":"robot24"}
     *
     * 凭班级口令加入对应班级；若口令为空，注册到 class_id=0 待管理员分配。
     */
    public function register()
    {
        $data     = $this->jsonInput();
        $sno      = trim(isset($data['sno']) ? $data['sno'] : '');
        $password = isset($data['password']) ? $data['password'] : '';
        $name     = trim(isset($data['name']) ? $data['name'] : '');
        $joinCode = trim(isset($data['class_join_code']) ? $data['class_join_code'] : '');

        // 验证学号
        if (!preg_match('/^[A-Za-z0-9_]{4,32}$/', $sno)) {
            return $this->fail('学号只能包含字母、数字和下划线，长度 4-32 位');
        }
        if (strlen($password) < 6 || strlen($password) > 72) {
            return $this->fail('密码长度须为 6-72 位');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 32) {
            return $this->fail('姓名长度须为 2-32 个字符');
        }

        // 检查学号唯一
        $exists = Student::where('sno', $sno)->find();
        if ($exists) {
            return $this->fail('该学号已被注册');
        }

        // 班级口令解析
        $classId = 0;
        if ($joinCode !== '') {
            $class = \app\common\model\SchoolClass::where('join_code', $joinCode)->find();
            if (!$class) {
                return $this->fail('班级口令无效，请确认后重试');
            }
            $classId = (int)$class->id;
        }

        $new = Student::create([
            'sno'      => $sno,
            'password' => Student::hashPassword($password),
            'name'     => $name,
            'class_id' => $classId,
            'year'     => (int)date('Y'),
            'status'   => Student::STATUS_PENDING,
            'source'   => 'register',
        ]);

        return $this->ok(['sno' => $sno, 'status' => Student::STATUS_PENDING],
            '注册成功，请等待管理员审核后登录');
    }
}