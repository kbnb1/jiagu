<?php
// 上传配置
return [
    // 默认上传磁盘
    'default' => 'local',

    // 磁盘列表
    'disks' => [
        // 本地磁盘
        'local' => [
            'type' => 'local',
            'root' => env('UPLOAD_SAVE_PATH', runtime_path('uploads')),
            'url'  => '/uploads',
        ],
    ],

    // 单文件最大上传(字节) 默认10MB
    'max_size' => env('UPLOAD_MAX_SIZE', 10485760),

    // 允许的文件扩展名
    'extensions' => [
        'php', 'inc', 'phtml',
        'java', 'jar',
        'js', 'mjs',
        'py',
        'cpp', 'cc', 'cxx', 'c', 'h', 'hpp',
        'zip', 'tar', 'gz',
    ],

    // 允许的MIME类型(留空则只校验扩展名)
    'mimes' => [
        'text/plain', 'application/octet-stream',
        'application/zip', 'application/x-tar', 'application/gzip',
    ],

    // 文件名生成方式: uuid / original / date
    'filename_type' => 'uuid',

    // 是否保留原文件名
    'keep_original_name' => false,
];
