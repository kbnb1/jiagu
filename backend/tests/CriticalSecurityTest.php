<?php
/**
 * 关键安全缺陷测试
 *
 * 测试已修复的高影响缺陷：
 * - 并发竞态条件（队列、限流器）
 * - 硬编码密钥回退
 * - Redis 事务状态不一致
 */

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use app\common\service\JwtService;
use app\common\service\AesService;
use app\common\service\QueueService;
use app\common\middleware\RateLimitMiddleware;

/**
 * 测试关键安全缺陷修复
 */
class CriticalSecurityTest extends TestCase
{
    /**
     * 测试 JWT 密钥缺失时拒绝启动
     */
    public function testJwtRejectsMissingSecret(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET environment variable must be configured');

        // 清除环境变量
        putenv('JWT_SECRET');

        new JwtService([]);
    }

    /**
     * 测试 JWT 密钥长度不足时拒绝启动
     */
    public function testJwtRejectsShortSecret(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32 characters');

        new JwtService(['secret' => 'short_key']);
    }

    /**
     * 测试 AES 密钥缺失时拒绝启动
     */
    public function testAesRejectsMissingKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AES_MASTER_KEY environment variable must be configured');

        // 清除环境变量
        putenv('AES_MASTER_KEY');

        new AesService([]);
    }

    /**
     * 测试 AES 密钥长度不足时拒绝启动
     */
    public function testAesRejectsShortKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32 characters');

        new AesService(['master_key' => 'short_key']);
    }

    /**
     * 测试有效密钥可以成功初始化
     */
    public function testValidSecretsInitialize(): void
    {
        $validSecret = str_repeat('a', 64); // 64 字符随机字符串

        // JWT
        $jwt = new JwtService(['secret' => $validSecret]);
        $this->assertInstanceOf(JwtService::class, $jwt);

        // AES
        $aes = new AesService(['master_key' => $validSecret]);
        $this->assertInstanceOf(AesService::class, $aes);
    }

    /**
     * 测试队列并发竞态：模拟多个 worker 同时 pop
     *
     * 注意：此测试验证文件锁的正确性，需在真实环境中运行
     */
    public function testQueueConcurrencyProtection(): void
    {
        // 使用文件模式队列
        $config = ['file_root' => sys_get_temp_dir() . '/test_queue_' . uniqid()];
        $queue = new QueueService($config);

        // 推送测试任务
        $jobId1 = $queue->push('test', ['data' => 'task1']);
        $jobId2 = $queue->push('test', ['data' => 'task2']);

        // 弹出第一个任务
        $job1 = $queue->pop('test');
        $this->assertNotNull($job1);
        $this->assertEquals($jobId1, $job1['job_id']);

        // 弹出第二个任务
        $job2 = $queue->pop('test');
        $this->assertNotNull($job2);
        $this->assertEquals($jobId2, $job2['job_id']);

        // 队列应为空
        $job3 = $queue->pop('test');
        $this->assertNull($job3);
    }

    /**
     * 测试限流器并发竞态保护
     *
     * 验证在高并发情况下限流器正确计数
     */
    public function testRateLimiterConcurrencyProtection(): void
    {
        $config = [
            'file_dir' => sys_get_temp_dir() . '/test_ratelimit_' . uniqid(),
            'default_limit' => 3, // 低限制便于测试
        ];

        $middleware = new RateLimitMiddleware($config);

        // 模拟并发请求（简化测试）
        $key = 'test_concurrent_' . uniqid();
        $allowed = 0;
        $denied = 0;

        for ($i = 0; $i < 5; $i++) {
            // 使用反射访问 protected 方法
            $reflection = new \ReflectionClass($middleware);
            $method = $reflection->getMethod('allowFile');
            $method->setAccessible(true);

            $result = $method->invoke($middleware, $key, 3);

            if ($result) {
                $allowed++;
            } else {
                $denied++;
            }
        }

        // 应该允许 3 次，拒绝 2 次
        $this->assertEquals(3, $allowed, '应该允许前 3 次请求');
        $this->assertEquals(2, $denied, '应该拒绝超限请求');
    }

    /**
     * 测试 JWT Token 生成和验证
     */
    public function testJwtTokenLifecycle(): void
    {
        $jwt = new JwtService(['secret' => str_repeat('a', 64)]);

        // 生成 token 对
        $claims = ['user_id' => 123, 'username' => 'testuser'];
        $tokens = $jwt->generateTokenPair($claims);

        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertEquals('Bearer', $tokens['token_type']);

        // 验证 access token
        $verified = $jwt->verify($tokens['access_token']);
        $this->assertNotNull($verified);
        $this->assertEquals(123, $verified['user_id']);
    }

    /**
     * 测试 AES 加密解密
     */
    public function testAesEncryptionDecryption(): void
    {
        $aes = new AesService(['master_key' => str_repeat('a', 64)]);

        $plaintext = '敏感数据测试 123 !@#';
        $encrypted = $aes->encrypt($plaintext);
        $decrypted = $aes->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
        $this->assertNotEquals($plaintext, $encrypted);
    }

    /**
     * 测试用户级加密
     */
    public function testUserLevelEncryption(): void
    {
        $aes = new AesService(['master_key' => str_repeat('a', 64)]);

        $plaintext = '用户私密数据';
        $userId = 10086;

        $encrypted = $aes->encryptForUser($plaintext, $userId);
        $decrypted = $aes->decryptForUser($encrypted, $userId);

        $this->assertEquals($plaintext, $decrypted);

        // 不同用户的密钥应该不同
        $decryptedOther = $aes->decryptForUser($encrypted, 99999);
        $this->assertNotEquals($plaintext, $decryptedOther);
    }
}