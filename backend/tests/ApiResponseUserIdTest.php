<?php
/**
 * 回归测试：ApiResponse::userId() 不应信任 X-User-Id 请求头。
 *
 * 背景：之前的实现从 HTTP 头 X-User-Id 读取 uid，覆盖了 AuthMiddleware
 * 从 JWT 注入的 userId，导致持有任意有效 token 的攻击者可凭 X-User-Id
 * 冒用任意其他用户身份。
 *
 * 运行：php backend/tests/ApiResponseUserIdTest.php
 */

declare(strict_types=1);

// 在引入 trait 之前先定义 request() 桩函数，便于覆盖。
namespace {
    require_once __DIR__ . '/../app/common/traits/ApiResponse.php';

    /**
     * 测试用的请求对象：同时支持 $userId 属性（中间件注入）与
     * header('X-User-Id') 模拟（来自客户端请求头）。
     */
    final class FakeRequest
    {
        public int $userId = 0;
        public ?string $xUserIdHeader = null;

        public function header(string $name): string
        {
            if (strcasecmp($name, 'X-User-Id') === 0) {
                return (string)($this->xUserIdHeader ?? '');
            }
            return '';
        }
    }

    /** 当前测试请求（桩 request() 全局函数的返回值）。 */
    $GLOBALS['__fake_request'] = new FakeRequest();

    if (!function_exists('request')) {
        function request(): FakeRequest
        {
            return $GLOBALS['__fake_request'];
        }
    }

    /** 使用 trait 的测试类，把 userId() 暴露为 public 便于断言。 */
    final class AuthUserIdProbe
    {
        use \app\common\traits\ApiResponse {
            userId as public;
        }
    }

    function assertSame($expected, $actual, string $label): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, sprintf(
                "FAIL: %s — expected %s, got %s\n",
                $label,
                var_export($expected, true),
                var_export($actual, true)
            ));
            exit(1);
        }
        echo "PASS: {$label}\n";
    }

    $probe = new AuthUserIdProbe();
    $req   = $GLOBALS['__fake_request'];

    // 1. 仅设置 X-User-Id 请求头，userId 属性为 0。
    //    修复后必须返回 0（不能被 header 覆盖为冒用身份）。
    $req->userId        = 0;
    $req->xUserIdHeader = '999';
    assertSame(0, $probe->userId(), 'X-User-Id header must not override authenticated identity');

    // 2. 中间件正确注入 userId=42，header 也被设置成 1（攻击者尝试覆盖）。
    //    修复后必须返回 42（信任 JWT，不信任 header）。
    $req->userId        = 42;
    $req->xUserIdHeader = '1';
    assertSame(42, $probe->userId(), 'JWT-injected userId must take precedence over X-User-Id header');

    // 3. 中间件注入 userId=7，header 不存在。
    $req->userId        = 7;
    $req->xUserIdHeader = null;
    assertSame(7, $probe->userId(), 'JWT-injected userId returned when no header present');

    echo "All ApiResponse::userId() regression tests passed.\n";
}
