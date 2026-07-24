<?php
declare(strict_types=1);

namespace app\common\hardener;

/**
 * PHP 代码加固器
 *
 * 实现以下加固变换：
 *  - 变量名混淆：$variable → $a1b2c3（映射表保证一致性）
 *  - 字符串加密：所有字符串字面量 AES 加密，替换为 __decrypt('base64') 调用
 *  - 注释清除：删除 // 与块注释（保留文件头 license）
 *  - 函数名混淆：非内置函数重命名为混淆名
 *  - 反调试：检测 xdebug / phpspy 并 exit
 *  - 空白压缩
 *  - 生成开发者可见的混淆映射头注释
 */
class PhpHardener extends AbstractHardener
{
    /**
     * PHP 内置函数与语言结构列表（不做混淆）。
     */
    protected const BUILTINS = [
        // 语言结构
        'echo','print','die','exit','isset','empty','unset','list','array','compact','extract',
        'eval','include','require','include_once','require_once','new','clone','instanceof',
        'function','return','if','else','elseif','endif','while','for','foreach','as','switch',
        'case','default','break','continue','do','try','catch','finally','throw','class','interface',
        'trait','extends','implements','abstract','final','static','public','protected','private',
        'const','define','global','var','use','namespace','goto','yield','match','fn','enum','readonly',
        // 常用内置函数
        'strlen','substr','strpos','str_replace','str_repeat','strtolower','strtoupper','trim','ltrim','rtrim',
        'explode','implode','join','split','preg_match','preg_replace','preg_match_all','sprintf','printf','vsprintf',
        'count','sizeof','in_array','array_key_exists','array_keys','array_values','array_merge','array_map',
        'array_filter','array_reduce','array_push','array_pop','array_shift','array_unshift','array_slice',
        'array_flip','array_reverse','array_unique','array_combine','array_fill','array_diff','array_intersect',
        'sort','rsort','usort','asort','ksort','array_search','array_column','array_chunk','array_sum','array_product',
        'is_array','is_string','is_int','is_integer','is_float','is_bool','is_null','is_object','is_resource','is_callable',
        'is_numeric','is_scalar','is_iterable','intval','floatval','doubleval','strval','boolval','settype','gettype',
        'date','time','mktime','strtotime','strftime','microtime','date_create','date_format','json_encode','json_decode',
        'serialize','unserialize','base64_encode','base64_decode','urlencode','urldecode','rawurlencode','rawurldecode',
        'md5','sha1','hash','hash_hmac','password_hash','password_verify','password_needs_rehash','openssl_encrypt',
        'openssl_decrypt','openssl_random_pseudo_bytes','random_bytes','random_int','mt_rand','rand','srand',
        'mt_srand','uniqid','bin2hex','hex2bin','pack','unpack','chr','ord','dechex','hexdec','decoct','octdec','decbin','bindec',
        'file_get_contents','file_put_contents','fopen','fclose','fread','fwrite','fgets','fgetc','file','readfile',
        'file_exists','is_file','is_dir','filesize','filemtime','dirname','basename','pathinfo','realpath','glob',
        'mkdir','rmdir','unlink','rename','copy','chmod','chown','touch','tempnam','tmpfile','move_uploaded_file',
        'header','headers_sent','http_response_code','setcookie','session_start','session_destroy','session_id',
        'session_name','session_regenerate_id','session_unset','session_set_save_handler','ob_start','ob_end_clean',
        'ob_end_flush','ob_get_clean','ob_get_contents','ob_flush','flush','print_r','var_dump','var_export',
        'debug_zval_refcount','debug_print_backtrace','debug_backtrace','error_reporting','set_error_handler',
        'restore_error_handler','trigger_error','user_error','error_log','error_get_last','set_exception_handler',
        'restore_exception_handler','register_shutdown_function','call_user_func','call_user_func_array','forward_static_call',
        'func_get_args','func_num_args','func_get_arg','function_exists','method_exists','class_exists','interface_exists',
        'trait_exists','property_exists','get_class','get_parent_class','get_called_class','get_object_vars','get_class_vars',
        'get_class_methods','get_declared_classes','get_declared_interfaces','get_declared_traits','is_a','is_subclass_of',
        'class_alias','class_implements','class_parents','class_uses','ReflectionClass','ReflectionMethod','ReflectionFunction',
        'ReflectionProperty','ReflectionParameter','ReflectionException','define','defined','constant','get_defined_constants',
        'get_defined_vars','get_defined_functions','get_resource_type','get_resource_id','extension_loaded','get_loaded_extensions',
        'function_exists','phpversion','phpinfo','php_sapi_name','php_uname','php_ini_loaded_file','ini_get','ini_set',
        'ini_restore','get_cfg_var','set_time_limit','memory_get_usage','memory_get_peak_usage','gc_collect_cycles','gc_enable',
        'gc_disable','gc_enabled','assert','assert_options','usleep','sleep','time_nanosleep','time_sleep_until',
        'exec','system','passthru','shell_exec','escapeshellarg','escapeshellcmd','proc_open','proc_close','proc_get_status',
        'popen','pclose','stream_get_contents','stream_get_line','stream_set_blocking','stream_set_timeout','stream_get_meta_data',
        'fopen','fsockopen','pfsockopen','stream_socket_client','stream_socket_server','stream_socket_accept','curl_init',
        'curl_setopt','curl_exec','curl_close','curl_error','curl_getinfo','finfo_open','finfo_file','mime_content_type',
        'gzcompress','gzuncompress','gzencode','gzdecode','gzdeflate','gzinflate','zlib_encode','zlib_decode',
        'mb_strlen','mb_substr','mb_strpos','mb_strtolower','mb_strtoupper','mb_convert_encoding','mb_detect_encoding',
        'iconv','iconv_strlen','utf8_encode','utf8_decode','htmlentities','htmlspecialchars','html_entity_decode',
        'htmlspecialchars_decode','nl2br','strip_tags','addslashes','stripslashes','addcslashes','stripcslashes','quotemeta',
        'wordwrap','chunk_split','number_format','money_format','str_pad','str_split','strrev','strtr','similar_text',
        'levenshtein','soundex','metaphone','localeconv','setlocale','nl_langinfo','ctype_alpha','ctype_digit','ctype_alnum',
        'ctype_lower','ctype_upper','ctype_space','ctype_punct','ctype_print','ctype_graph','ctype_cntrl','ctype_xdigit',
        'abs','ceil','floor','round','sqrt','pow','exp','log','log10','log2','sin','cos','tan','asin','acos','atan','atan2',
        'pi','min','max','range','fmod','intdiv','floor','ceil','round','hypot','deg2rad','rad2deg','base_convert',
        'ip2long','long2ip','gethostbyname','gethostbyaddr','gethostname','checkdnsrr','dns_get_record','dns_check_record',
        // 自身解密辅助函数（不混淆）
        '__decrypt',
    ];

    /**
     * PHP 超全局与保留变量（不做混淆）。
     */
    protected const RESERVED_VARS = [
        'this','GLOBALS','_GET','_POST','_SERVER','_FILES','_COOKIE','_SESSION','_REQUEST','_ENV',
        'argc','argv','_COOKIE','HTTP_RAW_POST_DATA','http_response_header',
    ];

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['php', 'phtml', 'php5', 'php7', 'phps', 'inc'];
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageName(): string
    {
        return 'php';
    }

    /**
     * {@inheritdoc}
     * PHP 变量名混淆：$variable → $a1b2c3
     */
    protected function obfuscateIdentifiers(string $code): string
    {
        // 1. 变量名混淆
        $code = preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)/',
            function ($m) {
                $name = $m[1];
                if (in_array($name, self::RESERVED_VARS, true)) {
                    return '$' . $name;
                }
                return '$' . $this->mapIdentifier($name, '', 'abcdefghijklmnopqrstuvwxyz');
            },
            $code
        );

        // 2. 函数定义名混淆：function foo( → function obf(
        $code = preg_replace_callback(
            '/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            function ($m) {
                if (in_array($m[1], self::BUILTINS, true)) {
                    return $m[0];
                }
                $newName = $this->mapIdentifier('fn::' . $m[1], '', 'abcdefghijklmnopqrstuvwxyz');
                return 'function ' . $newName . '(';
            },
            $code
        );

        // 3. 函数调用名混淆：foo( → obf(
        // 仅匹配出现在行首或非 -> :: 后的标识符调用
        $builtinsSet = array_flip(self::BUILTINS);
        $code = preg_replace_callback(
            '/(?<![>\w$:>])([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            function ($m) use ($builtinsSet) {
                $name = $m[1];
                // 跳过语言关键字与内置函数
                if (isset($builtinsSet[$name])) {
                    return $m[0];
                }
                // 仅在已注册到映射表（即作为函数定义被混淆过）时才替换调用处
                if (isset($this->identifierMap['fn::' . $name])) {
                    return $this->identifierMap['fn::' . $name] . '(';
                }
                return $m[0];
            },
            $code
        );

        return $code;
    }

    /**
     * {@inheritdoc}
     * 字符串加密：所有字符串字面量 → __decrypt('base64')
     *
     * 注意：必须用单次合并的正则同时匹配双引号与单引号字符串，
     * 否则第一次替换插入的 __decrypt('base64') 中的单引号字符串
     * 会被第二次单引号正则再次匹配，导致双重嵌套。
     */
    protected function encryptStrings(string $code): string
    {
        $self = $this;
        $code = preg_replace_callback(
            '/"(?<d>(?:[^"\\\\]|\\\\.)*)"|\'(?<s>(?:[^\'\\\\]|\\\\.)*)\'/s',
            function ($m) use ($self) {
                if (isset($m['d']) && $m['d'] !== '') {
                    $raw = $self->unescapePhpString($m['d'], '"');
                } elseif (isset($m['s']) && $m['s'] !== '') {
                    $raw = $self->unescapePhpString($m['s'], "'");
                } else {
                    return $m[0];
                }
                if ($raw === '') {
                    return $m[0];
                }
                $enc = $self->aesEncrypt($raw);
                return "__decrypt('" . $enc . "')";
            },
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 控制流平坦化：在函数体内插入垃圾分支
     */
    protected function flattenControlFlow(string $code): string
    {
        // 在每个 function { 体首部插入一个随机分发器
        $self = $this;
        $code = preg_replace_callback(
            '/(function\s+[a-zA-Z0-9_]*\s*\([^)]*\)\s*(?::\s*[^{]+)?\s*\{)/',
            function ($m) use ($self) {
                $dispatcher = $self->buildPhpJunkDispatcher();
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
        // 在文件首部（<?php 之后）插入若干无用变量赋值
        $junk = '';
        for ($i = 0; $i < 3; $i++) {
            $v = '$' . $this->mapIdentifier('junk' . $i, '', 'abcdefghijklmnopqrstuvwxyz');
            $junk .= $v . '=' . mt_rand(0, 99999) . ';';
        }
        $code = preg_replace(
            '/^(<\?php\s*)/',
            "$1" . $junk,
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 反调试：检测 xdebug / phpspy 并 exit
     */
    protected function insertAntiDebug(string $code): string
    {
        $anti = ''
            . '(function(){'
            . '$__a=false;'
            . 'if(extension_loaded("xdebug")||function_exists("xdebug_break")){$__a=true;}'
            . 'if(defined("PHPSPY_LOADED")||(function_exists("getmypid")&&@file_exists("/proc/".getmypid()."/comm")&&strpos(@file_get_contents("/proc/".getmypid()."/comm"),"phpspy")!==false)){$__a=true;}'
            . 'if(function_exists("getenv")&&(getenv("PHP_XDEBUG_LOADED")||getenv("XDEBUG_MODE"))){$__a=true;}'
            . 'if($__a){if(function_exists("http_response_code")){http_response_code(500);}exit(255);}'
            . '})();';

        // 注入到 <?php 之后
        $code = preg_replace(
            '/^(<\?php\s*)/',
            "$1" . $anti,
            $code
        );
        return $code;
    }

    /**
     * {@inheritdoc}
     * 后处理：注入 __decrypt 运行时函数定义、生成映射头注释
     */
    protected function doHarden(string $code, array $opts): string
    {
        $decryptFn = $this->buildDecryptFunction();

        // 生成开发者可见的映射头注释（不影响运行）
        $mapComment = $this->buildMapHeaderComment();

        // 在 <?php 之后插入解密函数与映射注释
        $inject = $mapComment . "\n" . $decryptFn . "\n";
        $code = preg_replace(
            '/^(<\?php\s*)/',
            "$1" . $inject,
            $code,
            1
        );

        return $code;
    }

    /* ---------------------------------------------------------------------
     * 私有辅助
     * ------------------------------------------------------------------- */

    /**
     * 构建 PHP 解密运行时函数。
     * 密钥以异或混淆形式嵌入，避免明文出现。
     */
    private function buildDecryptFunction(): string
    {
        // 原始派生密钥（与 aesEncrypt 中 deriveKey() 一致）
        $rawKey = $this->encryptionKey . '::' . $this->seedPrefix;
        // 异或混淆密钥
        $xorMask = $this->randomHex(16);
        $maskedKey = '';
        for ($i = 0, $n = strlen($rawKey); $i < $n; $i++) {
            $maskedKey .= sprintf('%02x', ord($rawKey[$i]) ^ ord($xorMask[$i % strlen($xorMask)]));
        }

        return 'if(!function_exists("__decrypt")){'
            . 'function __decrypt($d){'
            . '$mk="' . $maskedKey . '";'
            . '$xm="' . $xorMask . '";'
            . '$k="";'
            . 'for($i=0,$n=strlen($mk);$i<$n;$i+=2){'
            . '$k.=chr(hexdec(substr($mk,$i,2))^ord($xm[($i/2)%strlen($xm)]));'
            . '}'
            . '$kh=hash("sha256",$k,true);'
            . '$p=base64_decode($d);'
            . 'if($p===false||strlen($p)<17){return "";}'
            . '$iv=substr($p,0,16);'
            . '$c=substr($p,16);'
            . '$r=openssl_decrypt($c,"aes-256-cbc",$kh,OPENSSL_RAW_DATA,$iv);'
            . 'return $r!==false?$r:"";'
            . '}}';
    }

    /**
     * 构建垃圾代码分发器（控制流平坦化）。
     */
    private function buildPhpJunkDispatcher(): string
    {
        $state = '$' . $this->mapIdentifier('jstate', '', 'abcdefghijklmnopqrstuvwxyz');
        $cases = [];
        $branches = mt_rand(3, 6);
        for ($i = 0; $i < $branches; $i++) {
            $var = '$' . $this->mapIdentifier('jv' . $i, '', 'abcdefghijklmnopqrstuvwxyz');
            $cases[] = 'case ' . $i . ':' . $var . '=' . mt_rand() . ';break;';
        }
        return $state . '=mt_rand(0,' . ($branches - 1) . ');'
            . 'switch(' . $state . '){' . implode('', $cases) . '}';
    }

    /**
     * 生成开发者可见的映射头注释。
     */
    private function buildMapHeaderComment(): string
    {
        $lines = ['/* === Hardening Map (developer only) ==='];
        $lines[] = ' * Language: php';
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
     * 解析 PHP 字符串字面量的转义序列，还原原始字符串。
     */
    private function unescapePhpString(string $s, string $quote): string
    {
        if ($quote === "'") {
            // 单引号仅处理 \\ 与 \'
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $s);
        }
        // 双引号处理常见转义
        $s = str_replace(
            ['\\\\', '\\"', '\n', '\t', '\r', '\$', '\0'],
            ['\\', '"', "\n", "\t", "\r", '$', "\0"],
            $s
        );
        // 处理八进制 \012 与十六进制 \x1f
        $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{1,2})/', fn($m) => chr(hexdec($m[1])), $s);
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', fn($m) => chr(octdec($m[1])), $s);
        return $s;
    }
}
