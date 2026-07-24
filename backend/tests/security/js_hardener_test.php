<?php
declare(strict_types=1);

/**
 * 回归测试: JavaScriptHardener 解密正确性
 *
 * 复现的历史缺陷 (Bug 2):
 *   JavaScriptHardener::embedMaskedKey() 之前直接将 encryptionKey + '::'
 *   + seedPrefix 的原始字符串嵌入并 XOR 掩码, 而 PHP aesEncrypt() 端实际
 *   用的是 SHA-256(encryptionKey + '::' + seedPrefix) 派生密钥, 导致
 *   Node.js 端拿到的是错误的 key, 触发 createDecipheriv 的 final() 抛错.
 *   修复: 嵌入前先 hash('sha256', ..., true) 拿到 32 字节, 与加密端一致.
 *
 * 验证: harden() -> Node.js 执行, 字符串字面量在运行时被正确还原.
 *
 * 用法:  php tests/security/js_hardener_test.php
 * 依赖:  Node.js (v14+), 且 node 在 PATH 中
 */

namespace app\common\traits {
    // Hardener 父类无依赖, 但 AbstractHardener 继承 HardenerInterface
}

namespace app\common\hardener {
    // 桩掉 HardenerInterface, 因为它可能引用不存在的类
    if (!interface_exists(__NAMESPACE__ . '\\HardenerInterface', false)) {
        interface HardenerInterface
        {
            public function getSupportedExtensions(): array;
            public function getLanguageName(): string;
            public function harden(string $code, array $options = []): string;
        }
    }
}

namespace {
    require_once __DIR__ . '/../../app/common/hardener/AbstractHardener.php';
    require_once __DIR__ . '/../../app/common/hardener/JavaScriptHardener.php';
    use app\common\hardener\JavaScriptHardener;

    $passed = 0;
    $failed = 0;
    function check(bool $cond, string $msg): void
    {
        global $passed, $failed;
        if ($cond) { $passed++; echo "  PASS  $msg\n"; }
        else       { $failed++; echo "  FAIL  $msg\n"; }
    }

    // 准备测试输入: 包含可被加密的字符串字面量
    $src = <<<'JS'
        var greeting = "Hello, World!";
        var secret = "the-quick-brown-fox-2026";
        console.log(greeting);
        console.log(secret);
        JS;

    // 固定 encryptionKey 以便可重现
    $GLOBALS['__hardener_default_key'] = 'test-key-for-regression-2026';
    $hardener = new JavaScriptHardener();
    $hardened = $hardener->harden($src, [
        'key'                 => 'test-key-for-regression-2026',
        'obfuscate_identifiers'=> false,  // 关闭标识符混淆便于断言
        'strip_comments'      => false,
        'compress_whitespace' => false,
        'flatten_control_flow'=> false,
        'insert_junk_code'    => false,
        'anti_debug'          => false,
        'preserve_license'    => false,
    ]);

    // 1. 输出包含 Node.js crypto.createDecipheriv 调用
    check(
        strpos($hardened, 'crypto.createDecipheriv') !== false,
        'harden() 输出包含 Node.js AES-256-CBC 解密调用'
    );
    check(
        strpos($hardened, 'aes-256-cbc') !== false,
        'harden() 输出声明 AES-256-CBC 算法'
    );

    // 2. 关键属性: 输出不应回退到空串逻辑 (try-catch return "")
    // 修复前的实现: 在 try-catch 内直接 return Buffer.concat(...).toString("utf8"),
    // 失败时返回空串. 我们检查: 修复后只 return Node 路径, 不在 else 块返回空.
    // (注: 修复后保留了 try-catch, 但 catch 也返回空, 这是预期的失败安全.)
    // 真正能验证的是: 运行 Node 端能正确解密.

    // 3. 写入临时文件并通过 Node.js 执行, 验证字符串还原
    $tmpJs = tempnam(sys_get_temp_dir(), 'hardened_') . '.js';
    file_put_contents($tmpJs, $hardened);
    $tmpLog = $tmpJs . '.log';

    // 捕获 stdout
    $cmd = "node " . escapeshellarg($tmpJs) . " > " . escapeshellarg($tmpLog) . " 2>&1";
    exec($cmd, $out, $rc);

    $log = file_exists($tmpLog) ? file_get_contents($tmpLog) : '';
    @unlink($tmpJs);
    @unlink($tmpLog);

    check(
        $rc === 0,
        "Node.js 执行加固后的 JS 成功 (rc=$rc, log=" . trim(substr($log, 0, 200)) . ")"
    );

    check(
        strpos($log, 'Hello, World!') !== false,
        'Node.js 执行后, 字符串 "Hello, World!" 被正确解密'
    );
    check(
        strpos($log, 'the-quick-brown-fox-2026') !== false,
        'Node.js 执行后, 字符串 "the-quick-brown-fox-2026" 被正确解密'
    );

    // 4. 关键回归点: 修复前输出末尾会包含 catch (e) { return ""; } 导致
    // 第一次解密失败后所有字符串都是空串. 修复后所有字符串都能被还原.
    // 上面 3 个用例已经隐含验证了这一点.

    // 5. 检查 embedMaskedKey 的关键属性: 输出 hex 长度应等于 64 (32 bytes * 2 chars/byte)
    // 找到 khx="..." 的赋值, 提取 hex 字符串长度
    if (preg_match('/var khx="([0-9a-f]+)"/', $hardened, $m)) {
        $khx = $m[1];
        check(
            strlen($khx) === 64,
            "嵌入的 masked key 长度为 64 hex chars (32 bytes), 实际: " . strlen($khx)
        );
    } else {
        check(false, "未找到嵌入的 masked key (khx)");
    }

    // 6. 浏览器路径不应有 SHA-256 同步实现 (Web Crypto 异步).
    // 修复后 buildJsDecryptCall 简化: 浏览器侧返回空, 避免抛错.
    // 我们只需确保 buildJsDecryptCall 中没有使用 SubtleCrypto 同步调用.
    check(
        strpos($hardened, 'crypto.subtle') === false,
        '未使用 crypto.subtle 异步 API (避免浏览器端同步调用失败)'
    );

    echo "\n=== 结果: $passed 通过, $failed 失败 ===\n";
    exit($failed === 0 ? 0 : 1);
}
