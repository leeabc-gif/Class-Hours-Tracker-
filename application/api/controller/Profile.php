<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\service\AiConfig;

/**
 * 个人设置接口
 * 教师只能改自己的姓名、岗位、密码；院系与角色由管理员维护
 */
class Profile extends Base
{
    /**
     * 修改个人资料
     */
    public function save()
    {
        $this->requireLogin();
        $data = $this->jsonInput();

        $name     = trim(isset($data['name']) ? $data['name'] : '');
        $position = trim(isset($data['position']) ? $data['position'] : '');

        if ($name === '') {
            return $this->fail('姓名不能为空');
        }
        if (mb_strlen($name, 'UTF-8') > 32) {
            return $this->fail('姓名不能超过 32 个字');
        }
        if (mb_strlen($position, 'UTF-8') > 32) {
            return $this->fail('岗位不能超过 32 个字');
        }

        $this->user->name     = $name;
        $this->user->position = $position;
        $this->user->save();

        $this->log('update', 'teacher', $this->user->id, "修改个人资料：{$name} / {$position}");

        return $this->ok(['user' => $this->user->toSafeArray()], '资料已保存');
    }

    /**
     * 读取个人 AI 配置（API Key 只返回是否已配置）
     */
    public function ai()
    {
        $this->requireLogin();
        return $this->ok(AiConfig::publicForUser($this->user->id));
    }

    /**
     * 保存个人 AI 配置
     */
    public function aiSave()
    {
        $this->requireLogin();
        $data = $this->jsonInput();
        $result = AiConfig::saveForUser($this->user->id, $data);
        if (!$result['ok']) return $this->fail($result['msg']);
        $this->log('update', 'teacher_ai_config', $this->user->id, '修改个人 AI 配置');
        return $this->ok(AiConfig::publicForUser($this->user->id), '个人 AI 配置已保存');
    }

    /**
     * 修改登录密码
     */
    public function password()
    {
        $this->requireLogin();
        $data = $this->jsonInput();

        $old  = trim(isset($data['old_password']) ? $data['old_password'] : '');
        $new  = trim(isset($data['new_password']) ? $data['new_password'] : '');
        $confirm = trim(isset($data['confirm_password']) ? $data['confirm_password'] : '');

        if ($old === '' || $new === '') {
            return $this->fail('请填写原密码与新密码');
        }
        if (!$this->user->checkPassword($old)) {
            return $this->fail('原密码不正确');
        }
        if (strlen($new) < 6) {
            return $this->fail('新密码至少 6 位');
        }
        if ($new !== $confirm) {
            return $this->fail('两次输入的新密码不一致');
        }
        if ($new === $old) {
            return $this->fail('新密码不能与原密码相同');
        }

        $this->user->password = \app\common\model\Teacher::hashPassword($new);
        $this->user->save();

        $this->log('reset_pwd', 'teacher', $this->user->id, '修改登录密码');

        return $this->ok(null, '密码已修改，下次登录请使用新密码');
    }
}
