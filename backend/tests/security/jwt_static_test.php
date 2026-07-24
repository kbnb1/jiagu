<?php
declare(strict_types=1);

/**
 * 回归测试: JwtService 静态门面
 *
 * 复现的历史缺陷 (Bug 1):
 *   UserController::login() 与 UserController::refresh() 直接调用
 *   JwtService::makeAccessToken/makeRefreshToken/parse, 但 JwtService
 *   原先没有这些 static 门面, 触发 fatal error, 登录/刷新流程整体崩溃.
 *
 * 验证: 静态方法能成功签发与解析, 且 verify 校验通过.
 *
 * 用法:  php tests/security/jwt_static_test.php
 */

require_once __DIR__ . '/../../app/common/service/JwtService.php';

use app\common\service\JwtService;

$passed = 0;
$failed = 0;
function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $msg\n"; }
    else       { $failed++; echo "  FAIL  $msg\n"; }
}

// 重置共享实例, 使用测试密钥
JwtService::setShared(new JwtService([
    'secret'     => 'test-secret-' . bin2hex(random_bytes(8)),
    'issuer'     => 'test-issuer',
    'access_ttl' => 3600,
    'refresh_ttl'=> 7200,
]));

// 1. 静态方法存在
check(
    method_exists(JwtService::class, 'makeAccessToken'),
    'JwtService::makeAccessToken 静态方法存在'
);
check(
    method_exists(JwtService::class, 'makeRefreshToken'),
    'JwtService::makeRefreshToken 静态方法存在'
);
check(
    method_exists(JwtService::class, 'parse'),
    'JwtService::parse 静态方法存在'
);

// 2. 生成 access_token
$claims = ['user_id' => 42, 'username' => 'alice'];
$token = JwtService::makeAccessToken($claims);
check(
    is_string($token) && substr_count($token, '.') === 2,
    'JwtService::makeAccessToken 返回标准 JWT (三段式)'
);

// 3. 解析 token (不校验签名)
$parsed = JwtService::parse($token);
check(
    is_array($parsed) && ($parsed['user_id'] ?? null) === 42,
    'JwtService::parse 正确还原 claims (含 user_id)'
);
check(
    ($parsed['typ'] ?? null) === 'access',
    'JwtService::parse 解析出的 token 类型为 access'
);

// 4. 完整 verify 通过
$svc = JwtService::shared();
$verified = $svc->verify($token);
check(
    is_array($verified) && ($verified['user_id'] ?? null) === 42,
    'JwtService::shared()->verify 校验通过 access_token'
);

// 5. refresh_token
$refresh = JwtService::makeRefreshToken($claims);
$parsedRefresh = JwtService::parse($refresh);
check(
    ($parsedRefresh['typ'] ?? null) === 'refresh',
    'JwtService::makeRefreshToken 签发的 token 类型为 refresh'
);
check(
    is_array($svc->verifyType($refresh, JwtService::TYPE_REFRESH)),
    'JwtService::shared()->verifyType(refresh) 校验通过'
);

// 6. 回归点: 模拟原 bug 调用栈 (注入非法类型)
$bad = $svc->verifyType($refresh, JwtService::TYPE_ACCESS);
check(
    $bad === null,
    'access 验证不能通过 refresh token (类型隔离)'
);

echo "\n=== 结果: $passed 通过, $failed 失败 ===\n";
exit($failed === 0 ? 0 : 1);
