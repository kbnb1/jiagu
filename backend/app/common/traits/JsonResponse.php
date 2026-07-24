<?php
declare(strict_types=1);

namespace app\common\traits;

/**
 * JSON 响应 Trait
 *
 * 为控制器/服务提供统一的 JSON 响应构造能力，可与 BaseController 配合使用，
 * 也可独立 mix 到非控制器类中。
 */
trait JsonResponse
{
    /**
     * 成功响应。
     *
     * @param mixed  $data 业务数据
     * @param string $msg  提示消息
     * @return array
     */
    protected function jsonSuccess($data = null, string $msg = 'ok'): array
    {
        return [
            '__response__' => true,
            'status'       => 200,
            'headers'      => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'         => json_encode([
                'code' => 0,
                'msg'  => $msg,
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * 错误响应。
     *
     * @param string $msg        错误消息
     * @param int    $code       业务错误码
     * @param int    $httpStatus HTTP 状态码
     * @return array
     */
    protected function jsonError(string $msg, int $code = 1, int $httpStatus = 400): array
    {
        return [
            '__response__' => true,
            'status'       => $httpStatus,
            'headers'      => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'         => json_encode([
                'code' => $code,
                'msg'  => $msg,
                'data' => null,
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * 分页响应。
     *
     * @param array $list  当前页数据列表
     * @param int   $total 总记录数
     * @param int   $page  当前页码（从 1 开始）
     * @param int   $size  每页条数
     * @return array
     */
    protected function jsonPaginate(array $list, int $total, int $page, int $size): array
    {
        $page = max(1, $page);
        $size = max(1, $size);
        return [
            '__response__' => true,
            'status'       => 200,
            'headers'      => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'         => json_encode([
                'code' => 0,
                'msg'  => 'ok',
                'data' => [
                    'list'  => $list,
                    'total' => $total,
                    'page'  => $page,
                    'size'  => $size,
                    'pages' => $size > 0 ? (int)ceil($total / $size) : 0,
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }
}
