<?php
declare(strict_types=1);

namespace app\common\middleware;

use Closure;
use PDO;

/**
 * 审计日志中间件
 *
 * 记录所有 API 请求到 audit_log 表，包括：
 * user_id, method, path, params, ip, user_agent, response_code, duration。
 * 异步写入不阻塞响应（通过注册 shutdown 函数实现）。
 */
class AuditLogMiddleware
{
    /** @var PDO|null 数据库连接 */
    private static ?PDO $db = null;

    /** @var string 数据库 DSN */
    private string $dsn;

    /** @var string[] 不记录审计的路径前缀（如健康检查） */
    private array $excludedPaths;

    /** @var bool 是否启用 */
    private bool $enabled;

    /** @var array 待写入的审计记录（shutdown 时批量落库） */
    private static array $pending = [];

    public function __construct(array $config = [])
    {
        $this->dsn = $config['dsn'] ?? ('sqlite:' . dirname(__DIR__, 3) . '/runtime/audit.db');
        self::$instanceDsn = $this->dsn;
        $this->excludedPaths = $config['excluded_paths'] ?? ['/health', '/ping', '/favicon.ico'];
        $this->enabled = (bool)($config['enabled'] ?? true);
    }

    /**
     * 注入共享的 PDO 实例。
     */
    public static function setDb(PDO $db): void
    {
        self::$db = $db;
        self::ensureSchema($db);
    }

    /**
     * 处理请求：记录开始时间，执行后续逻辑后异步写审计。
     *
     * @param mixed   $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!$this->enabled) {
            return $next($request);
        }

        $path = $this->readPath($request);
        foreach ($this->excludedPaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        $startTime = microtime(true);
        $method = $this->readMethod($request);
        $ip = $this->readIp($request);
        $userAgent = $this->readHeader($request, 'User-Agent');
        $params = $this->collectParams($request);

        $response = $next($request);

        $duration = (int)((microtime(true) - $startTime) * 1000); // 毫秒
        $responseCode = $this->extractResponseCode($response);
        $userId = $this->extractUserId($request);

        $record = [
            'user_id'       => $userId,
            'method'        => $method,
            'path'          => $path,
            'params'        => $params,
            'ip'            => $ip,
            'user_agent'    => mb_substr($userAgent, 0, 255),
            'response_code' => $responseCode,
            'duration_ms'   => $duration,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        // 异步写入：注册到 pending，shutdown 时落库
        $this->recordAsync($record);

        return $response;
    }

    /**
     * 异步记录：加入 pending 队列，注册 shutdown 写入。
     */
    protected function recordAsync(array $record): void
    {
        self::$pending[] = $record;

        // 仅注册一次 shutdown 函数
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(function () {
                AuditLogMiddleware::flush();
            });
        }
    }

    private static bool $shutdownRegistered = false;

    /**
     * 批量落库所有 pending 审计记录。
     */
    public static function flush(): void
    {
        if (empty(self::$pending)) {
            return;
        }
        $db = self::getDb();
        if ($db === null) {
            self::$pending = [];
            return;
        }
        $stmt = $db->prepare(
            'INSERT INTO audit_log (user_id, method, path, params, ip, user_agent, response_code, duration_ms, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (self::$pending as $row) {
            try {
                $stmt->execute([
                    $row['user_id'],
                    $row['method'],
                    $row['path'],
                    json_encode($row['params'], JSON_UNESCAPED_UNICODE),
                    $row['ip'],
                    $row['user_agent'],
                    $row['response_code'],
                    $row['duration_ms'],
                    $row['created_at'],
                ]);
            } catch (\Throwable $e) {
                // 审计失败不影响主流程
            }
        }
        self::$pending = [];
    }

    /**
     * 同步写入单条记录（测试用）。
     */
    public function recordSync(array $record): void
    {
        $db = self::getDb();
        if ($db === null) {
            return;
        }
        $stmt = $db->prepare(
            'INSERT INTO audit_log (user_id, method, path, params, ip, user_agent, response_code, duration_ms, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $record['user_id'] ?? 0,
            $record['method'] ?? 'GET',
            $record['path'] ?? '/',
            json_encode($record['params'] ?? [], JSON_UNESCAPED_UNICODE),
            $record['ip'] ?? '',
            $record['user_agent'] ?? '',
            $record['response_code'] ?? 200,
            $record['duration_ms'] ?? 0,
            $record['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    protected static function getDb(): ?PDO
    {
        if (self::$db !== null) {
            return self::$db;
        }
        try {
            self::$db = new PDO(self::instanceDsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::ensureSchema(self::$db);
            return self::$db;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static string $instanceDsn = '';

    protected static function ensureSchema(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS audit_log ("
            . "id INTEGER PRIMARY KEY AUTOINCREMENT, "
            . "user_id INT NOT NULL DEFAULT 0, "
            . "method VARCHAR(16) NOT NULL, "
            . "path VARCHAR(255) NOT NULL, "
            . "params TEXT, "
            . "ip VARCHAR(64), "
            . "user_agent VARCHAR(255), "
            . "response_code INT NOT NULL DEFAULT 200, "
            . "duration_ms INT NOT NULL DEFAULT 0, "
            . "created_at DATETIME NOT NULL"
            . ")"
        );
    }

    /* ---------------------------------------------------------------------
     * 请求信息读取辅助
     * ------------------------------------------------------------------- */

    protected function readPath($request): string
    {
        if (is_object($request) && method_exists($request, 'path')) {
            return $request->path();
        }
        return parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
    }

    protected function readMethod($request): string
    {
        if (is_object($request) && method_exists($request, 'method')) {
            return strtoupper($request->method());
        }
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    protected function readIp($request): string
    {
        if (is_object($request) && method_exists($request, 'ip')) {
            return $request->ip();
        }
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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

    protected function collectParams($request): array
    {
        if (is_object($request) && method_exists($request, 'param')) {
            $params = $request->param();
            return is_array($params) ? $params : [];
        }
        $params = array_merge($_GET, $_POST);
        // 脱敏密码字段
        foreach (['password', 'old_password', 'new_password', 'secret'] as $k) {
            if (isset($params[$k])) {
                $params[$k] = '***';
            }
        }
        return $params;
    }

    protected function extractResponseCode($response): int
    {
        if (is_array($response) && isset($response['__response__'])) {
            return (int)($response['status'] ?? 200);
        }
        return 200;
    }

    protected function extractUserId($request): int
    {
        if (is_object($request) && property_exists($request, 'userId')) {
            return (int)$request->userId;
        }
        if (is_array($request) && isset($request['userId'])) {
            return (int)$request['userId'];
        }
        return 0;
    }

    /**
     * 设置 DSN（实例方法，用于懒加载时初始化静态 DSN）。
     */
    public function setDsn(string $dsn): void
    {
        self::$instanceDsn = $dsn;
        $this->dsn = $dsn;
    }
}
