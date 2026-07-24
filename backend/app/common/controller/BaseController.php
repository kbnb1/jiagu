<?php
declare(strict_types=1);

namespace app\common\controller;

/**
 * 基础控制器
 *
 * 提供统一的 JSON 响应封装与请求上下文访问工具。
 * 业务控制器继承本类以复用响应格式与认证信息读取。
 */
abstract class BaseController
{
    /**
     * 成功响应。
     *
     * @param mixed       $data 业务数据
     * @param string      $msg  提示消息
     * @return array{__response__:bool,status:int,headers:array,body:string}
     */
    protected function success($data = null, string $msg = 'ok'): array
    {
        return $this->json(200, [
            'code' => 0,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    /**
     * 错误响应。
     *
     * @param string $msg        错误消息
     * @param int    $code       业务错误码
     * @param int    $httpStatus HTTP 状态码
     * @return array{__response__:bool,status:int,headers:array,body:string}
     */
    protected function error(string $msg, int $code = 1, int $httpStatus = 400): array
    {
        return $this->json($httpStatus, [
            'code' => $code,
            'msg'  => $msg,
            'data' => null,
        ]);
    }

    /**
     * 从当前请求获取已认证的用户 ID（由 AuthMiddleware 注入）。
     */
    protected function getUserId(): int
    {
        $request = $this->getRequest();
        if (is_object($request) && property_exists($request, 'userId')) {
            return (int)$request->userId;
        }
        if (is_array($request) && isset($request['userId'])) {
            return (int)$request['userId'];
        }
        return 0;
    }

    /**
     * 获取客户端真实 IP。
     */
    protected function getClientIp(): string
    {
        $request = $this->getRequest();
        if (is_object($request) && method_exists($request, 'ip')) {
            return $request->ip();
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * 获取当前请求对象（子类可覆盖以接入框架 Request）。
     */
    protected function getRequest()
    {
        return $GLOBALS['__current_request'] ?? null;
    }

    /**
     * 构建标准 JSON 响应数组。
     */
    protected function json(int $status, array $payload): array
    {
        return [
            '__response__' => true,
            'status'       => $status,
            'headers'      => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
    }
}
