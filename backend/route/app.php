<?php
// +----------------------------------------------------------------------
// | API 路由定义
// +----------------------------------------------------------------------
use think\facade\Route;

// 不需要登录的健康检查路由
Route::group('api/health', function () {
    Route::get('ping', 'api/HealthController/ping');
    Route::get('status', 'api/HealthController/status');
});

// 用户认证相关路由(无需登录)
Route::group('api/user', function () {
    Route::post('register', 'api/UserController/register');
    Route::post('login', 'api/UserController/login');
    Route::post('refresh', 'api/UserController/refresh');
});

// 需要登录的用户路由
Route::group('api/user', function () {
    Route::post('logout', 'api/UserController/logout');
    Route::get('profile', 'api/UserController/profile');
    Route::put('profile', 'api/UserController/updateProfile');
    Route::put('password', 'api/UserController/changePassword');
})->middleware(\app\middleware\AuthMiddleware::class);

// 套餐相关路由(部分需登录)
Route::group('api/plan', function () {
    Route::get('list', 'api/PlanController/list');
    Route::get(':id', 'api/PlanController/detail');
});
Route::group('api/plan', function () {
    Route::get('current', 'api/PlanController/current');
    Route::get('orders', 'api/PlanController/orders');
    Route::post('order', 'api/PlanController/createOrder');
})->middleware(\app\middleware\AuthMiddleware::class);

// 任务相关路由(全部需登录 + 频率限制)
Route::group('api/task', function () {
    Route::post('create', 'api/TaskController/create');
    Route::get('status/:id', 'api/TaskController/status');
    Route::get('result/:id', 'api/TaskController/result');
    Route::get('download/:id', 'api/TaskController/download');
    Route::get('history', 'api/TaskController/history');
    Route::delete(':id', 'api/TaskController/delete');
})->middleware([
    \app\middleware\AuthMiddleware::class,
    \app\middleware\RateLimitMiddleware::class,
]);

// MISS 路由
Route::miss(function () {
    return json([
        'code'    => 404,
        'message' => 'Route Not Found',
        'data'    => null,
    ], 404);
});
