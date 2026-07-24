<?php
declare(strict_types=1);

namespace app\common\hardener;

/**
 * 加固器抽象基类
 *
 * 实现各语言加固器通用的算法骨架与公共工具方法：
 *  - 标识符混淆（生成随机变量名映射表）
 *  - 字符串加密（AES-256-CBC，运行时解密调用）
 *  - 注释清除（保留 license 头）
 *  - 空白字符压缩
 *  - 控制流平坦化（插入垃圾代码分支）
 *  - 通用 harden() 模板方法
 *
 * 子类通过覆盖 doHarden() / 各受保护方法实现语言特定的变换逻辑。
 */
abstract class AbstractHardener implements HardenerInterface
{
    /** @var array<string,string> 标识符 -> 混淆名 映射表（同一次加固内保持一致） */
    protected array $identifierMap = [];

    /** @var array<string,int> 标识符计数器，用于生成唯一名称 */
    protected array $nameCounters = [];

    /** @var string 当前加固任务的随机种子前缀，确保多次运行结果不同 */
    protected string $seedPrefix = '';

    /** @var string|null AES 加密密钥 */
    protected ?string $encryptionKey = null;

    /** @var array 默认加固选项 */
    protected array $defaultOptions = [
        'obfuscate_identifiers'  => true,
        'encrypt_strings'        => true,
        'strip_comments'         => true,
        'compress_whitespace'    => true,
        'flatten_control_flow'   => true,
        'insert_junk_code'       => true,
        'anti_debug'             => true,
        'preserve_license'       => true,
        'key'                    => null,
    ];

    /**
     * 模板方法：编排各加固步骤。
     */
    public function harden(string $code, array $options = []): string
    {
        if ($code === '') {
            return $code;
        }

        $opts = array_merge($this->defaultOptions, $options);
        $this->seedPrefix    = $this->generateSeedPrefix();
        $this->identifierMap = [];
        $this->nameCounters  = [];
        $this->encryptionKey = $opts['key'] ?: ($GLOBALS['__hardener_default_key'] ?? 'trae-hardener-default-key-2026');

        // 1. 预处理：标准化换行、移除 BOM
        $code = $this->preprocess($code);

        // 2. 提取并保留 license 头
        $license = '';
        if ($opts['preserve_license']) {
            $license = $this->extractLicenseHeader($code);
        }

        // 3. 清除注释
        if ($opts['strip_comments']) {
            $code = $this->stripComments($code);
        }

        // 4. 标识符混淆
        if ($opts['obfuscate_identifiers']) {
            $code = $this->obfuscateIdentifiers($code);
        }

        // 5. 字符串加密
        if ($opts['encrypt_strings']) {
            $code = $this->encryptStrings($code);
        }

        // 6. 控制流平坦化
        if ($opts['flatten_control_flow']) {
            $code = $this->flattenControlFlow($code);
        }

        // 7. 注入垃圾代码
        if ($opts['insert_junk_code']) {
            $code = $this->injectJunkCode($code);
        }

        // 8. 注入反调试
        if ($opts['anti_debug']) {
            $code = $this->insertAntiDebug($code);
        }

        // 9. 压缩空白
        if ($opts['compress_whitespace']) {
            $code = $this->compressWhitespace($code);
        }

        // 10. 语言特定后处理（如插入运行时解密函数、IIFE 包装等）
        $code = $this->doHarden($code, $opts);

        // 11. 重新拼接 license 头
        if ($license !== '') {
            $code = $license . "\n" . $code;
        }

        return $code;
    }

    /* ---------------------------------------------------------------------
     * 子类必须实现的钩子
     * ------------------------------------------------------------------- */

    /**
     * 语言特定加固逻辑。在通用步骤之后执行，用于插入运行时支持代码
     * （如解密函数定义、IIFE 包装、宏定义等）。
     */
    abstract protected function doHarden(string $code, array $opts): string;

    /* ---------------------------------------------------------------------
     * 公共工具方法（子类可覆盖以适配语言）
     * ------------------------------------------------------------------- */

    /**
     * 预处理：去除 BOM、统一换行符。
     */
    protected function preprocess(string $code): string
    {
        if (str_starts_with($code, "\xEF\xBB\xBF")) {
            $code = substr($code, 3);
        }
        $code = str_replace(["\r\n", "\r"], "\n", $code);
        return $code;
    }

    /**
     * 生成 4-8 位的随机种子前缀。
     */
    protected function generateSeedPrefix(): string
    {
        $bytes = random_bytes(4);
        return bin2hex($bytes);
    }

    /**
     * 提取文件头部的 license / 版权注释块。
     * 匹配开头的连续注释块（支持 // 与块注释 两种风格）。
     */
    protected function extractLicenseHeader(string $code): string
    {
        $licenseKeywords = ['license', 'copyright', 'author', 'powered', '@license', '@author', '版权', '保留所有权利'];
        $lines = explode("\n", $code);
        $header = [];
        $inBlockComment = false;
        $headerEnded = false;

        foreach ($lines as $idx => $line) {
            if ($headerEnded) {
                break;
            }
            $trimmed = trim($line);

            // 空行允许出现在头部的连续注释之间
            if ($trimmed === '') {
                if ($header !== []) {
                    $header[] = $line;
                }
                continue;
            }

            // 块注释内部
            if ($inBlockComment) {
                $header[] = $line;
                if (str_contains($line, '*/')) {
                    $inBlockComment = false;
                }
                continue;
            }

            // 进入块注释
            if (str_starts_with($trimmed, '/*')) {
                $header[] = $line;
                if (!str_contains($trimmed, '*/') || substr_count($trimmed, '/*') !== substr_count($trimmed, '*/')) {
                    $inBlockComment = true;
                }
                continue;
            }

            // 行注释
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                $header[] = $line;
                continue;
            }

            // 非注释行 => 头部结束
            $headerEnded = true;
        }

        // 检查头部是否包含 license 关键字
        $joined = strtolower(implode("\n", $header));
        $isLicense = false;
        foreach ($licenseKeywords as $kw) {
            if (str_contains($joined, strtolower($kw))) {
                $isLicense = true;
                break;
            }
        }

        return $isLicense ? implode("\n", $header) : '';
    }

    /**
     * 清除注释（保留 license 头已由 harden() 单独处理）。
     * 默认实现：移除块注释 与 // 行注释。
     */
    protected function stripComments(string $code): string
    {
        // 移除块注释 /* ... */
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        // 移除行注释 //
        $code = preg_replace('#^\s*//.*$#m', '', $code);
        // 移除行尾注释 // ... （保护 http:// https://）
        $code = preg_replace('#(?<![:"\w])//.*$#m', '', $code);
        return $code;
    }

    /**
     * 标识符混淆。子类应覆盖以实现语言特定的标识符提取与替换。
     * 基类仅提供映射表管理工具。
     */
    protected function obfuscateIdentifiers(string $code): string
    {
        return $code;
    }

    /**
     * 字符串加密。子类应覆盖以实现语言特定的字符串字面量替换。
     */
    protected function encryptStrings(string $code): string
    {
        return $code;
    }

    /**
     * 控制流平坦化。子类应覆盖。
     */
    protected function flattenControlFlow(string $code): string
    {
        return $code;
    }

    /**
     * 注入垃圾代码。子类应覆盖。
     */
    protected function injectJunkCode(string $code): string
    {
        return $code;
    }

    /**
     * 插入反调试代码。子类应覆盖。
     */
    protected function insertAntiDebug(string $code): string
    {
        return $code;
    }

    /**
     * 压缩空白：合并多余空行与连续空格。
     */
    protected function compressWhitespace(string $code): string
    {
        // 移除行首行尾空白
        $code = preg_replace('/^[ \t]+|[ \t]+$/m', '', $code);
        // 合并连续空行（最多保留一个）
        $code = preg_replace("/\n{3,}/", "\n\n", $code);
        // 压缩行内连续空格为单个（保护字符串需在子类加密前处理）
        $code = preg_replace('/[ \t]{2,}/', ' ', $code);
        return trim($code);
    }

    /* ---------------------------------------------------------------------
     * 标识符映射表工具
     * ------------------------------------------------------------------- */

    /**
     * 为标识符生成（或复用）混淆名。
     *
     * @param string $identifier 原始标识符
     * @param string $prefix     混淆名前缀（语言相关，如 $ / _0x / 空）
     * @param string $alphabet   可用字符集
     */
    protected function mapIdentifier(string $identifier, string $prefix = '', string $alphabet = 'abcdefghijklmnopqrstuvwxyz'): string
    {
        if (isset($this->identifierMap[$identifier])) {
            return $this->identifierMap[$identifier];
        }

        $name = $this->generateObfuscatedName(count($this->identifierMap), $prefix, $alphabet);
        $this->identifierMap[$identifier] = $name;
        return $name;
    }

    /**
     * 基于序号生成混淆名，确保唯一且可重现。
     */
    protected function generateObfuscatedName(int $index, string $prefix, string $alphabet): string
    {
        $len = strlen($alphabet);
        $firstChars = $alphabet[0] . ($len > 1 ? substr($alphabet, 0, $len - 10 > 0 ? $len - 10 : max(1, $len - 10)) : $alphabet);
        // 首字符仅取字母，后续可混合数字
        $fullSet = $alphabet . '0123456789';

        $n = $index;
        $name = '';
        // 首字符
        $firstPool = preg_replace('/[0-9]/', '', $alphabet) ?: $alphabet;
        $base = strlen($firstPool);
        if ($base < 1) {
            $firstPool = 'abcdefgh';
            $base = strlen($firstPool);
        }
        $name = $firstPool[$n % $base];
        $n = intdiv($n, $base);

        while ($n > 0) {
            $name .= $fullSet[$n % strlen($fullSet)];
            $n = intdiv($n, strlen($fullSet));
        }

        // 追加种子后缀避免冲突并增加随机性
        return $prefix . $name . '_' . substr($this->seedPrefix, 0, 3);
    }

    /**
     * 返回当前映射表（开发者可见的混淆映射头注释使用）。
     */
    public function getIdentifierMap(): array
    {
        return $this->identifierMap;
    }

    /* ---------------------------------------------------------------------
     * AES-256-CBC 字符串加密工具
     * ------------------------------------------------------------------- */

    /**
     * 使用 AES-256-CBC 加密字符串，返回 base64 编码（IV 拼接密文）。
     */
    protected function aesEncrypt(string $plain): string
    {
        $key = $this->deriveKey();
        $iv  = random_bytes(16);
        // SHA-256 派生 32 字节密钥
        $rawKey = hash('sha256', $key, true);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $rawKey, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return base64_encode($plain); // fallback：仅 base64
        }
        return base64_encode($iv . $cipher);
    }

    /**
     * 派生加密密钥（基于全局密钥 + 种子前缀，保证每次加固密文不同）。
     */
    protected function deriveKey(): string
    {
        return $this->encryptionKey . '::' . $this->seedPrefix;
    }

    /**
     * 生成一段随机十六进制字符串，用于垃圾代码与名字混淆。
     */
    protected function randomHex(int $length = 8): string
    {
        return bin2hex(random_bytes((int)ceil($length / 2)));
    }

    /**
     * 生成一段垃圾代码表达式（语言无关占位，子类可覆盖）。
     */
    protected function generateJunkExpression(): string
    {
        return '/* junk:' . $this->randomHex(6) . ' */';
    }
}
