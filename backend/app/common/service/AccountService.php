<?php
declare(strict_types=1);

namespace app\common\service;

use PDO;
use RuntimeException;

/**
 * 用户账户服务
 *
 * 负责用户注册、登录、登出、token 刷新、资料与密码管理。
 * 密码使用 password_hash / password_verify（bcrypt）。
 * 注册时自动创建 UserAccount 记录（免费计划）。
 *
 * 存储基于 PDO，默认使用 SQLite（自动建表），可切换 MySQL。
 */
class AccountService
{
    /** 免费计划配额（加固次数） */
    public const FREE_PLAN_QUOTA = 10;

    /** @var PDO|null 数据库连接 */
    private ?PDO $db = null;

    /** @var array 数据库配置 */
    private array $dbConfig;

    /** @var JwtService JWT 服务 */
    private JwtService $jwt;

    /** @var AesService AES 服务（用于加密敏感资料） */
    private AesService $aes;

    /**
     * @param array $config 数据库与依赖配置：
     *   - dsn:      PDO DSN，缺省 sqlite:runtime/account.db
     *   - username: 数据库用户名
     *   - password: 数据库密码
     *   - jwt:      JwtService 配置数组
     *   - aes:      AesService 配置数组
     */
    public function __construct(array $config = [])
    {
        $this->dbConfig = [
            'dsn'      => $config['dsn'] ?? ('sqlite:' . $this->defaultDbPath()),
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
            'options'  => $config['options'] ?? [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        ];
        $this->jwt = $config['jwt_service'] ?? new JwtService($config['jwt'] ?? []);
        $this->aes = $config['aes_service'] ?? new AesService($config['aes'] ?? []);
    }

    /**
     * 注册新用户并返回 token。
     *
     * @param string $username 用户名（3-32 字符，字母数字下划线）
     * @param string $password 密码（至少 6 位）
     * @return array{user:array,tokens:array}
     * @throws RuntimeException 用户名已存在或参数非法
     */
    public function register(string $username, string $password): array
    {
        $this->validateUsername($username);
        $this->validatePassword($password);

        $db = $this->getDb();

        // 检查用户名是否已存在
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new RuntimeException('用户名已被占用');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $now = date('Y-m-d H:i:s');

        // 创建用户记录
        $stmt = $db->prepare(
            'INSERT INTO users (username, password_hash, nickname, is_admin, status, created_at, updated_at) '
            . 'VALUES (?, ?, ?, 0, 1, ?, ?)'
        );
        $stmt->execute([$username, $passwordHash, $username, $now, $now]);
        $userId = (int)$db->lastInsertId();

        // 自动创建 UserAccount 记录（免费计划）
        $this->createUserAccount($userId, 'free', self::FREE_PLAN_QUOTA);

        // 生成 token
        $tokens = $this->jwt->generateTokenPair([
            'user_id'  => $userId,
            'username' => $username,
        ]);

        return [
            'user'   => $this->getUserProfile($userId),
            'tokens' => $tokens,
        ];
    }

    /**
     * 用户登录。
     *
     * @param string $username
     * @param string $password
     * @return array{user:array,tokens:array}
     * @throws RuntimeException 凭证错误或账户被禁用
     */
    public function login(string $username, string $password): array
    {
        $db = $this->getDb();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('用户名或密码错误');
        }

        if (isset($user['status']) && (int)$user['status'] !== 1) {
            throw new RuntimeException('账户已被禁用');
        }

        $userId = (int)$user['id'];
        $tokens = $this->jwt->generateTokenPair([
            'user_id'  => $userId,
            'username' => $user['username'],
            'is_admin' => (int)($user['is_admin'] ?? 0),
        ]);

        // 更新最后登录时间
        $stmt = $db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $userId]);

        return [
            'user'   => $this->getUserProfile($userId),
            'tokens' => $tokens,
        ];
    }

    /**
     * 登出（将 access_token 加入黑名单）。
     *
     * @param string $token access_token
     * @return bool
     */
    public function logout(string $token): bool
    {
        return $this->jwt->logout($token);
    }

    /**
     * 刷新 token。
     *
     * @param string $refreshToken
     * @return array 新的 token 对
     * @throws RuntimeException refresh_token 无效
     */
    public function refreshToken(string $refreshToken): array
    {
        $tokens = $this->jwt->refresh($refreshToken);
        if ($tokens === null) {
            throw new RuntimeException('refresh_token 无效或已过期');
        }
        return $tokens;
    }

    /**
     * 获取用户资料。
     *
     * @param int $userId
     * @return array
     * @throws RuntimeException 用户不存在
     */
    public function getUserProfile(int $userId): array
    {
        $db = $this->getDb();
        $stmt = $db->prepare(
            'SELECT u.id, u.username, u.nickname, u.email, u.avatar, u.is_admin, u.status, '
            . 'u.last_login_at, u.created_at, '
            . 'a.plan_type, a.quota_used, a.quota_limit, a.expires_at '
            . 'FROM users u LEFT JOIN user_accounts a ON a.user_id = u.id '
            . 'WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('用户不存在');
        }
        $row['id'] = (int)$row['id'];
        $row['is_admin'] = (int)($row['is_admin'] ?? 0);
        $row['status'] = (int)($row['status'] ?? 1);
        $row['quota_used'] = (int)($row['quota_used'] ?? 0);
        $row['quota_limit'] = (int)($row['quota_limit'] ?? 0);
        return $row;
    }

    /**
     * 更新用户资料。
     *
     * @param int   $userId
     * @param array $data 可更新字段：nickname, email, avatar
     * @return bool
     */
    public function updateProfile(int $userId, array $data): bool
    {
        $allowed = ['nickname', 'email', 'avatar'];
        $updates = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = $field . ' = ?';
                $params[] = $data[$field];
            }
        }
        if (empty($updates)) {
            return false;
        }
        $updates[] = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $userId;

        $db = $this->getDb();
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * 修改密码。
     *
     * @param int    $userId
     * @param string $oldPassword 旧密码
     * @param string $newPassword 新密码（至少 6 位）
     * @return bool
     * @throws RuntimeException 旧密码错误或新密码非法
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): bool
    {
        $this->validatePassword($newPassword);

        $db = $this->getDb();
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('用户不存在');
        }
        if (!password_verify($oldPassword, $row['password_hash'])) {
            throw new RuntimeException('旧密码错误');
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$newHash, date('Y-m-d H:i:s'), $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * 校验 access_token 并返回 claims。
     *
     * @param string $token
     * @return array|null
     */
    public function verifyToken(string $token): ?array
    {
        return $this->jwt->verify($token);
    }

    /**
     * 获取用户的加固配额信息。
     *
     * @param int $userId
     * @return array{plan_type:string,quota_used:int,quota_limit:int,remaining:int}
     */
    public function getQuota(int $userId): array
    {
        $db = $this->getDb();
        $stmt = $db->prepare('SELECT plan_type, quota_used, quota_limit FROM user_accounts WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['plan_type' => 'free', 'quota_used' => 0, 'quota_limit' => 0, 'remaining' => 0];
        }
        $used = (int)$row['quota_used'];
        $limit = (int)$row['quota_limit'];
        return [
            'plan_type'  => $row['plan_type'],
            'quota_used' => $used,
            'quota_limit' => $limit,
            'remaining'  => max(0, $limit - $used),
        ];
    }

    /**
     * 消耗一次加固配额。
     *
     * @param int $userId
     * @return bool 配额是否足够并已扣除
     */
    public function consumeQuota(int $userId): bool
    {
        $db = $this->getDb();
        $stmt = $db->prepare(
            'UPDATE user_accounts SET quota_used = quota_used + 1 '
            . 'WHERE user_id = ? AND quota_used < quota_limit'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * 获取注入的 JwtService 实例（供中间件复用）。
     */
    public function getJwtService(): JwtService
    {
        return $this->jwt;
    }

    /**
     * 获取注入的 AesService 实例。
     */
    public function getAesService(): AesService
    {
        return $this->aes;
    }

    /* ---------------------------------------------------------------------
     * 内部实现
     * ------------------------------------------------------------------- */

    /**
     * 创建用户账户（订阅计划）记录。
     */
    private function createUserAccount(int $userId, string $planType, int $quotaLimit): void
    {
        $db = $this->getDb();
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
        $stmt = $db->prepare(
            'INSERT INTO user_accounts (user_id, plan_type, quota_used, quota_limit, expires_at, created_at, updated_at) '
            . 'VALUES (?, ?, 0, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $planType, $quotaLimit, $expiresAt, $now, $now]);
    }

    /**
     * 校验用户名格式。
     */
    private function validateUsername(string $username): void
    {
        $len = strlen($username);
        if ($len < 3 || $len > 32) {
            throw new RuntimeException('用户名长度必须为 3-32 字符');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $username)) {
            throw new RuntimeException('用户名只能包含字母、数字和下划线，且以字母开头');
        }
    }

    /**
     * 校验密码强度。
     */
    private function validatePassword(string $password): void
    {
        if (strlen($password) < 6) {
            throw new RuntimeException('密码长度至少 6 位');
        }
        if (strlen($password) > 128) {
            throw new RuntimeException('密码长度不能超过 128 位');
        }
    }

    /**
     * 懒加载数据库连接，并自动建表（SQLite 场景）。
     */
    private function getDb(): PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }
        $this->db = new PDO(
            $this->dbConfig['dsn'],
            $this->dbConfig['username'],
            $this->dbConfig['password'],
            $this->dbConfig['options']
        );
        $this->ensureSchema();
        return $this->db;
    }

    /**
     * 注入外部 PDO 实例（用于测试）。
     */
    public function setDb(PDO $db): void
    {
        $this->db = $db;
        $this->ensureSchema();
    }

    /**
     * 自动建表（仅在表不存在时）。
     */
    private function ensureSchema(): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $autoInc = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS users ("
            . "id {$autoInc}, "
            . "username VARCHAR(64) NOT NULL UNIQUE, "
            . "password_hash VARCHAR(255) NOT NULL, "
            . "nickname VARCHAR(64), "
            . "email VARCHAR(128), "
            . "avatar VARCHAR(255), "
            . "is_admin TINYINT NOT NULL DEFAULT 0, "
            . "status TINYINT NOT NULL DEFAULT 1, "
            . "last_login_at DATETIME NULL, "
            . "created_at DATETIME NOT NULL, "
            . "updated_at DATETIME NOT NULL"
            . ")"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS user_accounts ("
            . "id {$autoInc}, "
            . "user_id INT NOT NULL UNIQUE, "
            . "plan_type VARCHAR(32) NOT NULL DEFAULT 'free', "
            . "quota_used INT NOT NULL DEFAULT 0, "
            . "quota_limit INT NOT NULL DEFAULT 0, "
            . "expires_at DATETIME NULL, "
            . "created_at DATETIME NOT NULL, "
            . "updated_at DATETIME NOT NULL"
            . ")"
        );
    }

    /**
     * 默认 SQLite 数据库路径。
     */
    private function defaultDbPath(): string
    {
        $dir = dirname(__DIR__, 3) . '/runtime';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/account.db';
    }
}
