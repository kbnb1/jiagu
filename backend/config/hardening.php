<?php
// 加固配置
return [
    // 支持的编程语言
    'languages' => ['php', 'java', 'javascript', 'python', 'cpp'],

    // 单文件最大体积(字节) 默认10MB
    'max_file_size' => env('HARDENING_MAX_FILE_SIZE', 10485760),

    // 加固临时目录
    'temp_dir' => env('HARDENING_TEMP_DIR', '/tmp/hardening'),

    // 字符串加密密钥(32字节)
    'encrypt_key' => env('HARDENING_ENCRYPT_KEY', 'ChangeMeToRandomKey32Bytes'),

    // 默认加固选项
    'default_options' => [
        'obfuscate_level'  => 2,
        'encrypt_strings'  => true,
        'remove_comments'  => true,
        'control_flow'     => false,
    ],

    // 单任务最大超时(秒)
    'task_timeout' => 300,
];
