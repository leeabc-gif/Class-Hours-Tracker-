<?php
namespace app\index\controller;

class Index
{
    /**
     * 站点根目录：跳转到前端单页应用（public/index.html）
     */
    public function index()
    {
        header('Location: /index.html');
        exit;
    }

    public function hello($name = 'ThinkPHP5')
    {
        return 'hello,' . $name;
    }
}
