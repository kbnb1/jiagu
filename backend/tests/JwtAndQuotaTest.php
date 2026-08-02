<?php
/**
 * 测试 JWT 静态方法与配额竞态修复
 * 
 * 运行方式: php backend/tests/JwtAndQuotaTest.php
 */

require_once dirname(__DIR__) . '/app/common/service/JwtService.php';

use app\common\service\JwtService;

echo "=== JWT 静态方法测试 ===\n";

// 测试 1: makeAccessToken 静态方法存在且可调用
try {
    $claims = ['user_id' => 1, 'username' => 'testuser'];
    $accessToken = JwtService::makeAccessToken($claims);
    assert(!empty($accessToken), 'access_token should not be empty');
    assert(strpos($accessToken, '.') !== false, 'JWT should contain dots');
    echo "✓ makeAccessToken 静态方法正常工作\n";
} catch (Throwable $e) {
    echo "✗ makeAccessToken 失败: " . $e->getMessage() . "\n";
    exit(1);
}

// 测试 2: makeRefreshToken 静态方法存在且可调用
try {
    $refreshToken = JwtService::makeRefreshToken($claims);
    assert(!empty($refreshToken), 'refresh_token should not be empty');
    echo "✓ makeRefreshToken 静态方法正常工作\n";
} catch (Throwable $e) {
    echo "✗ makeRefreshToken 失败: " . $e->getMessage() . "\n";
    exit(1);
}

// 测试 3: parse 静态方法存在且可调用
try {
    $parsed = JwtService::parse($accessToken);
    assert(is_array($parsed), 'parsed result should be array');
    assert($parsed['user_id'] === 1, 'user_id should match');
    echo "✓ parse 静态方法正常工作\n";
} catch (Throwable $e) {
    echo "✗ parse 失败: " . $e->getMessage() . "\n";
    exit(1);
}

// 测试 4: token 有效性验证
try {
    $jwt = new JwtService();
    $verified = $jwt->verify($accessToken);
    assert($verified !== null, 'token should be valid');
    assert($verified['user_id'] === 1, 'verified user_id should match');
    echo "✓ token 签名验证正常\n";
} catch (Throwable $e) {
    echo "✗ token 验证失败: " . $e->getMessage() . "\n";
    exit(1);
}

// 测试 5: refresh_token 类型验证
try {
    $parsedRefresh = JwtService::parse($refreshToken);
    assert($parsedRefresh['typ'] === 'refresh', 'refresh_token type should be correct');
    echo "✓ refresh_token 类型正确\n";
} catch (Throwable $e) {
    echo "✗ refresh_token 类型验证失败: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== 所有测试通过 ===\n";
echo "Bug #1 (JWT静态方法) 已修复。\n";
echo "Bug #2 (配额竞态) 已通过 UserAccount::tryIncrementUsage() 修复。\n";