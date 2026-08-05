<?php
declare(strict_types=1);

namespace app\common\middleware;

use Closure;
use Redis;

/**
 * 速率限制中间件
 *
 * 基于 IP + URI 的请求频率限制，使用 Redis 计数器实现滑动窗口算法。
 * 默认 60 次/分钟，上传接口 10 次/分钟。超限返回 429。
 * Redis 不可用时降级为文件计数。
 */
class RateLimitMiddleware
{
    /** 默认每分钟限制 */
    public const DEFAULT_LIMIT = 60;

    /** 窗口大小（秒） */
    public const WINDOW_SECONDS = 60;

    /** @var array 路径前缀 => 限制次数 */
    private array $routeLimits;

    /** @var int 默认限制 */
    private int $defaultLimit;

    /** @var Redis|null */
    private $redis = null;

    /** @var bool 是否使用 Redis */
    private bool $useRedis = false;

    /** @var string 文件计数目录 */
    private string $fileDir;

    public function __construct(array $config = [])
    {
        $this->defaultLimit = (int)($config['default_limit'] ?? self::DEFAULT_LIMIT);
        $this->routeLimits  = $config['route_limits'] ?? [
            '/api/upload'   => 10,
            '/api/harden'   => 20,
            '/api/auth'     => 30,
        ];
        $this->fileDir = $config['file_dir'] ?? (sys_get_temp_dir() . '/trae_ratelimit');

        if (isset($config['redis']) && $config['redis'] instanceof Redis) {
            $this->redis = $config['redis'];
            $this->useRedis = true;
        } elseif (class_exists(Redis::class) && !empty($config['redis_host'])) {
            try {
                $this->redis = new Redis();
                $this->useRedis = (bool)$this->redis->connect(
                    $config['redis_host'],
                    (int)($config['redis_port'] ?? 6379),
                    2.0
                );
            } catch (\Throwable $e) {
                $this->useRedis = false;
            }
        }

        if (!$this->useRedis && !is_dir($this->fileDir)) {
            @mkdir($this->fileDir, 0700, true);
        }
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
        $ip   = $this->readIp($request);
        $path = $this->readPath($request);
        $limit = $this->resolveLimit($path);

        $key = $this->buildKey($ip, $path);

        if (!$this->allow($key, $limit)) {
            $retry = self::WINDOW_SECONDS;
            return $this->tooManyRequests($limit, $retry);
        }

        return $next($request);
    }

    /**
     * 判断请求是否被允许，并累加计数。
     */
    protected function allow(string $key, int $limit): bool
    {
        if ($this->useRedis) {
            return $this->allowRedis($key, $limit);
        }
        return $this->allowFile($key, $limit);
    }

    /**
     * Redis 滑动窗口实现：使用有序集合（ZSET）记录请求时间戳。
     */
    protected function allowRedis(string $key, int $limit): bool
    {
        $now = microtime(true);
        $windowStart = $now - self::WINDOW_SECONDS;
        $zsetKey = 'rl:' . $key;

        try {
            $this->redis->multi();
            $this->redis->zRemRangeByScore($zsetKey, 0, $windowStart);
            $this->redis->zAdd($zsetKey, $now, $now . '_' . bin2hex(random_bytes(4)));
            $this->redis->zCard($zsetKey);
            $this->redis->expire($zsetKey, self::WINDOW_SECONDS + 1);
            [, , $count] = $this->redis->exec();
            return $count <= $limit;
        } catch (\Throwable $e) {
            return $this->allowFile($key, $limit);
        }
    }

    /**
     * 文件计数实现：固定窗口内的时间戳列表。
     * 使用文件锁防止并发竞态条件。
     */
    protected function allowFile(string $key, int $limit): bool
    {
        $file = $this->fileDir . '/' . md5($key) . '.json';
        $now = microtime(true);
        $windowStart = $now - self::WINDOW_SECONDS;

        // 使用文件锁防止并发竞态
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            // 无法打开文件，允许请求通过（降级策略）
            return true;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            // 无法获取锁，允许请求通过（降级策略）
            return true;
        }

        try {
            // 读取现有时间戳
            $content = stream_get_contents($handle);
            $entries = [];
            if ($content !== '' && $content !== false) {
                $entries = json_decode($content, true) ?: [];
            }

            // 清理过期时间戳
            $entries = array_values(array_filter($entries, fn($t) => $t > $windowStart));

            if (count($entries) >= $limit) {
                // 达到限制，拒绝请求
                return false;
            }

            // 添加当前请求时间戳
            $entries[] = $now;

            // 原子写入：清空文件并写入新内容
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($entries));
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 根据路径解析限流阈值。
     */
    protected function resolveLimit(string $path): int
    {
        foreach ($this->routeLimits as $prefix => $lim) {
            if (str_starts_with($path, $prefix)) {
                return $lim;
            }
        }
        return $this->defaultLimit;
    }

    /**
     * 构建限流键。
     */
    protected function buildKey(string $ip, string $path): string
    {
        // 路径归一化，避免动态参数导致键爆炸
        $normalized = preg_replace('/\d+/', '_', $path);
        return md5($ip . '|' . $normalized);
    }

    protected function readIp($request): string
    {
        if (is_object($request) && method_exists($request, 'ip')) {
            return $request->ip();
        }
        if (is_array($request) && isset($request['ip'])) {
            return $request['ip'];
        }
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    protected function readPath($request): string
    {
        if (is_object($request) && method_exists($request, 'path')) {
            return $request->path();
        }
        return parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
    }

    /**
     * 返回 429 响应。
     */
    protected function tooManyRequests(int $limit, int $retry): array
    {
        $body = json_encode([
            'code'    => 429,
            'message' => '请求过于频繁，请稍后再试',
            'error'   => 'too_many_requests',
            'data'    => ['limit' => $limit, 'retry_after' => $retry],
        ], JSON_UNESCAPED_UNICODE);
        return [
            '__response__' => true,
            'status'       => 429,
            'headers'      => [
                'Content-Type'    => 'application/json; charset=utf-8',
                'Retry-After'     => (string)$retry,
                'X-RateLimit-Limit'     => (string)$limit,
                'X-RateLimit-Remaining' => '0',
            ],
            'body'         => $body,
        ];
    }

    /**
     * 是否使用 Redis 后端。
     */
    public function isUsingRedis(): bool
    {
        return $this->useRedis;
    }
}
