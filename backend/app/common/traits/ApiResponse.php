<?php
declare(strict_types=1);

namespace app\common\traits;

use think\Response;

/**
 * API 统一响应 Trait
 */
trait ApiResponse
{
    /**
     * 成功响应
     */
    protected function success($data = null, string $message = 'success', int $code = 0): Response
    {
        return $this->json($code, $message, $data);
    }

    /**
     * 失败响应
     */
    protected function fail(string $message = 'failed', int $code = 1, $data = null, int $httpStatus = 200): Response
    {
        return $this->json($code, $message, $data, $httpStatus);
    }

    /**
     * 统一JSON输出 {code, message, data}
     */
    protected function json(int $code, string $message, $data = null, int $httpStatus = 200): Response
    {
        $payload = [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];
        return Response::create($payload, 'json', $httpStatus);
    }

    /**
     * 从请求头获取当前登录用户ID
     */
    protected function userId(): int
    {
        $uid = request()->header('X-User-Id');
        if (empty($uid)) {
            $uid = request()->userId ?? 0;
        }
        return (int) $uid;
    }

    /**
     * 分页参数
     */
    protected function pageParams(): array
    {
        $page = (int) request()->param('page', 1);
        $size = (int) request()->param('page_size', 15);
        $page = max(1, $page);
        $size = min(100, max(1, $size));
        return [$page, $size];
    }
}
