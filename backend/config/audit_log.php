<?php
// 审计日志配置
return [
    // 是否开启审计日志
    'enabled' => env('AUDIT_LOG_ENABLED', true),

    // 日志保留天数
    'keep_days' => env('AUDIT_LOG_KEEP_DAYS', 90),
];
