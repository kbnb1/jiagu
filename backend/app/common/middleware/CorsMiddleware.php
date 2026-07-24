<?php
declare(strict_types=1);

namespace app\common\middleware;

use Closure;

/**
 * 跨域中间件
 *
 * 处理 OPTIONS 预检请求，设置 Access-Control-Allow-* 系列响应头。
 * 支持配置允许的域名列表（白名单），仅对白名单域名回显 Origin。
 */
class CorsMiddleware
{
    /** @var string[] 允许的来源域名列表（* 表示全部） */
    private array $allowedOrigins;

    /** @var string[] 允许的 HTTP 方法 */
    private array $allowedMethods;

    /** @var string[] 允许的请求头 */
    private array $allowedHeaders;

    /** @var bool 是否允许携带凭证 */
    private bool $allowCredentials;

    /** @var int 预检缓存时间（秒） */
    private int $maxAge;

    /** @var string[] 不需要 CORS 的路径前缀 */
    private array $excludedPaths;

    public function __construct(array $config = [])
    {
        $this->allowedOrigins    = $config['allowed_origins'] ?? ['*'];
        $this->allowedMethods    = $config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $this->allowedHeaders    = $config['allowed_headers'] ?? [
            'Authorization', 'Content-Type', 'X-Requested-With', 'X-Token', 'Accept', 'Origin',
        ];
        $this->allowCredentials  = (bool)($config['allow_credentials'] ?? true);
        $this->maxAge            = (int)($config['max_age'] ?? 86400);
        $this->excludedPaths     = $config['excluded_paths'] ?? [];
    }

    /**
     * 处理请求。
     *
     * @param mixed   $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $origin = $this->readHeader($request, 'Origin');
        $method = $this->readMethod($request);
        $path   = $this->readPath($request);

        // 跳过排除路径
        foreach ($this->excludedPaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        // 计算 Allow-Origin
        $allowOrigin = $this->resolveAllowOrigin($origin);

        // OPTIONS 预检直接返回 204
        if (strtoupper($method) === 'OPTIONS') {
            return [
                '__response__' => true,
                'status'       => 204,
                'headers'      => $this->buildHeaders($allowOrigin),
                'body'         => '',
            ];
        }

        // 非预检：透传并附加 CORS 头
        $response = $next($request);

        // 为已有响应附加 CORS 头
        if (is_array($response) && isset($response['__response__'])) {
            $response['headers'] = array_merge($response['headers'] ?? [], $this->buildHeaders($allowOrigin));
            return $response;
        }

        return $response;
    }

    /**
     * 解析允许的 Origin。仅白名单域名回显，否则不设置 Allow-Origin。
     */
    protected function resolveAllowOrigin(string $origin): string
    {
        if ($origin === '') {
            return '';
        }
        foreach ($this->allowedOrigins as $allowed) {
            if ($allowed === '*') {
                return $this->allowCredentials ? $origin : '*';
            }
            if ($this->originMatches($origin, $allowed)) {
                return $origin;
            }
        }
        return '';
    }

    /**
     * 判断 origin 是否匹配白名单规则（支持通配符）。
     */
    protected function originMatches(string $origin, string $pattern): bool
    {
        if ($origin === $pattern) {
            return true;
        }
        // 支持 *.example.com
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1);
            $host = parse_url($origin, PHP_URL_HOST) ?: '';
            return $host !== '' && str_ends_with($host, $suffix);
        }
        return false;
    }

    /**
     * 构建 CORS 响应头。
     */
    protected function buildHeaders(string $allowOrigin): array
    {
        $headers = [
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $this->allowedHeaders),
            'Access-Control-Max-Age'       => (string)$this->maxAge,
        ];
        if ($allowOrigin !== '') {
            $headers['Access-Control-Allow-Origin'] = $allowOrigin;
        }
        if ($this->allowCredentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        return $headers;
    }

    protected function readHeader($request, string $name): string
    {
        if (is_object($request) && method_exists($request, 'header')) {
            $val = $request->header($name);
            return is_string($val) ? $val : (string)($val ?? '');
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? '';
    }

    protected function readMethod($request): string
    {
        if (is_object($request) && method_exists($request, 'method')) {
            return strtoupper($request->method());
        }
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    protected function readPath($request): string
    {
        if (is_object($request) && method_exists($request, 'path')) {
            return $request->path();
        }
        return parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
    }
}
