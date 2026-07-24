<?php
declare(strict_types=1);

namespace app\common\hardener;

/**
 * JavaScript 代码加固器
 *
 * 实现以下加固变换（参考 javascript-obfuscator 思路）：
 *  - 变量名/函数名混淆：使用 _0x 前缀的十六进制名
 *  - 字符串数组化：提取字符串到数组，通过索引访问
 *  - 字符串数组打乱 + 偏移解密
 *  - 控制流平坦化：将 if/else 改为 switch-case 分发
 *  - 死代码注入
 *  - 自我防御：检测 debugger / cons 等并触发无限 debugger
 *  - 作用域隔离：IIFE 包装
 */
class JavaScriptHardener extends AbstractHardener
{
    /** @var string[] 提取出来的字符串数组 */
    private array $stringArray = [];

    /** @var int 字符串数组打乱后的偏移量 */
    private int $arrayOffset = 0;

    /** @var string 字符串数组变量名（混淆后） */
    private string $arrayVarName = '';

    /** @var string 取值函数名 */
    private string $getterName = '';

    /**
     * JS 保留字与全局对象（不混淆）。
     */
    protected const RESERVED = [
        'var','let','const','function','return','if','else','for','while','do','switch','case',
        'default','break','continue','new','delete','typeof','instanceof','in','of','void','this',
        'throw','try','catch','finally','class','extends','super','static','get','set','async',
        'await','yield','import','export','from','as','with','debugger','null','undefined','true',
        'false','NaN','Infinity','arguments','Object','Array','String','Number','Boolean','Function',
        'Symbol','BigInt','Math','JSON','Date','RegExp','Error','TypeError','RangeError','SyntaxError',
        'Map','Set','WeakMap','WeakSet','Promise','Proxy','Reflect','console','window','document',
        'globalThis','self','global','require','module','exports','process','Buffer','setTimeout',
        'setInterval','clearTimeout','clearInterval','setImmediate','queueMicrotask','encodeURIComponent',
        'decodeURIComponent','encodeURI','decodeURI','parseInt','parseFloat','isNaN','isFinite','btoa',
        'atob','escape','unescape','eval','alert','prompt','confirm','fetch','XMLHttpRequest','URL',
        'URLSearchParams','FormData','Headers','Request','Response','WebSocket','Event','CustomEvent',
        'EventTarget','Element','Node','Document','HTMLElement','HTMLCanvasElement','CanvasRenderingContext2D',
        'localStorage','sessionStorage','indexedDB','navigator','location','history','screen','performance',
        'crypto','atob','btoa','TextEncoder','TextDecoder','Worker','SharedWorker','ServiceWorker',
    ];

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['js', 'mjs', 'cjs', 'jsx'];
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageName(): string
    {
        return 'javascript';
    }

    /**
     * 重写 harden 前的初始化（清空字符串数组）。
     */
    public function harden(string $code, array $options = []): string
    {
        $this->stringArray  = [];
        $this->arrayOffset  = mt_rand(100, 999);
        $this->arrayVarName = $this->mapIdentifier('arr', '_0x', 'abcdefghijklmnopqrstuvwxyz');
        $this->getterName   = $this->mapIdentifier('get', '_0x', 'abcdefghijklmnopqrstuvwxyz');
        return parent::harden($code, $options);
    }

    /**
     * {@inheritdoc}
     * 变量名/函数名混淆：使用 _0x 前缀的十六进制名
     */
    protected function obfuscateIdentifiers(string $code): string
    {
        // 1. 函数声明名：function foo(
        $code = preg_replace_callback(
            '/\bfunction\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*\(/',
            function ($m) {
                if (in_array($m[1], self::RESERVED, true)) {
                    return $m[0];
                }
                $new = $this->mapIdentifier('fn::' . $m[1], '_0x', 'abcdefghijklmnopqrstuvwxyz');
                return 'function ' . $new . '(';
            },
            $code
        );

        // 2. var/let/const 变量名：var foo =
        $code = preg_replace_callback(
            '/\b(var|let|const)\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*=/',
            function ($m) {
                if (in_array($m[2], self::RESERVED, true)) {
                    return $m[0];
                }
                $new = $this->mapIdentifier('v::' . $m[2], '_0x', 'abcdefghijklmnopqrstuvwxyz');
                return $m[1] . ' ' . $new . ' =';
            },
            $code
        );

        // 3. 函数参数名：function(a, b, c)
        $code = preg_replace_callback(
            '/\bfunction\s*[a-zA-Z0-9_$]*\s*\(([^)]*)\)/',
            function ($m) {
                $params = array_map('trim', explode(',', $m[1]));
                $out = [];
                foreach ($params as $p) {
                    if ($p === '' || in_array($p, self::RESERVED, true)) {
                        $out[] = $p;
                        continue;
                    }
                    $out[] = $this->mapIdentifier('p::' . $p, '_0x', 'abcdefghijklmnopqrstuvwxyz');
                }
                return 'function(' . implode(',', $out) . ')';
            },
            $code
        );

        // 4. 替换已注册变量与函数的引用
        foreach ($this->identifierMap as $key => $obf) {
            if (str_starts_with($key, 'fn::') || str_starts_with($key, 'v::') || str_starts_with($key, 'p::')) {
                $orig = substr($key, strpos($key, '::') + 2);
                if (in_array($orig, self::RESERVED, true) || strlen($orig) < 2) {
                    continue;
                }
                // 仅在词边界处替换，避免破坏属性
                $code = preg_replace('/(?<![.\w$])' . preg_quote($orig, '/') . '\b/', $obf, $code);
            }
        }

        return $code;
    }

    /**
     * {@inheritdoc}
     * 字符串数组化：提取字符串到数组，通过索引访问
     */
    protected function encryptStrings(string $code): string
    {
        // 收集所有字符串字面量
        $code = preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'/s',
            function ($m) {
                $raw = isset($m[2]) ? $this->unescapeJsString($m[2]) : $this->unescapeJsString($m[1]);
                if ($raw === '') {
                    return $m[0];
                }
                $idx = array_search($raw, $this->stringArray, true);
                if ($idx === false) {
                    $idx = count($this->stringArray);
                    $this->stringArray[] = $raw;
                }
                // 偏移后通过 getter 取值
                return $this->getterName . '(' . ($idx + $this->arrayOffset) . ')';
            },
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 控制流平坦化：将 if/else 改为 switch-case 分发
     */
    protected function flattenControlFlow(string $code): string
    {
        // 简单的 if/else 块平坦化：if (cond) { A } else { B } => switch(state){case 0:A;break;case 1:B;break;}
        $self = $this;
        $code = preg_replace_callback(
            '/\bif\s*\(([^{}]+)\)\s*\{([^{}]*)\}\s*else\s*\{([^{}]*)\}/s',
            function ($m) use ($self) {
                $cond = $m[1];
                $a = $m[2];
                $b = $m[3];
                $stateVar = $self->mapIdentifier('flow', '_0x', 'abcdefghijklmnopqrstuvwxyz');
                $cases = 'case ' . $self->randomHex(4) . ':' . $a . ';break;'
                    . 'case ' . $self->randomHex(4) . ':' . $b . ';break;';
                return '(function(){var ' . $stateVar . '=' . $cond . '?0:1;'
                    . 'switch(' . $stateVar . '){' . $cases . '}})()';
            },
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 死代码注入
     */
    protected function injectJunkCode(string $code): string
    {
        $junkBlocks = [];
        for ($i = 0; $i < 3; $i++) {
            $v = $this->mapIdentifier('jd' . $i, '_0x', 'abcdefghijklmnopqrstuvwxyz');
            $junkBlocks[] = 'var ' . $v . '=' . mt_rand() . ';';
        }
        $junk = implode('', $junkBlocks);
        // 注入到第一个 { 之后（通常是顶层 IIFE 或函数体）
        $pos = strpos($code, '{');
        if ($pos !== false) {
            $code = substr($code, 0, $pos + 1) . $junk . substr($code, $pos + 1);
        } else {
            $code = $junk . $code;
        }
        return $code;
    }

    /**
     * {@inheritdoc}
     * 自我防御：检测 debugger / cons 并触发无限 debugger
     */
    protected function insertAntiDebug(string $code): string
    {
        $guard = ''
            . '(function(){'
            . 'setInterval(function(){'
            . 'var s=new Date();'
            . 'debugger;'
            . 'if((new Date())-s>100){'
            . 'while(true){debugger;}'
            . '}'
            . '},4000);'
            . 'var _c=function(){return console;};'
            . 'try{'
            . 'Object.defineProperty(_c(),"__proto__",{get:function(){while(true){}}});'
            . '}catch(e){}'
            . '})();';

        return $guard . $code;
    }

    /**
     * {@inheritdoc}
     * 后处理：注入字符串数组运行时、IIFE 包装
     */
    protected function doHarden(string $code, array $opts): string
    {
        // 1. 构建字符串数组与 getter
        $runtime = $this->buildStringArrayRuntime();

        // 2. IIFE 包装
        $iife = '(function(){' . "\n" . $runtime . "\n" . $code . "\n" . '})();';

        return $iife;
    }

    /* ---------------------------------------------------------------------
     * 私有辅助
     * ------------------------------------------------------------------- */

    /**
     * 构建字符串数组运行时（含打乱与偏移解密）。
     *
     * 关键：打乱后必须维护 原始索引 -> 新位置 的置换表，
     * getter 先还原偏移得到原始索引，再通过置换表查到实际存储位置，最后解密。
     */
    private function buildStringArrayRuntime(): string
    {
        $count = count($this->stringArray);

        // 无字符串时仍提供 getter 占位，避免引用未定义函数
        if ($count === 0) {
            return 'var ' . $this->arrayVarName . '=[];'
                . 'function ' . $this->getterName . '(i){return "";}';
        }

        // 生成置换：原索引 i 的新位置 perm[i]
        $perm = range(0, $count - 1);
        shuffle($perm);

        // 按置换后的顺序构造加密数组：newArr[newPos] = encrypt(stringArray[origIdx])
        $shuffled = [];
        foreach ($perm as $origIdx => $newPos) {
            $shuffled[$newPos] = $this->aesEncrypt($this->stringArray[$origIdx]);
        }
        ksort($shuffled);

        // 反向置换表：newPos -> origIdx（getter 使用）
        $reverse = [];
        foreach ($perm as $origIdx => $newPos) {
            $reverse[$newPos] = $origIdx;
        }
        ksort($reverse);
        $reverseLiteral = '[' . implode(',', $reverse) . ']';

        $arrLiteral = '[' . implode(',', array_map(fn($x) => "'" . $x . "'", $shuffled)) . ']';
        $permVar = $this->mapIdentifier('perm', '_0x', 'abcdefghijklmnopqrstuvwxyz');

        return 'var ' . $this->arrayVarName . '=' . $arrLiteral . ';'
            . 'var ' . $permVar . '=' . $reverseLiteral . ';'
            . 'function ' . $this->getterName . '(i){'
            . 'var o=(i-' . $this->arrayOffset . ');'
            . 'o=o%' . $count . ';'
            . 'if(o<0){o+=' . $count . ';}'
            . 'var n=' . $permVar . '[o];'
            . 'var mk="' . $this->embedMaskedKey() . '";'
            . 'var xm="' . $this->embedXorMask() . '";'
            . 'var k="";'
            . 'for(var j=0;j<mk.length;j+=2){'
            . 'k+=String.fromCharCode(parseInt(mk.substr(j,2),16)^xm.charCodeAt((j/2)%xm.length));'
            . '}'
            . 'var kh="";'
            . 'for(var j=0;j<k.length;j++){kh+=("00"+k.charCodeAt(j).toString(16)).slice(-2);}'
            . 'var raw=' . $this->arrayVarName . '[n];'
            . 'return ' . $this->buildJsDecryptCall() . ';'
            . '}';
    }

    private function buildJsDecryptCall(): string
    {
        // 使用 Web Crypto 风格的同步解密（实际中需要异步，这里用 node 风格 createDecipheriv 兜底）
        return '(function(){'
            . 'try{'
            . 'if(typeof require!=="undefined"){'
            . 'var crypto=require("crypto");'
            . 'var p=Buffer.from(raw,"base64");'
            . 'var iv=p.slice(0,16);'
            . 'var c=p.slice(16);'
            . 'var d=crypto.createDecipheriv("aes-256-cbc",Buffer.from(kh,"hex"),iv);'
            . 'return Buffer.concat([d.update(c),d.final()]).toString("utf8");'
            . '}else{'
            . 'return atob(raw);'
            . '}'
            . '}catch(e){return raw;}'
            . '})()';
    }

    private function embedMaskedKey(): string
    {
        $rawKey = $this->encryptionKey . '::' . $this->seedPrefix;
        $xorMask = $this->randomHex(16);
        $masked = '';
        for ($i = 0, $n = strlen($rawKey); $i < $n; $i++) {
            $masked .= sprintf('%02x', ord($rawKey[$i]) ^ ord($xorMask[$i % strlen($xorMask)]));
        }
        // 保存 xorMask 供 embedXorMask() 使用
        $GLOBALS['__js_xor_mask'] = $xorMask;
        return $masked;
    }

    private function embedXorMask(): string
    {
        return $GLOBALS['__js_xor_mask'] ?? $this->randomHex(16);
    }

    /**
     * 解析 JS 字符串转义。
     */
    private function unescapeJsString(string $s): string
    {
        $s = str_replace(
            ['\\n', '\\t', '\\r', '\\\\', '\\"', "\\'", '\\0', '\\b', '\\f', '\\v'],
            ["\n", "\t", "\r", '\\', '"', "'", "\0", "\x08", "\x0c", "\x0b"],
            $s
        );
        $s = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn($m) => mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UTF-16BE'), $s);
        $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', fn($m) => chr(hexdec($m[1])), $s);
        return $s;
    }
}

/**
 * 关联数组打乱辅助（不保留键名顺序，但保留键值关联）。
 */
function shuffleAssoc(array &$arr): bool
{
    $keys = array_keys($arr);
    shuffle($keys);
    $new = [];
    foreach ($keys as $k) {
        $new[$k] = $arr[$k];
    }
    $arr = $new;
    return true;
}
