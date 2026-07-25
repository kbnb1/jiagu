<?php
declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\JwtService;
use Closure;

/**
 * 认证中间件
 *
 * 从 Authorization 头提取 Bearer token，调用 JwtService 验证；
 * 验证失败返回 401 JSON，成功将 user_id 注入 request。
 */
class AuthMiddleware
{
    /** @var JwtService|null 共享的 JWT 服务实例 */
    private static ?JwtService $jwt = null;

    /**
     * 设置共享的 JwtService 实例。
     */
    public static function setJwtService(JwtService $jwt): void
    {
        self::$jwt = $jwt;
    }

    /**
     * 获取 JwtService 实例（懒加载）。
     */
    protected static function getJwt(): JwtService
    {
        if (self::$jwt === null) {
            self::$jwt = new JwtService();
        }
        return self::$jwt;
    }

    /**
     * 处理请求。
     *
     * @param mixed   $request 请求对象（需支持 header()/method() 或基于 $_SERVER）
     * @param Closure $next    后续处理闭包
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $this->extractBearerToken($request);

        if ($token === '') {
            return $this->deny(401, '缺少认证凭证', 'unauthorized');
        }

        $claims = self::getJwt()->verify($token);
        if ($claims === null) {
            return $this->deny(401, '认证凭证无效或已过期', 'invalid_token');
        }

        // 注入用户信息到 request
        $this->injectUser($request, $claims);

        return $next($request);
    }

    /**
     * 从 Authorization 头提取 Bearer token。
     */
    protected function extractBearerToken($request): string
    {
        $auth = $this->readHeader($request, 'Authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * 注入用户信息到 request。
     */
    protected function injectUser($request, array $claims): void
    {
        $userId = (int)($claims['user_id'] ?? 0);
        if (is_object($request)) {
            // 优先使用 setter，兼容不可变对象
            if (method_exists($request, 'withUserId')) {
                $request->withUserId($userId);
            } else {
                $request->userId = $userId;
            }
            if (method_exists($request, 'withClaims')) {
                $request->withClaims($claims);
            } else {
                $request->userClaims = $claims;
            }
        } elseif (is_array($request)) {
            $request['userId'] = $userId;
            $request['userClaims'] = $claims;
        }
    }

    /**
     * 读取请求头（兼容对象与 $_SERVER）。
     */
    protected function readHeader($request, string $name): string
    {
        if (is_object($request) && method_exists($request, 'header')) {
            $val = $request->header($name);
            return is_string($val) ? $val : (string)($val ?? '');
        }
        if (is_array($request) && isset($request[$name])) {
            return (string)$request[$name];
        }
        // $_SERVER 回退
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? '';
    }

    /**
     * 返回拒绝响应（JSON）。
     *
     * @return array{__response__:bool,status:int,headers:array,body:string}
     */
    protected function deny(int $status, string $message, string $error = 'error')
    {
        $body = json_encode([
            'code'    => $status,
            'message' => $message,
            'error'   => $error,
            'data'    => null,
        ], JSON_UNESCAPED_UNICODE);
        return [
            '__response__' => true,
            'status'       => $status,
            'headers'      => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'         => $body,
        ];
    }
}
