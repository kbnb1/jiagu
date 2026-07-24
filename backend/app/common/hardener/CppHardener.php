<?php
declare(strict_types=1);

namespace app\common\hardener;

/**
 * C/C++ 代码加固器
 *
 * 实现以下加固变换：
 *  - 宏定义混淆：用宏展开增加复杂度
 *  - 字符串加密：替换为 DECRYPT("base64") 宏调用
 *  - 函数内联控制（通过宏控制 inline）
 *  - 垃圾代码块注入
 *  - 头文件保护（#pragma once / include guard）
 *  - 注释清除
 */
class CppHardener extends AbstractHardener
{
    /**
     * C/C++ 关键字与标准库标识符（不混淆）。
     */
    protected const RESERVED = [
        'auto','break','case','char','const','continue','default','do','double','else','enum',
        'extern','float','for','goto','if','inline','int','long','register','return','short',
        'signed','sizeof','static','struct','switch','typedef','union','unsigned','void','volatile',
        'while','bool','true','false','nullptr','class','public','protected','private','virtual',
        'override','final','template','typename','namespace','using','this','new','delete','operator',
        'friend','explicit','mutable','constexpr','decltype','auto','static_cast','dynamic_cast',
        'const_cast','reinterpret_cast','throw','try','catch','noexcept','sizeof','alignof','alignas',
        'thread_local','co_await','co_return','co_yield','concept','requires','consteval','constinit',
        'char16_t','char32_t','wchar_t','size_t','ptrdiff_t','intptr_t','uintptr_t','int8_t','int16_t',
        'int32_t','int64_t','uint8_t','uint16_t','uint32_t','uint64_t','NULL','EOF','stdin','stdout',
        'stderr','FILE','printf','scanf','fprintf','sprintf','snprintf','sscanf','fopen','fclose',
        'fread','fwrite','fgets','fputs','fgetc','fputc','feof','ferror','malloc','calloc','realloc',
        'free','memcpy','memset','memcmp','memmove','strcpy','strncpy','strcat','strncat','strcmp',
        'strncmp','strlen','strchr','strstr','strtok','atoi','atol','atof','strtol','strtoul','strtod',
        'exit','abort','atexit','getenv','system','qsort','bsearch','rand','srand','time','clock',
        'abs','labs','div','ldiv','min','max','assert','errno','perror','std','string','vector','map',
        'set','unordered_map','unordered_set','list','deque','queue','stack','pair','tuple','array',
        'cout','cin','cerr','clog','endl','flush','std::string','std::vector','main','argc','argv',
        // 加密宏
        'DECRYPT','DECODE',
    ];

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['c', 'cpp', 'cc', 'cxx', 'h', 'hpp', 'hh', 'hxx', 'ino'];
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageName(): string
    {
        return 'cpp';
    }

    /**
     * 重写注释清除：保留 # 预处理指令，移除 /* *​/ 与 // 注释。
     */
    protected function stripComments(string $code): string
    {
        // 移除块注释
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        // 移除行注释 //（保护字符串内的 //，如 http://）
        $lines = explode("\n", $code);
        $out = [];
        foreach ($lines as $line) {
            $cleaned = $this->stripCppLineComment($line);
            $out[] = $cleaned;
        }
        return implode("\n", $out);
    }

    /**
     * 移除一行中的 C++ 注释（保护字符串内的 //）。
     */
    private function stripCppLineComment(string $line): string
    {
        $inSingle = false;
        $inDouble = false;
        $result = '';
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if (!$inSingle && !$inDouble && $ch === '/' && $i + 1 < $len && $line[$i + 1] === '/') {
                break;
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
     * 标识符混淆：函数名 / 局部变量名混淆（不动类型与标准库）
     */
    protected function obfuscateIdentifiers(string $code): string
    {
        // 1. 函数定义：返回类型 + 函数名( ... )
        //    [a-zA-Z_][\w:<>*\s&]*  作为返回类型，捕获函数名
        $code = preg_replace_callback(
            '/\b((?:[a-zA-Z_][\w:<>*&\s]*?\s+)+)([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^;{}]*)\)\s*\{/',
            function ($m) {
                $retType = $m[1];
                $name = $m[2];
                $params = $m[3];
                if (in_array($name, self::RESERVED, true) || $name === 'main') {
                    return $m[0];
                }
                $new = $this->mapIdentifier('fn::' . $name, '_', 'abcdefghijklmnopqrstuvwxyz');
                // 混淆参数名
                $newParams = $this->obfuscateCppParams($params);
                return $retType . $new . '(' . $newParams . '){';
            },
            $code
        );

        // 2. 替换已注册函数的调用
        foreach ($this->identifierMap as $key => $obf) {
            if (str_starts_with($key, 'fn::')) {
                $orig = substr($key, 4);
                if (in_array($orig, self::RESERVED, true) || strlen($orig) < 2) {
                    continue;
                }
                // 调用：name(  且前面不是 . -> ::
                $code = preg_replace('/(?<![.\w:>])\b' . preg_quote($orig, '/') . '\s*\(/', $obf . '(', $code);
            }
        }

        return $code;
    }

    /**
     * 混淆 C++ 函数参数名。
     */
    private function obfuscateCppParams(string $params): string
    {
        if (trim($params) === '' || trim($params) === 'void') {
            return $params;
        }
        $parts = array_map('trim', explode(',', $params));
        $out = [];
        $idx = 0;
        foreach ($parts as $p) {
            // 匹配 "类型 名字" 末尾标识符
            if (preg_match('/^(.*?)([a-zA-Z_][a-zA-Z0-9_]*)$/', $p, $mm)) {
                $type = $mm[1];
                $name = $mm[2];
                if (in_array($name, self::RESERVED, true)) {
                    $out[] = $p;
                } else {
                    $newName = $this->mapIdentifier('p::' . $name, '_', 'abcdefghijklmnopqrstuvwxyz');
                    $out[] = $type . $newName;
                }
            } else {
                $out[] = $p;
            }
            $idx++;
        }
        return implode(', ', $out);
    }

    /**
     * {@inheritdoc}
     * 字符串加密：替换为 DECRYPT("base64") 宏调用
     */
    protected function encryptStrings(string $code): string
    {
        // 跳过 #include 等预处理行中的字符串
        $code = preg_replace_callback(
            '/("(?:[^"\\\\]|\\\\.)*")/s',
            function ($m) {
                $raw = $this->unescapeCppString(substr($m[1], 1, -1));
                if ($raw === '') {
                    return $m[1];
                }
                $enc = $this->aesEncrypt($raw);
                return 'DECRYPT("' . $enc . '")';
            },
            $code
        );
        // 还原 #include "xxx" 中的字符串（被误替换）
        $code = preg_replace_callback(
            '/#include\s+DECRYPT\("([^"]+)"\)/',
            fn($m) => '#include "' . $m[1] . '"',
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 控制流平坦化：在函数体首部插入 switch 分发器
     */
    protected function flattenControlFlow(string $code): string
    {
        $self = $this;
        $code = preg_replace_callback(
            '/(\)\s*\{)\n(\s*)/',
            function ($m) use ($self) {
                // 仅对函数体的 { 处理（粗略：上一行含 )）
                return $m[1] . "\n" . $m[2] . $self->buildCppDispatcher() . "\n" . $m[2];
            },
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 垃圾代码块注入：在文件首部插入无用全局变量与宏
     */
    protected function injectJunkCode(string $code): string
    {
        $junk = '';
        for ($i = 0; $i < 3; $i++) {
            $v = $this->mapIdentifier('gj' . $i, '_', 'abcdefghijklmnopqrstuvwxyz');
            $junk .= 'static volatile int ' . $v . '=' . mt_rand(0, 99999) . ';' . "\n";
        }
        // 插入到第一个非 #include / #pragma 行之前
        if (preg_match('/^((?:#[^\n]*\n|#pragma[^\n]*\n)*)/s', $code, $mm)) {
            $code = $mm[1] . $junk . substr($code, strlen($mm[1]));
        } else {
            $code = $junk . $code;
        }
        return $code;
    }

    /**
     * {@inheritdoc}
     * C/C++ 反调试：检测 ptrace（Linux）防止 gdb 调试
     */
    protected function insertAntiDebug(string $code): string
    {
        $anti = ''
            . '#ifndef __ANTI_DEBUG_GUARD__' . "\n"
            . '#define __ANTI_DEBUG_GUARD__' . "\n"
            . '#if defined(__linux__)' . "\n"
            . '#include <sys/ptrace.h>' . "\n"
            . '#include <unistd.h>' . "\n"
            . 'static void __attribute__((constructor)) __ad_init(void){' . "\n"
            . 'if(ptrace(PTRACE_TRACEME,0,1,0)==-1){_exit(1);}' . "\n"
            . '}' . "\n"
            . '#endif' . "\n"
            . '#endif';

        // 插入到 #include 块之后
        if (preg_match('/^((?:#[^\n]*\n)*)/s', $code, $mm)) {
            $code = $mm[1] . $anti . "\n" . substr($code, strlen($mm[1]));
        } else {
            $code = $anti . "\n" . $code;
        }
        return $code;
    }

    /**
     * {@inheritdoc}
     * 后处理：插入头文件保护、宏定义混淆、DECRYPT 宏实现
     */
    protected function doHarden(string $code, array $opts): string
    {
        $headerGuard = $this->buildHeaderGuard($opts);
        $macros = $this->buildMacroConfusion();
        $decryptMacro = $this->buildDecryptMacro();

        // 头文件保护包裹整个内容
        $code = $headerGuard['open'] . "\n" . $macros . "\n" . $decryptMacro . "\n" . $code . "\n" . $headerGuard['close'];

        return $code;
    }

    /* ---------------------------------------------------------------------
     * 私有辅助
     * ------------------------------------------------------------------- */

    /**
     * 构建头文件保护（include guard + #pragma once）。
     */
    private function buildHeaderGuard(array $opts): array
    {
        $guardName = '_HARDENED_HEADER_' . strtoupper($this->randomHex(12)) . '_H_';
        return [
            'open'  => '#ifndef ' . $guardName . "\n#define " . $guardName . "\n#pragma once",
            'close' => '#endif /* ' . $guardName . ' */',
        ];
    }

    /**
     * 构建宏定义混淆：用宏展开增加复杂度，控制函数内联。
     */
    private function buildMacroConfusion(): string
    {
        $macros = '';
        // 强制内联控制宏
        $macros .= '#define __HARDEN_INLINE static inline __attribute__((always_inline))' . "\n";
        $macros .= '#define __HARDEN_NOINLINE __attribute__((noinline))' . "\n";
        // 字符串化与拼接宏
        $macros .= '#define __HARDEN_CAT_(a,b) a##b' . "\n";
        $macros .= '#define __HARDEN_CAT(a,b) __HARDEN_CAT_(a,b)' . "\n";
        $macros .= '#define __HARDEN_STR_(x) #x' . "\n";
        $macros .= '#define __HARDEN_STR(x) __HARDEN_STR_(x)' . "\n";
        // 混淆控制流宏：用宏实现不可读的分支
        $macros .= '#define __HARDEN_BRANCH(c,a,b) ((c)?(a):(b))' . "\n";
        $macros .= '#define __HARDEN_LOOP(n,b) do{int __i=' . mt_rand(1, 99) . ';while(__i++<n){b;}}while(0)' . "\n";
        // 反篡改宏：编译期校验
        $tag = $this->randomHex(8);
        $macros .= '#define __HARDEN_TAG ' . hexdec(substr($tag, 0, 8)) . "\n";
        $macros .= 'static_assert(sizeof(char[1+!__HARDEN_TAG])!=1,"hardened");' . "\n";
        return $macros;
    }

    /**
     * 构建 DECRYPT 宏实现。
     */
    private function buildDecryptMacro(): string
    {
        $rawKey = $this->encryptionKey . '::' . $this->seedPrefix;
        $xorMask = $this->randomHex(16);
        $masked = '';
        for ($i = 0, $n = strlen($rawKey); $i < $n; $i++) {
            $masked .= sprintf('%02x', ord($rawKey[$i]) ^ ord($xorMask[$i % strlen($xorMask)]));
        }

        $lines = [];
        $lines[] = '#include <openssl/evp.h>';
        $lines[] = '#include <openssl/sha.h>';
        $lines[] = '#include <string.h>';
        $lines[] = '#include <stdlib.h>';
        $lines[] = 'static const char* __HARDEN_MK="' . $masked . '";';
        $lines[] = 'static const char* __HARDEN_XM="' . $xorMask . '";';
        $lines[] = 'static char* __harden_recover_key(){';
        $lines[] = ' size_t ml=strlen(__HARDEN_MK),xl=strlen(__HARDEN_XM);';
        $lines[] = ' char* k=(char*)malloc(ml/2+1);';
        $lines[] = ' size_t ki=0;';
        $lines[] = ' for(size_t i=0;i+1<ml;i+=2){';
        $lines[] = '  unsigned int mh;';
        $lines[] = '  sscanf(__HARDEN_MK+i,"%2x",&mh);';
        $lines[] = '  k[ki++]=(char)(mh^__HARDEN_XM[(i/2)%xl]);';
        $lines[] = ' }';
        $lines[] = ' k[ki]=0; return k;';
        $lines[] = '}';
        $lines[] = 'static char* __harden_b64dec(const char* in,size_t* outlen){';
        $lines[] = ' static const int8_t T[256]={0}; /* simplified: assume OpenSSL base64 */';
        $lines[] = ' int rc=EVP_DecodeBlock(NULL,NULL,0);(void)rc; (void)T;';
        $lines[] = ' size_t il=strlen(in);';
        $lines[] = ' unsigned char* out=(unsigned char*)malloc(il+4);';
        $lines[] = ' int n=EVP_DecodeBlock(out,(const unsigned char*)in,(int)il);';
        $lines[] = ' if(n<0){free(out);*outlen=0;return NULL;}';
        $lines[] = ' *outlen=(size_t)n; return (char*)out;';
        $lines[] = '}';
        $lines[] = 'static const char* __harden_decrypt(const char* d){';
        $lines[] = ' char* k=__harden_recover_key();';
        $lines[] = ' unsigned char kh[32];';
        $lines[] = ' SHA256((const unsigned char*)k,strlen(k),kh);';
        $lines[] = ' free(k);';
        $lines[] = ' size_t pl=0;';
        $lines[] = ' char* p=__harden_b64dec(d,&pl);';
        $lines[] = ' if(!p||pl<17){free(p);return "";}';
        $lines[] = ' unsigned char iv[16]; memcpy(iv,p,16);';
        $lines[] = ' int cl=(int)pl-16;';
        $lines[] = ' EVP_CIPHER_CTX* ctx=EVP_CIPHER_CTX_new();';
        $lines[] = ' EVP_DecryptInit_ex(ctx,EVP_aes_256_cbc(),NULL,kh,iv);';
        $lines[] = ' unsigned char* r=(unsigned char*)malloc(cl+16);';
        $lines[] = ' int rl=0,fl=0;';
        $lines[] = ' EVP_DecryptUpdate(ctx,r,&rl,(const unsigned char*)p+16,cl);';
        $lines[] = ' EVP_DecryptFinal_ex(ctx,r+rl,&fl);';
        $lines[] = ' EVP_CIPHER_CTX_free(ctx);';
        $lines[] = ' free(p);';
        $lines[] = ' r[rl+fl]=0;';
        $lines[] = ' return (const char*)r;';
        $lines[] = '}';
        $lines[] = '#define DECRYPT(x) __harden_decrypt(x)';

        return implode("\n", $lines);
    }

    /**
     * 构建一段 C++ 垃圾 switch 分发器。
     */
    private function buildCppDispatcher(): string
    {
        $v = $this->mapIdentifier('ds', '_', 'abcdefghijklmnopqrstuvwxyz');
        $branches = mt_rand(2, 4);
        $cases = '';
        for ($i = 0; $i < $branches; $i++) {
            $jv = $this->mapIdentifier('dv' . $i, '_', 'abcdefghijklmnopqrstuvwxyz');
            $cases .= 'case ' . $i . ':int ' . $jv . '=' . mt_rand(0, 99999) . ';break;';
        }
        return 'int ' . $v . '=' . mt_rand(0, $branches - 1) . ';switch(' . $v . '){' . $cases . 'default:break;}';
    }

    /**
     * 解析 C/C++ 字符串转义。
     */
    private function unescapeCppString(string $s): string
    {
        $s = str_replace(
            ['\\n', '\\t', '\\r', '\\\\', '\\"', "\\'", '\\0', '\\a', '\\b', '\\f', '\\v'],
            ["\n", "\t", "\r", '\\', '"', "'", "\0", "\x07", "\x08", "\x0c", "\x0b"],
            $s
        );
        $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{1,2})/', fn($m) => chr(hexdec($m[1])), $s);
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', fn($m) => chr(octdec($m[1])), $s);
        return $s;
    }
}
