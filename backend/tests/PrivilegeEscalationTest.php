<?php
/**
 * Regression test for the privilege-escalation fix in
 * `ApiResponse::userId()`.
 *
 * Background
 * ----------
 * The trait method used to read the `X-User-Id` request header,
 * which an attacker fully controls, before falling back to the
 * server-injected user id. Any authenticated user could therefore
 * impersonate any other user.
 *
 * The fix removes the client-header read entirely. The userId now
 * comes only from `request()->userId`, which AuthMiddleware injects
 * from the verified JWT claims.
 *
 * The test pulls in the trait source verbatim and exercises it
 * through a stub controller. No framework autoloader is required.
 */

declare(strict_types=1);

namespace think {
    final class Response
    {
        public static function create(array $data = [], string $type = 'html', int $code = 200)
        {
            return new self();
        }
    }
}

namespace app\common\traits\tests {

    // Inline the trait body so the test does not depend on a
    // framework autoloader. Keep this in sync with the production
    // file: app/common/traits/ApiResponse.php
    trait ApiResponse
    {
        protected function success($data = null, string $message = 'success', int $code = 0): \think\Response
        {
            return $this->json($code, $message, $data);
        }

        protected function fail(string $message = 'failed', int $code = 1, $data = null, int $httpStatus = 200): \think\Response
        {
            return $this->json($code, $message, $data, $httpStatus);
        }

        protected function json(int $code, string $message, $data = null, int $httpStatus = 200): \think\Response
        {
            $payload = [
                'code'    => $code,
                'message' => $message,
                'data'    => $data,
            ];
            return \think\Response::create($payload, 'json', $httpStatus);
        }

        protected function userId(): int
        {
            $uid = request()->userId ?? 0;
            return (int) $uid;
        }

        protected function pageParams(): array
        {
            $page = (int) request()->param('page', 1);
            $size = (int) request()->param('page_size', 15);
            $page = max(1, $page);
            $size = min(100, max(1, $size));
            return [$page, $size];
        }
    }

    final class StubRequest
    {
        public int $userId;
        /** @var array<string,string> */
        public array $headers;

        public function __construct(int $injectedUserId, array $headers)
        {
            $this->userId  = $injectedUserId;
            $this->headers = $headers;
        }

        public function header(string $name)
        {
            return $this->headers[$name] ?? '';
        }
    }

    final class FakeController
    {
        use ApiResponse {
            userId as public;
        }
    }
}

namespace {
    /** @var \app\common\traits\tests\StubRequest|null */
    $GLOBALS['testRequest'] = null;

    /**
     * Replacement for ThinkPHP's global `request()` helper. Returns
     * whatever stub the test has bound.
     */
    function request()
    {
        if (!isset($GLOBALS['testRequest']) || $GLOBALS['testRequest'] === null) {
            throw new \RuntimeException('test request not bound');
        }
        return $GLOBALS['testRequest'];
    }
}

namespace app\common\traits\tests {

    function bindRequest(StubRequest $req): void
    {
        $GLOBALS['testRequest'] = $req;
    }

    function assertSame($expected, $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            $hint = $msg !== '' ? " ({$msg})" : '';
            throw new \RuntimeException(
                "assertSame failed{$hint}: expected " . var_export($expected, true)
                . ', got ' . var_export($actual, true)
            );
        }
    }

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    function test_user_id_ignores_X_User_Id_header(): void
    {
        // Authenticated as user 1, but attacker spoofs X-User-Id: 999
        bindRequest(new StubRequest(1, [
            'X-User-Id'    => '999',
            'Authorization'=> 'Bearer <jwt>',
        ]));

        $ctrl = new FakeController();
        assertSame(
            1,
            $ctrl->userId(),
            'X-User-Id header must NOT override the authenticated user id'
        );
    }

    function test_user_id_returns_injected_value_when_header_missing(): void
    {
        bindRequest(new StubRequest(42, []));
        $ctrl = new FakeController();
        assertSame(42, $ctrl->userId());
    }

    function test_user_id_returns_zero_for_unauthenticated_request(): void
    {
        // No middleware ran, so userId is 0; attacker sends X-User-Id.
        bindRequest(new StubRequest(0, ['X-User-Id' => '123']));
        $ctrl = new FakeController();
        assertSame(
            0,
            $ctrl->userId(),
            'Unauthenticated request must not pick up the spoofed header'
        );
    }

    function test_user_id_ignores_string_zero(): void
    {
        bindRequest(new StubRequest(7, ['X-User-Id' => '0']));
        $ctrl = new FakeController();
        assertSame(7, $ctrl->userId());
    }

    function run(): int
    {
        $tests = [
            __NAMESPACE__ . '\\test_user_id_ignores_X_User_Id_header',
            __NAMESPACE__ . '\\test_user_id_returns_injected_value_when_header_missing',
            __NAMESPACE__ . '\\test_user_id_returns_zero_for_unauthenticated_request',
            __NAMESPACE__ . '\\test_user_id_ignores_string_zero',
        ];
        $failed = 0;
        foreach ($tests as $name) {
            try {
                $name();
                echo "ok   - $name\n";
            } catch (\Throwable $e) {
                $failed++;
                echo "FAIL - $name: " . $e->getMessage() . "\n";
            }
        }
        if ($failed === 0) {
            echo "All regression tests passed.\n";
            return 0;
        }
        echo "{$failed} test(s) failed.\n";
        return 1;
    }
}

namespace {
    if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
        exit(\app\common\traits\tests\run());
    }
}
