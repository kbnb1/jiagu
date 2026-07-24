<?php
// 全局中间件
return [
    // 全局跨域
    \app\middleware\CorsMiddleware::class,

    // 全局审计日志
    \app\middleware\AuditLogMiddleware::class,
];
