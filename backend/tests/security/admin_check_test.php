<?php
declare(strict_types=1);

/**
 * 安全回归测试: AdminController::checkAdmin() 不再信任 X-Is-Admin 头
 *
 * 复现的历史缺陷 (Bug 3):
 *   checkAdmin() 在原 369 行读取 `X-Is-Admin` 客户端请求头, 任何已登录
 *   用户只要附带 `X-Is-Admin: 1` 即可跳过 User::find()->isAdmin() 的
 *   数据库校验, 直接获得管理员权限 (严重垂直越权).
 *
 * 验证策略:
 *   A) 静态层: 源码中已不再出现可被滥用的 X-Is-Admin 头访问代码.
 *   B) 行为层: 用真实 AdminController + 桩 User/Request, 通过反射调用
 *      private checkAdmin(). 因 checkAdmin() 在拒绝时调 exit() 杀进程,
 *      每个用例用 pcntl_fork 在子进程内运行. 子进程通过 register_shutdown_function
 *      把 Response::$last 写入临时文件, 父进程回收后读取判定.
 *
 * 用法:  php tests/security/admin_check_test.php
 * 退出码: 0 = 全部通过, 1 = 存在失败断言.
 */

namespace think\response {
    if (!class_exists(__NAMESPACE__ . '\\Response', false)) {
        class Response
        {
            public static array $last = [];
            public static function create($data = null, string $type = 'json', int $code = 200): self
            {
                self::$last = ['data' => $data, 'code' => $code];
                return new self();
            }
            public function send(): void {}
        }
    }
}

namespace think\facade {
    if (!class_exists(__NAMESPACE__ . '\\Db', false)) {
        class Db { public static function name($n) { return null; } }
    }
    if (!class_exists(__NAMESPACE__ . '\\Config', false)) {
        class Config { public static function get($k, $d = null) { return $d; } }
    }
    if (!class_exists(__NAMESPACE__ . '\\Cache', false)) {
        class Cache { public static function delete($k) { return true; } }
    }
}

namespace app\common\model {
    if (!class_exists(__NAMESPACE__ . '\\User', false)) {
        class User
        {
            public static array $users = [];
            public int $id;
            public int $is_admin;
            public int $status;
            public function __construct(int $id, int $isAdmin = 0, int $status = 1)
            {
                $this->id = $id; $this->is_admin = $isAdmin; $this->status = $status;
            }
            public function isAdmin(): bool { return $this->is_admin === 1; }
            public static function find($id): ?self { return self::$users[(int) $id] ?? null; }
        }
    }
}

namespace app\common\traits {
    if (!trait_exists(__NAMESPACE__ . '\\ApiResponse', false)) {
        trait ApiResponse
        {
            public $request;
            public function __construct() { $this->request = $GLOBALS['__test_request']; }
            public function success($d = null, string $m = 'ok', int $c = 0)
            { return \think\response\Response::create(['ok' => true], 'json', 200); }
            public function fail(string $m = '', int $c = 1, $d = null, int $hs = 200)
            { return \think\response\Response::create(['err' => $m], 'json', $hs); }
            public function json(int $c, $msg, $d = null, int $hs = 200)
            { return \think\response\Response::create(['c' => $c], 'json', $hs); }
            public function userId(): int
            {
                $r = $this->request;
                $h = $r->header ?? [];
                $uid = $h['X-User-Id'] ?? '0';
                return (int) $uid;
            }
            public function pageParams(): array { return [1, 15]; }
        }
    }
}

namespace app {
    if (!class_exists(__NAMESPACE__ . '\\BaseController', false)) {
        abstract class BaseController
        {
            protected function getRequest() { return $GLOBALS['__test_request']; }
            protected function success($d = null, string $m = 'ok') { return ['ok' => true]; }
            protected function fail(string $m, int $c = 1, $d = null, int $hs = 400) { return ['err' => $m]; }
            protected function json(int $s, $payload) { return $payload; }
        }
    }
}

namespace app\admin\controller {
    require_once __DIR__ . '/../../app/admin/controller/AdminController.php';
}

namespace {

    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "需要 pcntl 扩展\n");
        exit(2);
    }

    final class FakeRequest
    {
        public int $userId;
        public array $header;
        public function __construct(int $uid, array $h) { $this->userId = $uid; $this->header = $h; }
    }

    $cases = [
        [
            'name'    => '非管理员用户即便伪造 X-Is-Admin: 1 仍应被 403 拒绝',
            'uid'     => 100,
            'headers' => ['X-User-Id' => '100', 'X-Is-Admin' => '1'],
            'users'   => [100 => ['is_admin' => 0, 'status' => 1]],
            'expect_status' => 403,
        ],
        [
            'name'    => '管理员用户 (DB is_admin=1) 通过校验',
            'uid'     => 200,
            'headers' => ['X-User-Id' => '200'],
            'users'   => [200 => ['is_admin' => 1, 'status' => 1]],
            'expect_status' => null,  // null 表示不应有响应 (通过)
        ],
        [
            'name'    => '未登录请求 (uid=0) 应被 401 拒绝, 即使伪造 X-Is-Admin',
            'uid'     => 0,
            'headers' => ['X-User-Id' => '0', 'X-Is-Admin' => '1'],
            'users'   => [],
            'expect_status' => 401,
        ],
        [
            'name'    => 'DB 中无对应用户时, 即便伪造 X-Is-Admin: 1 仍应被 403 拒绝',
            'uid'     => 999,
            'headers' => ['X-User-Id' => '999', 'X-Is-Admin' => '1'],
            'users'   => [],
            'expect_status' => 403,
        ],
    ];

    function runChild(array $case, string $resultFile): void
    {
        // 在 exit 时把 Response 状态写入文件, 供父进程读取.
        register_shutdown_function(function () use ($resultFile) {
            $state = [
                'response' => \think\response\Response::$last,
                'denied'   => \think\response\Response::$last !== [],
            ];
            @file_put_contents($resultFile, json_encode($state));
        });

        \app\common\model\User::$users = [];
        foreach ($case['users'] as $uid => $u) {
            \app\common\model\User::$users[$uid] = new \app\common\model\User(
                $uid, $u['is_admin'], $u['status']
            );
        }
        $GLOBALS['__test_request'] = new FakeRequest($case['uid'], $case['headers']);
        \think\response\Response::$last = [];

        $ctrl = new \app\admin\controller\AdminController();
        $ref = new \ReflectionMethod($ctrl, 'checkAdmin');
        $ref->setAccessible(true);
        $ref->invoke($ctrl);
    }

    $passed = 0;
    $failed = 0;
    $tmpDir = sys_get_temp_dir() . '/admin_check_test_' . getmypid();
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0700, true);
    }

    foreach ($cases as $idx => $case) {
        $resultFile = $tmpDir . '/case_' . $idx . '.json';
        @unlink($resultFile);

        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "fork failed\n");
            exit(2);
        }
        if ($pid === 0) {
            runChild($case, $resultFile);
            exit(0);
        }
        $status = 0;
        pcntl_waitpid($pid, $status);

        $stateRaw = @file_get_contents($resultFile);
        @unlink($resultFile);
        $state = $stateRaw ? json_decode($stateRaw, true) : null;

        $denied = is_array($state) && ($state['denied'] ?? false);
        $code   = is_array($state) ? ($state['response']['code'] ?? null) : null;

        $ok = false;
        $msg2 = '';
        if ($case['expect_status'] === null) {
            $ok = !$denied;
            $msg2 = $ok ? '' : ' (期望通过, 但被拒绝, code=' . var_export($code, true) . ')';
        } else {
            $ok = $denied && $code === $case['expect_status'];
            $msg2 = $ok ? '' : sprintf(
                ' (期望拒绝且 code=%d, 实际: %s, code=%s)',
                $case['expect_status'],
                $denied ? '已拒绝' : '未拒绝',
                var_export($code, true)
            );
        }

        if ($ok) {
            $passed++;
            echo "  PASS  {$case['name']}\n";
        } else {
            $failed++;
            echo "  FAIL  {$case['name']}{$msg2}\n";
        }
    }

    // 静态层验证: 不能在源码中出现 X-Is-Admin 头的访问 (仅注释不算)
    $src = file_get_contents(__DIR__ . '/../../app/admin/controller/AdminController.php');
    // 移除注释内容再做匹配
    $srcNoComments = preg_replace('#/\*.*?\*/#s', '', $src);
    $srcNoComments = preg_replace('#//[^\n]*#', '', $srcNoComments);
    if (strpos($srcNoComments, 'X-Is-Admin') === false) {
        $passed++;
        echo "  PASS  AdminController.php 中已不再访问 X-Is-Admin 头\n";
    } else {
        $failed++;
        echo "  FAIL  AdminController.php 仍存在 X-Is-Admin 头访问代码\n";
        // 打印出出现的位置
        $lines = explode("\n", $srcNoComments);
        foreach ($lines as $i => $line) {
            if (strpos($line, 'X-Is-Admin') !== false) {
                echo "       line " . ($i + 1) . ": $line\n";
            }
        }
    }

    // 清理
    @rmdir($tmpDir);

    echo "\n=== 结果: $passed 通过, $failed 失败 ===\n";
    exit($failed === 0 ? 0 : 1);

}
