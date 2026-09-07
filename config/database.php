<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

$installedConfig = __DIR__ . '/installed.php';
$runtimeConfig = is_file($installedConfig) ? require $installedConfig : [];
if (!is_array($runtimeConfig)) {
    $runtimeConfig = [];
}

if (!defined('APP_AI_CONFIG_KEY') && !empty($runtimeConfig['ai_config_key'])) {
    define('APP_AI_CONFIG_KEY', $runtimeConfig['ai_config_key']);
}

return [
    // 数据库类型
    'type'            => 'mysql',
    // 服务器地址：安装向导生成的 config/installed.php 优先
    'hostname'        => isset($runtimeConfig['hostname']) ? $runtimeConfig['hostname'] : '127.0.0.1',
    // 数据库名
    'database'        => isset($runtimeConfig['database']) ? $runtimeConfig['database'] : 'keshi',
    // 用户名
    'username'        => isset($runtimeConfig['username']) ? $runtimeConfig['username'] : 'keshi',
    // 密码
    'password'        => isset($runtimeConfig['password']) ? $runtimeConfig['password'] : 'keshi123456',
    // 端口
    'hostport'        => isset($runtimeConfig['hostport']) ? $runtimeConfig['hostport'] : '3399',
    // 连接dsn
    'dsn'             => '',
    // 数据库连接参数
    'params'          => [
        // 连接超时（秒），避免数据库未启动时页面长时间卡住
        \PDO::ATTR_TIMEOUT    => 10,
        \PDO::ATTR_PERSISTENT => false,
    ],
    // 数据库编码
    'charset'         => 'utf8mb4',
    // 数据库表前缀
    'prefix'          => '',
    // 数据库调试模式：生产安装默认关闭，可通过环境变量临时打开
    'debug'           => filter_var(getenv('KS_DB_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN),
    // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
    'deploy'          => 0,
    // 数据库读写是否分离 主从式有效
    'rw_separate'     => false,
    // 读写分离后 主服务器数量
    'master_num'      => 1,
    // 指定从服务器序号
    'slave_no'        => '',
    // 自动读取主库数据
    'read_master'     => false,
    // 是否严格检查字段是否存在
    'fields_strict'   => true,
    // 数据集返回类型
    'resultset_type'  => 'array',
    // 自动写入时间戳字段
    'auto_timestamp'  => false,
    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',
    // 是否需要进行SQL性能分析
    'sql_explain'     => false,
    // Builder类
    'builder'         => '',
    // Query类
    'query'           => '\\think\\db\\Query',
    // 是否需要断线重连
    'break_reconnect' => false,
    // 断线标识字符串
    'break_match_str' => [],
];
