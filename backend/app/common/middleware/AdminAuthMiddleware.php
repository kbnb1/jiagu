<?php
declare(strict_types=1);

namespace app\common\middleware;

use Closure;

/**
 * 管理员认证中间件
 *
 * 先走 AuthMiddleware 验证用户身份，再检查 is_admin 字段，
 * 非管理员返回 403。
 */
class AdminAuthMiddleware
{
    /** @var AuthMiddleware 复用的认证中间件 */
    private AuthMiddleware $auth;

    public function __construct(?AuthMiddleware $auth = null)
    {
        $this->auth = $auth ?? new AuthMiddleware();
    }

    /**
     * 处理请求：先认证，再鉴权。
     *
     * @param mixed   $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // 包装一层闭包用于鉴权
        $authNext = function ($req) use ($request, $next) {
            return $this->authorize($req, $next);
        };

        return $this->auth->handle($request, $authNext);
    }

    /**
     * 鉴权：检查当前用户是否为管理员。
     */
    protected function authorize($request, Closure $next)
    {
        $isAdmin = $this->isAdmin($request);
        if (!$isAdmin) {
            return $this->forbid('需要管理员权限', 'forbidden');
        }
        return $next($request);
    }

    /**
     * 判断当前请求用户是否为管理员。
     */
    protected function isAdmin($request): bool
    {
        if (is_object($request)) {
            if (property_exists($request, 'userClaims')) {
                return (bool)($request->userClaims['is_admin'] ?? false);
            }
            if (property_exists($request, 'isAdmin')) {
                return (bool)$request->isAdmin;
            }
        }
        if (is_array($request)) {
            return (bool)($request['userClaims']['is_admin'] ?? ($request['isAdmin'] ?? false));
        }
        return false;
    }

    /**
     * 返回 403 拒绝响应。
     */
    protected function forbid(string $message, string $error = 'forbidden')
    {
        $body = json_encode([
            'code'    => 403,
            'message' => $message,
            'error'   => $error,
            'data'    => null,
        ], JSON_UNESCAPED_UNICODE);
        return [
            '__response__' => true,
            'status'       => 403,
            'headers'      => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'         => $body,
        ];
    }
}
