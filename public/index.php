<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// [ 应用入口文件 ]
namespace think;

// 未安装引导：缺少 config/installed.php（Web 请求）时直接进安装向导，
// 避免打开站点后落到连库报错页。CLI 场景（php think ... / 定时任务）不受影响。
if (php_sapi_name() !== 'cli'
    && !is_file(dirname(__DIR__) . '/config/installed.php')
    && !is_file(dirname(__DIR__) . '/runtime/install.lock')) {
    header('Location: /install/');
    exit;
}

// 加载基础文件
require __DIR__ . '/../thinkphp/base.php';

// 支持事先使用静态方法设置Request对象和Config对象

// 执行应用并响应
Container::get('app')->run()->send();
