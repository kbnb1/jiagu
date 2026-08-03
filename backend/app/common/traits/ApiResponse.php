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
     * 从 JWT 中间件注入的请求上下文获取已认证用户 ID。
     *
     * 严格以来源 JWT 的 userId 为准，禁止通过 X-User-Id 等请求头覆盖，
     * 否则持有任意有效 token 的攻击者可凭 X-User-Id 任意冒用其他用户身份。
     */
    protected function userId(): int
    {
        $uid = request()->userId ?? 0;
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
