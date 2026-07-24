<?php
// +----------------------------------------------------------------------
// | 管理后台路由定义
// +----------------------------------------------------------------------
use think\facade\Route;

// 所有 admin 前缀路由,挂载 admin_auth 中间件
Route::group('admin', function () {
    // 仪表盘
    Route::get('dashboard', 'admin/AdminController/dashboard');

    // 用户管理
    Route::get('users', 'admin/AdminController/users');
    Route::get('users/:id', 'admin/AdminController/userDetail');
    Route::put('users/:id/status', 'admin/AdminController/toggleUserStatus');

    // 任务管理
    Route::get('tasks', 'admin/AdminController/tasks');
    Route::get('tasks/:id', 'admin/AdminController/taskDetail');

    // 套餐管理
    Route::get('plans', 'admin/AdminController/plans');
    Route::post('plans', 'admin/AdminController/createPlan');
    Route::put('plans/:id', 'admin/AdminController/updatePlan');

    // 订单管理
    Route::get('orders', 'admin/AdminController/orders');

    // 审计日志
    Route::get('audit-logs', 'admin/AdminController/auditLogs');

    // 系统配置
    Route::get('config', 'admin/AdminController/systemConfig');
})->middleware(\app\middleware\AdminAuthMiddleware::class);
