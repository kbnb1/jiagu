<?php
// 数据库配置
return [
    // 默认连接
    'default' => env('DB_TYPE', 'mysql'),

    // 连接配置
    'connections' => [
        'mysql' => [
            // 数据库类型
            'type'            => 'mysql',
            // 服务器地址
            'hostname'        => env('DB_HOST', '127.0.0.1'),
            // 数据库名
            'database'        => env('DB_DATABASE', 'code_hardening'),
            // 用户名
            'username'        => env('DB_USERNAME', 'root'),
            // 密码
            'password'        => env('DB_PASSWORD', ''),
            // 端口
            'hostport'        => env('DB_PORT', '3306'),
            // 数据库编码默认采用utf8mb4
            'charset'         => env('DB_CHARSET', 'utf8mb4'),
            // 数据库表前缀
            'prefix'          => env('DB_PREFIX', ''),
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 0,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => false,
            // 连接参数
            'params'          => [],
            // 数据库调试模式
            'debug'           => env('APP_DEBUG', false),
            // 断线重连
            'break_reconnect' => true,
            // 监听SQL
            'trigger_sql'     => true,
        ],
    ],
];
