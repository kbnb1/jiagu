<?php
declare(strict_types=1);

namespace app\common\hardener;

/**
 * 加固器接口
 *
 * 定义代码加固器必须实现的契约。每一种编程语言的加固器都必须实现该接口，
 * 由 HardenerFactory 负责根据语言名称创建对应的实例。
 */
interface HardenerInterface
{
    /**
     * 执行代码加固。
     *
     * @param string $code    待加固的源代码内容
     * @param array  $options 加固选项，例如：
     *                        - obfuscate_identifiers (bool) 是否混淆标识符
     *                        - encrypt_strings (bool) 是否加密字符串
     *                        - strip_comments (bool) 是否清除注释
     *                        - compress_whitespace (bool) 是否压缩空白
     *                        - flatten_control_flow (bool) 是否控制流平坦化
     *                        - insert_junk_code (bool) 是否注入垃圾代码
     *                        - anti_debug (bool) 是否插入反调试
     *                        - key (string) 自定义加密密钥
     * @return string 加固后的代码
     */
    public function harden(string $code, array $options = []): string;

    /**
     * 返回该加固器支持的源代码文件扩展名列表（不含点）。
     *
     * @return string[] 例如 ['php']
     */
    public function getSupportedExtensions(): array;

    /**
     * 返回该加固器处理的语言名称（小写英文）。
     *
     * @return string 例如 'php'
     */
    public function getLanguageName(): string;
}
