<?php
/**
 * JwtService 静态方法测试
 *
 * 验证静态快捷方法是否存在且正常工作
 */

require_once __DIR__ . '/../app/common/service/JwtService.php';

use app\common\service\JwtService;

class JwtServiceStaticTest
{
    public static function testMakeAccessToken(): bool
    {
        $claims = ['user_id' => 1, 'username' => 'test'];
        $token = JwtService::makeAccessToken($claims);

        if (empty($token)) {
            echo "FAIL: makeAccessToken returned empty token\n";
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            echo "FAIL: Invalid JWT format, expected 3 parts, got " . count($parts) . "\n";
            return false;
        }

        echo "PASS: makeAccessToken works correctly\n";
        return true;
    }

    public static function testMakeRefreshToken(): bool
    {
        $claims = ['user_id' => 1, 'username' => 'test'];
        $token = JwtService::makeRefreshToken($claims);

        if (empty($token)) {
            echo "FAIL: makeRefreshToken returned empty token\n";
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            echo "FAIL: Invalid JWT format\n";
            return false;
        }

        echo "PASS: makeRefreshToken works correctly\n";
        return true;
    }

    public static function testParse(): bool
    {
        $claims = ['user_id' => 1, 'username' => 'testuser'];
        $token = JwtService::makeAccessToken($claims);

        $parsed = JwtService::parse($token);

        if ($parsed === null) {
            echo "FAIL: parse returned null\n";
            return false;
        }

        if (($parsed['user_id'] ?? null) !== 1) {
            echo "FAIL: parsed user_id mismatch\n";
            return false;
        }

        if (($parsed['username'] ?? null) !== 'testuser') {
            echo "FAIL: parsed username mismatch\n";
            return false;
        }

        echo "PASS: parse works correctly\n";
        return true;
    }

    public static function run(): int
    {
        $allPass = true;
        $allPass = self::testMakeAccessToken() && $allPass;
        $allPass = self::testMakeRefreshToken() && $allPass;
        $allPass = self::testParse() && $allPass;

        echo "\n";
        echo $allPass ? "All tests PASSED\n" : "Some tests FAILED\n";
        return $allPass ? 0 : 1;
    }
}

// 运行测试
exit(JwtServiceStaticTest::run());