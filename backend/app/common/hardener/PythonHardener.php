<?php
declare(strict_types=1);

namespace app\common\hardener;

/**
 * Python 代码加固器
 *
 * 实现以下加固变换：
 *  - 变量名/函数名混淆
 *  - 字符串加密：替换为 __import__('base64').b64decode().decode() 调用
 *  - 注释清除
 *  - 缩进压缩（保留 Python 必需的缩进语义）
 *  - 插入反调试检测 sys.settrace / sys.gettrace
 */
class PythonHardener extends AbstractHardener
{
    /**
     * Python 关键字与内置（不混淆）。
     */
    protected const RESERVED = [
        'False','None','True','and','as','assert','async','await','break','class','continue',
        'def','del','elif','else','except','finally','for','from','global','if','import','in',
        'is','lambda','nonlocal','not','or','pass','raise','return','try','while','with','yield',
        'match','case','self','cls','__init__','__del__','__new__','__str__','__repr__','__len__',
        '__getitem__','__setitem__','__delitem__','__iter__','__next__','__enter__','__exit__',
        '__call__','__getattr__','__setattr__','__delattr__','__eq__','__ne__','__lt__','__le__',
        '__gt__','__ge__','__hash__','__bool__','__contains__','__name__','__main__','__file__',
        '__doc__','__module__','__class__','__dict__','__bases__','__import__','__builtins__',
        // 内置函数
        'print','len','range','enumerate','zip','map','filter','sorted','reversed','sum','min','max',
        'abs','round','pow','divmod','isinstance','issubclass','type','id','hash','dir','vars',
        'globals','locals','getattr','setattr','hasattr','delattr','callable','repr','str','int',
        'float','bool','list','tuple','dict','set','frozenset','bytes','bytearray','complex','object',
        'super','property','staticmethod','classmethod','iter','next','open','input','format','chr',
        'ord','bin','oct','hex','ascii','exec','eval','compile','memoryview','slice','Exception',
        'BaseException','ValueError','TypeError','KeyError','IndexError','AttributeError','RuntimeError',
        'StopIteration','ImportError','ModuleNotFoundError','FileNotFoundError','OSError','IOError',
        'SystemExit','KeyboardInterrupt','SystemError','ArithmeticError','ZeroDivisionError',
        'OverflowError','FloatingPointError','NotImplementedError','RecursionError','AssertionError',
        // 标准库模块
        'os','sys','json','re','math','random','time','datetime','logging','threading','multiprocessing',
        'collections','itertools','functools','operator','pathlib','subprocess','tempfile','shutil',
        'base64','hashlib','hmac','secrets','urllib','http','socket','ssl','asyncio','struct','codecs',
    ];

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['py', 'pyw', 'py3'];
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageName(): string
    {
        return 'python';
    }

    /**
     * 重写缩进压缩：Python 必须保留缩进语义，仅压缩行尾与多余空行。
     */
    protected function compressWhitespace(string $code): string
    {
        // 仅去除行尾空白
        $code = preg_replace('/[ \t]+$/m', '', $code);
        // 合并连续空行（最多保留一个）
        $code = preg_replace("/\n{3,}/", "\n\n", $code);
        // 压缩行内连续空格（但不影响行首缩进）
        $code = preg_replace_callback('/^(\s*)(.*)$/m', function ($m) {
            $indent = $m[1];
            $rest = preg_replace('/[ \t]{2,}/', ' ', $m[2]);
            return $indent . $rest;
        }, $code);
        return trim($code);
    }

    /**
     * 重写注释清除：Python 注释以 # 开头，且需保护 shebang 与编码声明。
     */
    protected function stripComments(string $code): string
    {
        $lines = explode("\n", $code);
        $out = [];
        $inTriple = false;
        $tripleMarker = '';

        foreach ($lines as $idx => $line) {
            // shebang 与编码声明保留
            if ($idx === 0 && preg_match('/^#!\//', $line)) {
                $out[] = $line;
                continue;
            }
            if (preg_match('/^#\s*coding[:=]/', $line)) {
                $out[] = $line;
                continue;
            }

            // 处理三引号字符串
            if ($inTriple) {
                $out[] = $line;
                if (str_contains($line, $tripleMarker)) {
                    // 简单判断：行内出现闭合
                    $inTriple = false;
                }
                continue;
            }
            // 检测三引号开始（粗略）
            if (preg_match('/\s*("""|\'\'\')/', $line) && substr_count($line, '"""') % 2 === 1) {
                $inTriple = true;
                $tripleMarker = '"""';
                $out[] = $line;
                continue;
            }
            if (preg_match('/\s*(\'\'\')/', $line) && substr_count($line, "'''") % 2 === 1) {
                $inTriple = true;
                $tripleMarker = "'''";
                $out[] = $line;
                continue;
            }

            // 移除行注释 # ... （保护字符串内的 #）
            $cleaned = $this->stripPythonLineComment($line);
            $out[] = $cleaned;
        }
        return implode("\n", $out);
    }

    /**
     * 移除一行中的 Python 注释（保护字符串内的 #）。
     */
    private function stripPythonLineComment(string $line): string
    {
        $inSingle = false;
        $inDouble = false;
        $result = '';
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if (!$inSingle && !$inDouble && $ch === '#') {
                break; // 注释开始
            }
            if ($ch === '\\' && $i + 1 < $len) {
                $result .= $ch . $line[$i + 1];
                $i++;
                continue;
            }
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            }
            $result .= $ch;
        }
        return rtrim($result);
    }

    /**
     * {@inheritdoc}
     * 变量名/函数名混淆
     */
    protected function obfuscateIdentifiers(string $code): string
    {
        // 1. 函数定义名：def foo(
        $code = preg_replace_callback(
            '/\bdef\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            function ($m) {
                if (in_array($m[1], self::RESERVED, true) || str_starts_with($m[1], '__')) {
                    return $m[0];
                }
                $new = $this->mapIdentifier('fn::' . $m[1], '_', 'abcdefghijklmnopqrstuvwxyz');
                return 'def ' . $new . '(';
            },
            $code
        );

        // 2. 类定义名：class Foo(  /  class Foo:
        $code = preg_replace_callback(
            '/\bclass\s+([a-zA-Z_][a-zA-Z0-9_]*)/',
            function ($m) {
                if (in_array($m[1], self::RESERVED, true) || str_starts_with($m[1], '__')) {
                    return $m[0];
                }
                return 'class ' . $this->mapIdentifier('cls::' . $m[1], '_', 'abcdefghijklmnopqrstuvwxyz');
            },
            $code
        );

        // 3. 赋值变量名：仅匹配行首（含缩进）的 标识符 =
        $code = preg_replace_callback(
            '/^(\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*=/m',
            function ($m) {
                if (in_array($m[2], self::RESERVED, true) || str_starts_with($m[2], '__')) {
                    return $m[0];
                }
                $new = $this->mapIdentifier('v::' . $m[2], '_', 'abcdefghijklmnopqrstuvwxyz');
                return $m[1] . $new . ' =';
            },
            $code
        );

        // 4. 替换已注册标识符的引用（词边界）
        foreach ($this->identifierMap as $key => $obf) {
            if (str_starts_with($key, 'fn::') || str_starts_with($key, 'cls::') || str_starts_with($key, 'v::')) {
                $orig = substr($key, strpos($key, '::') + 2);
                if (in_array($orig, self::RESERVED, true) || strlen($orig) < 3) {
                    continue;
                }
                // 避免破坏属性访问 obj.attr
                $code = preg_replace('/(?<![.\w])' . preg_quote($orig, '/') . '\b/', $obf, $code);
            }
        }

        return $code;
    }

    /**
     * {@inheritdoc}
     * 字符串加密：替换为 __import__('base64').b64decode().decode()
     *
     * 使用单次合并正则同时匹配双引号与单引号字符串，避免插入的
     * __import__('base64')... 中的字符串字面量被二次匹配导致嵌套。
     */
    protected function encryptStrings(string $code): string
    {
        $self = $this;
        $code = preg_replace_callback(
            '/"(?<d>(?:[^"\\\\]|\\\\.)*)"|\'(?<s>(?:[^\'\\\\]|\\\\.)*)\'/s',
            function ($m) use ($self) {
                if (isset($m['d']) && $m['d'] !== '') {
                    $raw = $self->unescapePyString($m['d']);
                } elseif (isset($m['s']) && $m['s'] !== '') {
                    $raw = $self->unescapePyString($m['s']);
                } else {
                    return $m[0];
                }
                if ($raw === '') {
                    return $m[0];
                }
                $enc = $self->aesEncrypt($raw);
                return "__import__('base64').b64decode('" . $enc . "').decode('utf-8')";
            },
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 控制流平坦化：在函数体首行后插入垃圾分支
     */
    protected function flattenControlFlow(string $code): string
    {
        $self = $this;
        $code = preg_replace_callback(
            '/^(\s*def\s+[a-zA-Z0-9_]+\([^)]*\)\s*(?:->\s*[^:]+)?:\n(?:\s+[^\n]*\n){0,1})/m',
            function ($m) use ($self) {
                $junk = $self->buildPythonJunk();
                return $m[1] . $junk . "\n";
            },
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 垃圾代码注入：在文件首部插入无用赋值
     */
    protected function injectJunkCode(string $code): string
    {
        $junk = '';
        for ($i = 0; $i < 3; $i++) {
            $v = $this->mapIdentifier('gj' . $i, '_', 'abcdefghijklmnopqrstuvwxyz');
            $junk .= $v . '=' . mt_rand(0, 99999) . "\n";
        }
        // 在 shebang/coding 声明之后插入
        if (preg_match('/^((?:#!.*\n)?(?:#.*coding.*\n)?)/', $code, $mm)) {
            $code = $mm[1] . $junk . substr($code, strlen($mm[1]));
        } else {
            $code = $junk . $code;
        }
        return $code;
    }

    /**
     * {@inheritdoc}
     * 反调试：检测 sys.settrace / sys.gettrace
     */
    protected function insertAntiDebug(string $code): string
    {
        $v = $this->mapIdentifier('_ad', '_', 'abcdefghijklmnopqrstuvwxyz');
        $fingerprint = $this->randomHex(8);
        $anti = "import sys as {$v}\n"
            . "def {$v}_t(f,e,a):\n"
            . " raise SystemExit(1)\n"
            . "try:\n"
            . " if {$v}.gettrace() is not None:\n"
            . "  {$v}.exit(1)\n"
            . " if \"{$fingerprint}\" in str({$v}.modules):\n"
            . "  {$v}.exit(1)\n"
            . "except SystemExit:\n"
            . " raise\n"
            . "except Exception:\n"
            . " pass\n";

        // 插入到 shebang/coding 之后
        if (preg_match('/^((?:#!.*\n)?(?:#.*coding.*\n)?)/', $code, $mm)) {
            $code = $mm[1] . $anti . substr($code, strlen($mm[1]));
        } else {
            $code = $anti . $code;
        }
        return $code;
    }

    /**
     * {@inheritdoc}
     * 后处理：注入 AES 解密辅助
     */
    protected function doHarden(string $code, array $opts): string
    {
        $helper = $this->buildPythonDecryptHelper();
        // 注入到文件首部（shebang/coding 之后）
        if (preg_match('/^((?:#!.*\n)?(?:#.*coding.*\n)?)/', $code, $mm)) {
            $code = $mm[1] . $helper . "\n" . substr($code, strlen($mm[1]));
        } else {
            $code = $helper . "\n" . $code;
        }

        // 替换字符串加密调用为 AES 解密（在 encryptStrings 中已用 base64，这里升级为 AES）
        $code = str_replace(
            "__import__('base64').b64decode('",
            "__ad('",
            $code
        );
        $code = str_replace(
            "').decode('utf-8')",
            ")",
            $code
        );

        return $code;
    }

    /* ---------------------------------------------------------------------
     * 私有辅助
     * ------------------------------------------------------------------- */

    /**
     * 构建 Python AES 解密辅助函数 __ad。
     */
    private function buildPythonDecryptHelper(): string
    {
        $rawKey = $this->encryptionKey . '::' . $this->seedPrefix;
        $xorMask = $this->randomHex(16);
        $masked = '';
        for ($i = 0, $n = strlen($rawKey); $i < $n; $i++) {
            $masked .= sprintf('%02x', ord($rawKey[$i]) ^ ord($xorMask[$i % strlen($xorMask)]));
        }

        $lines = [];
        $lines[] = "import base64 as _b, hashlib as _h";
        $lines[] = "def _rk():";
        $lines[] = "    mk='" . $masked . "';xm='" . $xorMask . "';k=''";
        $lines[] = "    for i in range(0,len(mk),2):";
        $lines[] = "        k+=chr(int(mk[i:i+2],16)^ord(xm[(i//2)%len(xm)]))";
        $lines[] = "    return k";
        $lines[] = "def __ad(d):";
        $lines[] = "    try:";
        $lines[] = "        k=_rk();kh=_h.sha256(k.encode()).digest()";
        $lines[] = "        p=_b.b64decode(d);iv=p[:16];c=p[16:]";
        $lines[] = "        from Crypto.Cipher import AES as _A";
        $lines[] = "        r=_A.new(kh,_A.MODE_CBC,iv).decrypt(c)";
        $lines[] = "        pad=r[-1];return r[:-pad].decode('utf-8')";
        $lines[] = "    except Exception:";
        $lines[] = "        try:";
        $lines[] = "            from cryptography.hazmat.primitives.ciphers import Cipher as _C,algorithms as _a,modes as _m";
        $lines[] = "            r=_C(_a.AES(kh),_m.CBC(iv)).decryptor().update(c)+_C(_a.AES(kh),_m.CBC(iv)).decryptor().finalize()";
        $lines[] = "            pad=r[-1];return r[:-pad].decode('utf-8')";
        $lines[] = "        except Exception:";
        $lines[] = "            return ''";
        return implode("\n", $lines);
    }

    /**
     * 构建一段 Python 垃圾分支代码（保持正确缩进）。
     */
    private function buildPythonJunk(): string
    {
        $v = $this->mapIdentifier('jl', '_', 'abcdefghijklmnopqrstuvwxyz');
        return '    ' . $v . '=' . mt_rand(0, 99999) . ' if ' . mt_rand(0, 1) . ' else ' . mt_rand(0, 99999);
    }

    /**
     * 解析 Python 字符串转义。
     */
    private function unescapePyString(string $s): string
    {
        $s = str_replace(
            ['\\n', '\\t', '\\r', '\\\\', "\\'", '\\"', '\\0'],
            ["\n", "\t", "\r", '\\', "'", '"', "\0"],
            $s
        );
        $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', fn($m) => chr(hexdec($m[1])), $s);
        $s = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn($m) => mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UTF-16BE'), $s);
        return $s;
    }
}
