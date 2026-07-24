<?php
declare(strict_types=1);

namespace app\common\hardener;

/**
 * Java 代码加固器
 *
 * 实现以下加固变换：
 *  - 类名/方法名/字段名混淆：重命名为短名 a/b/c
 *  - 字符串加密：替换为 StringDecryptor.decode("base64") 调用
 *  - 注释清除
 *  - 控制流平坦化：插入 try-catch 包裹与分发
 *  - 资源引用保护（防止 R.string 等被错误混淆）
 */
class JavaHardener extends AbstractHardener
{
    /**
     * Java 关键字与保留字（不做混淆）。
     */
    protected const KEYWORDS = [
        'abstract','assert','boolean','break','byte','case','catch','char','class','const',
        'continue','default','do','double','else','enum','extends','final','finally','float',
        'for','goto','if','implements','import','instanceof','int','interface','long','native',
        'new','package','private','protected','public','return','short','static','strictfp',
        'super','switch','synchronized','this','throw','throws','transient','try','void',
        'volatile','while','true','false','null','var','yield','record','sealed','permits',
        'String','Integer','Long','Double','Float','Boolean','Object','System','Math','Arrays',
        'List','Map','Set','ArrayList','HashMap','HashSet','LinkedList','TreeMap','TreeSet',
        'Collection','Iterator','Iterable','Comparable','Comparator','Exception','RuntimeException',
        'Throwable','Error','Override','Deprecated','SuppressWarnings','FunctionalInterface',
        'Void','Number','Byte','Short','Character','CharSequence','StringBuilder','StringBuffer',
        'Thread','Runnable','Callable','Future','Optional','Stream','Predicate','Consumer',
        'Function','Supplier','BiFunction','BiConsumer','Integer','Pair','Tuple',
        // 标准库常用类
        'Context','Activity','Fragment','View','Bundle','Intent','Resources','AssetManager',
        'Log','Toast','LayoutInflater','ViewGroup','RecyclerView','Adapter','ViewHolder',
        // 反射 / 解密辅助
        'StringDecryptor','decode','encrypt','decrypt','main','equals','hashCode','toString',
        'getClass','notify','notifyAll','wait','clone','finalize','charAt','length','substring',
        'indexOf','contains','startsWith','endsWith','replace','replaceAll','split','trim',
        'toLowerCase','toUpperCase','isEmpty','getBytes','toCharArray','compareTo','format',
        'valueOf','parseInt','parseLong','parseDouble','parseFloat','valueOf','getInteger',
    ];

    /**
     * 不混淆的字段前缀（资源引用等）。
     */
    protected const PROTECTED_PREFIXES = ['R.', 'BR.', 'BuildConfig.', 'databinding.', 'android.', 'java.', 'kotlin.', 'com.', 'org.'];

    /**
     * 已识别的类名集合（用于跨文件一致性与 main 方法保护）。
     */
    private array $classNames = [];

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['java', 'jsp'];
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageName(): string
    {
        return 'java';
    }

    /**
     * {@inheritdoc}
     * 类名/方法名/字段名混淆：重命名为短名 a/b/c
     */
    protected function obfuscateIdentifiers(string $code): string
    {
        // 1. 类名混淆：class Foo / class Foo extends Bar / class Foo implements Iface
        $code = preg_replace_callback(
            '/\bclass\s+([A-Z][a-zA-Z0-9_]*)/',
            function ($m) {
                $this->classNames[$m[1]] = true;
                return 'class ' . $this->mapIdentifier('cls::' . $m[1], '', 'abcdefghij');
            },
            $code
        );

        // 2. 方法名混淆：修饰符 + 返回类型 + 方法名(   不混淆内置方法
        $code = preg_replace_callback(
            '/\b(public|protected|private|static|final|synchronized|abstract|native|default)\s+'
            . '(?:[a-zA-Z_][\w.<>]*(?:\s*<[^>]*>)?)\s+'
            . '([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            function ($m) {
                $name = $m[2];
                if (in_array($name, self::KEYWORDS, true)) {
                    return $m[0];
                }
                if ($name === 'main') {
                    return $m[0];
                }
                $newName = $this->mapIdentifier('m::' . $name, '', 'abcdefghij');
                return str_replace($name . '(', $newName . '(', $m[0]);
            },
            $code
        );

        // 3. 字段名混淆：private Type field;  / private final Type field =
        $code = preg_replace_callback(
            '/\b(?:public|protected|private|static|final|transient|volatile)\s+'
            . '([a-zA-Z_][\w.<>]*(?:\s*<[^>]*>)?)\s+'
            . '([a-zA-Z_][a-zA-Z0-9_]*)\s*[;=]/',
            function ($m) {
                $name = $m[2];
                if (in_array($name, self::KEYWORDS, true)) {
                    return $m[0];
                }
                $newName = $this->mapIdentifier('f::' . $name, '', 'abcdefghij');
                return str_replace($name . ' ', $newName . ' ', $m[0]);
            },
            $code
        );

        // 4. 方法调用替换：仅替换已混淆过的方法名（保护资源引用）
        foreach (array_keys($this->identifierMap) as $key) {
            if (str_starts_with($key, 'm::')) {
                $orig = substr($key, 3);
                $obf = $this->identifierMap[$key];
                // 替换 .method( 与 this.method(  与 method(（无点号前缀）
                $code = preg_replace('/(?<![.\w$])' . preg_quote($orig, '/') . '\s*\(/', $obf . '(', $code);
                $code = preg_replace('/\.' . preg_quote($orig, '/') . '\s*\(/', '.' . $obf . '(', $code);
            } elseif (str_starts_with($key, 'f::')) {
                $orig = substr($key, 3);
                $obf = $this->identifierMap[$key];
                $code = preg_replace('/(?<![.\w$])' . preg_quote($orig, '/') . '\b/', $obf, $code);
            }
        }

        return $code;
    }

    /**
     * {@inheritdoc}
     * 字符串加密：替换为 StringDecryptor.decode("base64") 调用
     */
    protected function encryptStrings(string $code): string
    {
        $code = preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"/s',
            function ($m) {
                $raw = $this->unescapeJavaString($m[1]);
                if ($raw === '') {
                    return $m[0];
                }
                $enc = $this->aesEncrypt($raw);
                return 'StringDecryptor.decode("' . $enc . '")';
            },
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 控制流平坦化：在方法体首部插入 try-catch 分发器
     */
    protected function flattenControlFlow(string $code): string
    {
        $self = $this;
        // 匹配方法体起始 {
        $code = preg_replace_callback(
            '/(\b(?:public|protected|private|static|final|synchronized|abstract)?\s*'
            . '(?:[a-zA-Z_][\w.<>]*)\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\)\s*(?:throws\s+[\w.,\s]+)?\s*\{)\s*\n/',
            function ($m) use ($self) {
                $dispatcher = $self->buildJavaTryDispatcher();
                return $m[1] . "\n" . $dispatcher . "\n";
            },
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 注入垃圾代码块
     */
    protected function injectJunkCode(string $code): string
    {
        // 在类体首部插入若干私有垃圾字段
        $junk = '';
        for ($i = 0; $i < 3; $i++) {
            $name = $this->mapIdentifier('jf' . $i, '', 'abcdefghij');
            $junk .= 'private int ' . $name . '=' . mt_rand(0, 99999) . ';';
        }
        $code = preg_replace(
            '/(class\s+[A-Za-z0-9_]+\s*(?:extends\s+[\w.]+)?(?:\s+implements\s+[\w.,\s]+)?\s*\{)\s*\n/',
            "$1\n" . $junk . "\n",
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * Java 不插入反调试（在 doHarden 中通过 StringDecryptor 实现保护）
     */
    protected function insertAntiDebug(string $code): string
    {
        // 在 main 方法首部插入反调试检测
        $anti = ''
            . 'if(java.lang.management.ManagementFactory.getRuntimeMXBean()'
            . '.getInputArguments().toString().contains("-agentlib:jdwp")){'
            . 'System.exit(1);}'
            . 'if(System.getenv("JAVA_TOOL_OPTIONS")!=null'
            . '&&System.getenv("JAVA_TOOL_OPTIONS").contains("jdwp")){'
            . 'System.exit(1);}';

        $code = preg_replace(
            '/(public\s+static\s+void\s+main\s*\([^)]*\)\s*\{)/',
            "$1\n" . $anti . "\n",
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 后处理：插入 StringDecryptor 辅助类定义
     */
    protected function doHarden(string $code, array $opts): string
    {
        $decryptorClass = $this->buildStringDecryptorClass();
        // 在文件首部 package 行之后插入 StringDecryptor 内部类引用注释
        $mapComment = $this->buildJavaMapComment();
        $code = preg_replace(
            '/^(package\s+[\w.]+\s*;)\s*\n/',
            "$1\n" . $mapComment . "\n",
            $code
        );
        // 在最后一个 } 之前插入 StringDecryptor 类定义（作为内部类）
        $lastBrace = strrpos($code, '}');
        if ($lastBrace !== false) {
            $code = substr($code, 0, $lastBrace) . $decryptorClass . "\n}\n";
        } else {
            $code .= "\n" . $decryptorClass;
        }
        return $code;
    }

    /* ---------------------------------------------------------------------
     * 私有辅助
     * ------------------------------------------------------------------- */

    /**
     * 构建 StringDecryptor 辅助类。
     */
    private function buildStringDecryptorClass(): string
    {
        $rawKey = $this->encryptionKey . '::' . $this->seedPrefix;
        $xorMask = $this->randomHex(16);
        $maskedKey = '';
        for ($i = 0, $n = strlen($rawKey); $i < $n; $i++) {
            $maskedKey .= sprintf('%02x', ord($rawKey[$i]) ^ ord($xorMask[$i % strlen($xorMask)]));
        }

        return 'static class StringDecryptor{'
            . 'private static final String MK="' . $maskedKey . '";'
            . 'private static final String XM="' . $xorMask . '";'
            . 'private static String recoverKey(){'
            . 'StringBuilder sb=new StringBuilder();'
            . 'for(int i=0;i<MK.length();i+=2){'
            . 'int mh=Integer.parseInt(MK.substring(i,i+2),16);'
            . 'int xm=XM.charAt((i/2)%XM.length());'
            . 'sb.append((char)(mh^xm));'
            . '}return sb.toString();}'
            . 'public static String decode(String d){'
            . 'try{'
            . 'String k=recoverKey();'
            . 'byte[] kh=java.security.MessageDigest.getInstance("SHA-256").digest(k.getBytes("UTF-8"));'
            . 'byte[] p=java.util.Base64.getDecoder().decode(d);'
            . 'byte[] iv=new byte[16];'
            . 'System.arraycopy(p,0,iv,0,16);'
            . 'byte[] c=new byte[p.length-16];'
            . 'System.arraycopy(p,16,c,0,c.length);'
            . 'javax.crypto.Cipher cipher=javax.crypto.Cipher.getInstance("AES/CBC/PKCS5Padding");'
            . 'cipher.init(javax.crypto.Cipher.DECRYPT_MODE,'
            . 'new javax.crypto.spec.SecretKeySpec(kh,"AES"),'
            . 'new javax.crypto.spec.IvParameterSpec(iv));'
            . 'byte[] r=cipher.doFinal(c);'
            . 'return new String(r,"UTF-8");'
            . '}catch(Exception e){return "";}'
            . '}}';
    }

    /**
     * 构建 try-catch 控制流分发器。
     */
    private function buildJavaTryDispatcher(): string
    {
        $stateVar = $this->mapIdentifier('state', '', 'abcdefghij');
        $branches = mt_rand(2, 4);
        $cases = '';
        for ($i = 0; $i < $branches; $i++) {
            $jv = $this->mapIdentifier('jv' . $i, '', 'abcdefghij');
            $cases .= 'case ' . $i . ':' . $jv . '=' . mt_rand(0, 99999) . ';break;';
        }
        return 'int ' . $stateVar . '=(int)(Math.random()*' . $branches . ');'
            . 'try{switch(' . $stateVar . '){' . $cases . '}}catch(Exception e){}'
            . '// cfg-flatten:' . $this->randomHex(6);
    }

    /**
     * 生成 Java 映射头注释。
     */
    private function buildJavaMapComment(): string
    {
        $lines = ['/* === Hardening Map (developer only) ==='];
        $lines[] = ' * Language: java';
        $lines[] = ' * Seed: ' . $this->seedPrefix;
        $lines[] = ' * Generated: ' . date('Y-m-d H:i:s');
        $lines[] = ' * Identifiers:';
        foreach ($this->identifierMap as $orig => $obf) {
            $lines[] = ' *   ' . $orig . ' => ' . $obf;
        }
        $lines[] = ' */';
        return implode("\n", $lines);
    }

    /**
     * 解析 Java 字符串转义。
     */
    private function unescapeJavaString(string $s): string
    {
        $s = str_replace(
            ['\\"', '\\n', '\\t', '\\r', '\\\\', "\\0"],
            ['"', "\n", "\t", "\r", '\\', "\0"],
            $s
        );
        $s = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn($m) => mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UTF-16BE'), $s);
        $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{1,2})/', fn($m) => chr(hexdec($m[1])), $s);
        return $s;
    }
}
