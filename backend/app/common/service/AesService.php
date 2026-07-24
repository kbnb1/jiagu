<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * AES 加密服务
 *
 * 提供 AES-256-CBC 对称加密/解密能力，供业务层与加固引擎共用。
 * 密钥从配置读取，支持基于用户 ID 派生每用户独立密钥。
 *
 * 密文格式：base64( IV(16) || ciphertext )，IV 随每次加密随机生成。
 */
class AesService
{
    /** 加密算法 */
    public const CIPHER = 'aes-256-cbc';

    /** IV 长度（字节） */
    public const IV_LENGTH = 16;

    /** 密钥长度（字节，AES-256 = 32） */
    public const KEY_LENGTH = 32;

    /** @var string 主密钥（用于派生用户密钥的根） */
    private string $masterKey;

    /** @var string 密钥派生盐 */
    private string $salt;

    /**
     * @param array $config 配置：
     *   - master_key: 主密钥（缺省时从环境变量 AES_MASTER_KEY 读取）
     *   - salt:       密钥派生盐
     */
    public function __construct(array $config = [])
    {
        $this->masterKey = $config['master_key'] ?? (getenv('AES_MASTER_KEY') ?: 'trae-aes-master-key-2026-change-me');
        $this->salt      = $config['salt'] ?? (getenv('AES_SALT') ?: 'trae-hardening-salt-v1');
    }

    /**
     * AES-256-CBC 加密，返回 base64 编码的密文（IV 拼接密文）。
     *
     * @param string      $data 待加密明文
     * @param string|null $key  自定义密钥（为空则使用主密钥派生）
     * @return string base64 编码密文
     * @throws \RuntimeException 加密失败时抛出
     */
    public function encrypt(string $data, ?string $key = null): string
    {
        $rawKey = $this->resolveKey($key);
        $iv = random_bytes(self::IV_LENGTH);
        $cipher = openssl_encrypt($data, self::CIPHER, $rawKey, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('AES encrypt failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        return base64_encode($iv . $cipher);
    }

    /**
     * 解密 base64 编码的密文。
     *
     * @param string      $encrypted base64 密文
     * @param string|null $key       自定义密钥
     * @return string 明文
     * @throws \RuntimeException 解密失败时抛出
     */
    public function decrypt(string $encrypted, ?string $key = null): string
    {
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + 1) {
            throw new \RuntimeException('AES decrypt failed: invalid ciphertext');
        }
        $rawKey = $this->resolveKey($key);
        $iv = substr($raw, 0, self::IV_LENGTH);
        $cipher = substr($raw, self::IV_LENGTH);
        $plain = openssl_decrypt($cipher, self::CIPHER, $rawKey, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('AES decrypt failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        return $plain;
    }

    /**
     * 生成随机密钥（32 字节，hex 编码返回 64 字符）。
     *
     * @param bool $raw 是否返回原始二进制（默认返回 hex）
     * @return string
     */
    public function generateKey(bool $raw = false): string
    {
        $bytes = random_bytes(self::KEY_LENGTH);
        return $raw ? $bytes : bin2hex($bytes);
    }

    /**
     * 基于用户 ID 派生独立密钥。
     *
     * 使用 HKDF-like 方案：HMAC-SHA256(masterKey, salt || userId) 取前 32 字节。
     * 相同用户每次派生结果一致，不同用户互不相同。
     *
     * @param int|string $userId 用户标识
     * @return string 32 字节原始密钥
     */
    public function deriveUserKey($userId): string
    {
        $info = $this->salt . '|' . (string)$userId;
        $prk = hash_hmac('sha256', $info, $this->masterKey, true);
        // HKDF-Expand：单块即可得到 32 字节
        $t = hash_hmac('sha256', chr(1) . $info, $prk, true);
        return substr($t, 0, self::KEY_LENGTH);
    }

    /**
     * 为指定用户加密数据（使用派生密钥）。
     *
     * @param string     $data
     * @param int|string $userId
     * @return string
     */
    public function encryptForUser(string $data, $userId): string
    {
        return $this->encrypt($data, $this->deriveUserKey($userId));
    }

    /**
     * 为指定用户解密数据。
     *
     * @param string     $encrypted
     * @param int|string $userId
     * @return string
     */
    public function decryptForUser(string $encrypted, $userId): string
    {
        return $this->decrypt($encrypted, $this->deriveUserKey($userId));
    }

    /**
     * 解析密钥：若传入原始二进制则直接使用，否则通过 SHA-256 派生 32 字节。
     */
    private function resolveKey(?string $key): string
    {
        if ($key === null) {
            // 默认使用主密钥的 SHA-256 派生
            return hash('sha256', $this->masterKey, true);
        }
        if (strlen($key) === self::KEY_LENGTH) {
            return $key; // 已是 32 字节原始密钥
        }
        // hex 编码的 64 字符密钥
        if (strlen($key) === self::KEY_LENGTH * 2 && ctype_xdigit($key)) {
            $decoded = hex2bin($key);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        // 其他情况用 SHA-256 派生
        return hash('sha256', $key, true);
    }

    /**
     * 获取主密钥（仅用于内部调试，生产环境不应暴露）。
     */
    public function getMasterKey(): string
    {
        return $this->masterKey;
    }
}
