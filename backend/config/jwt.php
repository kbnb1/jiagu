<?php
// JWT 配置
return [
    // 加密密钥(生产环境必须修改)
    'secret' => env('JWT_SECRET', 'ChangeMePleaseToRandomString'),

    // 签名算法
    'alg' => env('JWT_ALG', 'HS256'),

    // access_token 有效期(秒) 默认7天
    'access_ttl' => env('JWT_ACCESS_TTL', 604800),

    // refresh_token 有效期(秒) 默认30天
    'refresh_ttl' => env('JWT_REFRESH_TTL', 2592000),

    // token 签发者
    'issuer' => env('APP_NAME', 'CodeHardening'),

    // token 接收方
    'audience' => env('APP_URL', 'https://hardening.example.com'),

    // 是否开启黑名单(注销时使token失效)
    'blacklist_enabled' => true,

    // 黑名单缓存key前缀
    'blacklist_prefix' => 'jwt:blacklist:',

    // token 请求头名称
    'header' => 'Authorization',

    // token 前缀
    'prefix' => 'Bearer',

    // 用户模型字段映射
    'user_pk' => 'id',
    'user_claim' => 'uid',
];
