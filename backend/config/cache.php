<?php
// 缓存配置
return [
    // 默认驱动
    'default' => env('CACHE_DRIVER', 'redis'),

    // 缓存连接
    'stores' => [
        // redis缓存
        'redis' => [
            'type'       => 'redis',
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS_PORT', 6379),
            'password'   => env('REDIS_PASSWORD', ''),
            'select'     => env('REDIS_DB', 0),
            'timeout'    => 3,
            'expire'     => 0,
            'persistent' => false,
            'prefix'     => 'ch:',
        ],

        // 文件缓存(备用)
        'file' => [
            'type'       => 'file',
            'path'       => runtime_path('cache'),
            'prefix'     => '',
            'expire'     => 0,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
    ],
];
