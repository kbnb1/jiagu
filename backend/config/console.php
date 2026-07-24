<?php
// 控制台命令配置
return [
    // 注册的命令
    'commands' => [
        'hardening:worker' => \app\command\HardeningWorker::class,
    ],
];
