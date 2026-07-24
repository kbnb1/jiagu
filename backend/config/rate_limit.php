<?php
// 频率限制配置
return [
    // 默认限制(次/分钟)
    'default' => env('RATE_LIMIT_DEFAULT', 60),

    // 上传接口限制(次/分钟)
    'upload' => env('RATE_LIMIT_UPLOAD', 10),

    // 登录接口限制(次/分钟) 防爆破
    'login' => 5,

    // 注册接口限制(次/分钟)
    'register' => 3,

    // 管理后台限制(次/分钟)
    'admin' => 120,

    // 缓存key前缀
    'prefix' => 'rate_limit:',

    // 限制窗口(秒)
    'window' => 60,
];
