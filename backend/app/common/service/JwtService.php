<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * JWT 认证服务
 *
 * 实现 JSON Web Token 的签发、验证、刷新与黑名单机制。
 * 使用 HMAC-SHA256 签名算法。access_token 默认 7 天有效，refresh_token 30 天。
 *
 * 黑名单机制：登出时将 token 的 jti 写入黑名单，验证时拒绝已拉黑的 token。
 * 黑名单存储默认基于文件（runtime/jwt_blacklist/），可切换至 Redis。
 */
class JwtService
{
    /** 签名算法 */
    public const ALG = 'HS256';

    /** 签名算法对应的 hash 函数 */
    public const HASH_ALGO = 'sha256';

    /** access_token 默认有效期（秒）：7 天 */
    public const ACCESS_TTL = 7 * 24 * 3600;

    /** refresh_token 默认有效期（秒）：30 天 */
    public const REFRESH_TTL = 30 * 24 * 3600;

    /** token 类型 */
    public const TYPE_ACCESS  = 'access';
    public const TYPE_REFRESH = 'refresh';

    /** @var string HMAC 签名密钥 */
    private string $secret;

    /** @var string 签发者 */
    private string $issuer;

    /** @var int access token 有效期（秒） */
    private int $accessTtl;

    /** @var int refresh token 有效期（秒） */
    private int $refreshTtl;

    /** @var string 黑名单存储目录（文件模式） */
    private string $blacklistDir;

    /** @var object|null Redis 客户端实例（可选） */
    private $redis = null;

    /** @var bool 是否使用 Redis 存储黑名单 */
    private bool $useRedis = false;

    /**
     * @param array $config 配置：
     *   - secret:      HMAC 密钥（必填，缺省时从环境变量读取）
     *   - issuer:      签发者
     *   - access_ttl:  access token 有效期秒数
     *   - refresh_ttl: refresh token 有效期秒数
     *   - blacklist_dir: 文件黑名单目录
     *   - redis:       Redis 客户端实例（提供则启用 Redis 黑名单）
     */
    public function __construct(array $config = [])
    {
        $this->secret      = $config['secret'] ?? (getenv('JWT_SECRET') ?: 'trae-jwt-default-secret-change-me-2026');
        $this->issuer      = $config['issuer'] ?? (getenv('APP_NAME') ?: 'trae-hardening-platform');
        $this->accessTtl   = (int)($config['access_ttl'] ?? self::ACCESS_TTL);
        $this->refreshTtl  = (int)($config['refresh_ttl'] ?? self::REFRESH_TTL);
        $this->blacklistDir = $config['blacklist_dir'] ?? (sys_get_temp_dir() . '/trae_jwt_blacklist');

        if (!is_dir($this->blacklistDir)) {
            @mkdir($this->blacklistDir, 0700, true);
        }

        if (isset($config['redis']) && $config['redis'] !== null) {
            $this->redis = $config['redis'];
            $this->useRedis = true;
        }
    }

    /**
     * 生成 access_token（7 天有效期）。
     *
     * @param array $claims 业务 claims，如 ['user_id' => 1, 'username' => 'foo']
     * @return string
     */
    public function generateAccessToken(array $claims): string
    {
        return $this->encode($claims, self::TYPE_ACCESS, $this->accessTtl);
    }

    /**
     * 生成 refresh_token（30 天有效期）。
     *
     * @param array $claims 业务 claims
     * @return string
     */
    public function generateRefreshToken(array $claims): string
    {
        return $this->encode($claims, self::TYPE_REFRESH, $this->refreshTtl);
    }

    /**
     * 同时生成 access_token 与 refresh_token。
     *
     * @param array $claims
     * @return array{access_token:string,refresh_token:string,expires_in:int,refresh_expires_in:int}
     */
    public function generateTokenPair(array $claims): array
    {
        return [
            'access_token'       => $this->generateAccessToken($claims),
            'refresh_token'      => $this->generateRefreshToken($claims),
            'expires_in'         => $this->accessTtl,
            'refresh_expires_in' => $this->refreshTtl,
            'token_type'         => 'Bearer',
        ];
    }

    /**
     * 验证 token 并返回 claims。
     *
     * @param string $token
     * @return array|null 验证失败返回 null，成功返回 claims
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        // 校验签名
        $expectedSig = $this->sign($headerB64 . '.' . $payloadB64);
        if (!$this->constantTimeEquals($sigB64, $expectedSig)) {
            return null;
        }

        $header = $this->jsonDecode($this->base64UrlDecode($headerB64));
        if (!is_array($header) || ($header['alg'] ?? '') !== self::ALG) {
            return null;
        }

        $payload = $this->jsonDecode($this->base64UrlDecode($payloadB64));
        if (!is_array($payload)) {
            return null;
        }

        // 校验过期时间
        $now = time();
        if (!isset($payload['exp']) || $payload['exp'] < $now) {
            return null;
        }

        // 校验签发者
        if (isset($payload['iss']) && $payload['iss'] !== $this->issuer) {
            return null;
        }

        // 校验生效时间（nbf）
        if (isset($payload['nbf']) && $payload['nbf'] > $now) {
            return null;
        }

        // 校验黑名单
        $jti = $payload['jti'] ?? null;
        if ($jti !== null && $this->isBlacklisted($jti)) {
            return null;
        }

        return $payload;
    }

    /**
     * 仅当 token 类型匹配时返回 claims。
     *
     * @param string $token
     * @param string $expectedType
     * @return array|null
     */
    public function verifyType(string $token, string $expectedType): ?array
    {
        $claims = $this->verify($token);
        if ($claims === null) {
            return null;
        }
        if (($claims['typ'] ?? '') !== $expectedType) {
            return null;
        }
        return $claims;
    }

    /**
     * 用 refresh_token 换取新的 token 对。
     *
     * @param string $refreshToken
     * @return array{access_token:string,refresh_token:string,expires_in:int,refresh_expires_in:int}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        $claims = $this->verifyType($refreshToken, self::TYPE_REFRESH);
        if ($claims === null) {
            return null;
        }

        // 将旧 refresh_token 加入黑名单，防止重放
        if (isset($claims['jti'])) {
            $this->blacklist($claims['jti'], $claims['exp'] - time());
        }

        // 提取业务 claims（剔除标准字段）
        $business = array_diff_key($claims, array_flip(['iss', 'iat', 'exp', 'nbf', 'jti', 'typ']));

        return $this->generateTokenPair($business);
    }

    /**
     * 将 token 加入黑名单（登出使用）。
     *
     * @param string $token
     * @return bool
     */
    public function logout(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        $payload = $this->jsonDecode($this->base64UrlDecode($parts[1]));
        if (!is_array($payload) || !isset($payload['jti'])) {
            return false;
        }
        $ttl = isset($payload['exp']) ? ($payload['exp'] - time()) : $this->accessTtl;
        if ($ttl <= 0) {
            return true; // 已过期，无需拉黑
        }
        return $this->blacklist($payload['jti'], $ttl);
    }

    /**
     * 将指定 jti 加入黑名单。
     *
     * @param string $jti    token 唯一标识
     * @param int    $ttl    黑名单保留秒数（过期后自动清理）
     * @return bool
     */
    public function blacklist(string $jti, int $ttl): bool
    {
        if ($ttl <= 0) {
            return true;
        }
        $expireAt = time() + $ttl;

        if ($this->useRedis && $this->redis) {
            try {
                $key = 'jwt:bl:' . $jti;
                $this->redis->setex($key, $ttl, (string)$expireAt);
                return true;
            } catch (\Throwable $e) {
                // 回退到文件
            }
        }

        // 文件模式：写入标记文件，文件名带过期时间戳便于清理
        $file = $this->blacklistDir . '/' . md5($jti) . '.' . $expireAt;
        return (bool)@file_put_contents($file, $jti);
    }

    /**
     * 判断 jti 是否在黑名单中。
     */
    public function isBlacklisted(string $jti): bool
    {
        if ($this->useRedis && $this->redis) {
            try {
                return (bool)$this->redis->exists('jwt:bl:' . $jti);
            } catch (\Throwable $e) {
                // 回退到文件
            }
        }

        $hash = md5($jti);
        $now = time();
        $found = false;
        foreach (glob($this->blacklistDir . '/' . $hash . '.*') ?: [] as $file) {
            $expireAt = (int)substr(strrchr(basename($file), '.'), 1);
            if ($expireAt <= $now) {
                @unlink($file); // 过期清理
                continue;
            }
            $found = true;
        }
        return $found;
    }

    /**
     * 清理已过期的黑名单条目（文件模式）。
     */
    public function cleanupBlacklist(): int
    {
        if ($this->useRedis) {
            return 0; // Redis 自带过期
        }
        $now = time();
        $count = 0;
        foreach (glob($this->blacklistDir . '/*.*') ?: [] as $file) {
            $expireAt = (int)substr(strrchr(basename($file), '.'), 1);
            if ($expireAt <= $now) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * 从 token 中提取 claims（不校验签名，仅解析）。
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = $this->jsonDecode($this->base64UrlDecode($parts[1]));
        return is_array($payload) ? $payload : null;
    }

    /* ---------------------------------------------------------------------
     * 内部实现
     * ------------------------------------------------------------------- */

    /**
     * 编码生成 JWT。
     *
     * @param array  $claims 业务 claims
     * @param string $type   token 类型
     * @param int    $ttl    有效期秒数
     * @return string
     */
    private function encode(array $claims, string $type, int $ttl): string
    {
        $now = time();
        $header = ['typ' => 'JWT', 'alg' => self::ALG];
        $payload = array_merge($claims, [
            'iss' => $this->issuer,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => $this->generateJti(),
            'typ' => $type,
        ]);

        $headerB64  = $this->base64UrlEncode($this->jsonEncode($header));
        $payloadB64 = $this->base64UrlEncode($this->jsonEncode($payload));
        $signature  = $this->sign($headerB64 . '.' . $payloadB64);

        return $headerB64 . '.' . $payloadB64 . '.' . $signature;
    }

    /**
     * 对 header.payload 进行 HMAC-SHA256 签名，返回 base64url 编码的签名。
     */
    private function sign(string $data): string
    {
        $raw = hash_hmac(self::HASH_ALGO, $data, $this->secret, true);
        return $this->base64UrlEncode($raw);
    }

    /**
     * 生成唯一 jti（JWT ID）。
     */
    private function generateJti(): string
    {
        return bin2hex(random_bytes(12)) . dechex(time());
    }

    /**
     * 常量时间字符串比较，防止时序攻击。
     */
    private function constantTimeEquals(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        return hash_equals($a, $b);
    }

    /**
     * base64url 编码（无填充）。
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * base64url 解码。
     */
    private function base64UrlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'));
        return $decoded === false ? '' : $decoded;
    }

    /**
     * JSON 编码（统一选项）。
     */
    private function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }

    /**
     * JSON 解码为关联数组。
     */
    private function jsonDecode(string $data): ?array
    {
        $arr = json_decode($data, true);
        return is_array($arr) ? $arr : null;
    }

    /**
     * 获取当前 access token 有效期。
     */
    public function getAccessTtl(): int
    {
        return $this->accessTtl;
    }

    /**
     * 获取当前 refresh token 有效期。
     */
    public function getRefreshTtl(): int
    {
        return $this->refreshTtl;
    }

    /* ---------------------------------------------------------------------
     * 静态快捷方法（用于控制器直接调用）
     * ------------------------------------------------------------------- */

    /** @var JwtService|null 单例实例 */
    private static ?JwtService $instance = null;

    /**
     * 获取单例实例。
     */
    public static function getInstance(): JwtService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 静态快捷：生成 access token。
     */
    public static function makeAccessToken(array $claims): string
    {
        return self::getInstance()->generateAccessToken($claims);
    }

    /**
     * 静态快捷：生成 refresh token。
     */
    public static function makeRefreshToken(array $claims): string
    {
        return self::getInstance()->generateRefreshToken($claims);
    }

    /**
     * 静态快捷：解析 token（不验证签名）。
     */
    public static function parse(string $token): ?array
    {
        return self::getInstance()->decode($token);
    }
}
