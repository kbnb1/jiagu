<?php
declare(strict_types=1);

namespace app\common\hardener;

use InvalidArgumentException;

/**
 * 加固器工厂
 *
 * 根据语言名称创建对应的加固器实例，并提供支持语言列表查询。
 */
class HardenerFactory
{
    /**
     * 语言 => 加固器类 映射。
     */
    private const LANGUAGE_MAP = [
        'php'         => PhpHardener::class,
        'php7'        => PhpHardener::class,
        'php8'        => PhpHardener::class,
        'java'        => JavaHardener::class,
        'javascript'  => JavaScriptHardener::class,
        'js'          => JavaScriptHardener::class,
        'python'      => PythonHardener::class,
        'py'          => PythonHardener::class,
        'c'           => CppHardener::class,
        'cpp'         => CppHardener::class,
        'c++'         => CppHardener::class,
        'c+++'        => CppHardener::class,
    ];

    /**
     * 已实例化的加固器缓存（按语言名）。
     *
     * @var array<string,HardenerInterface>
     */
    private static array $instances = [];

    /**
     * 创建加固器实例。
     *
     * @param string $language 语言名称（不区分大小写）
     * @return HardenerInterface
     * @throws InvalidArgumentException 当语言不支持时
     */
    public static function create(string $language): HardenerInterface
    {
        $key = strtolower(trim($language));
        if (!isset(self::LANGUAGE_MAP[$key])) {
            throw new InvalidArgumentException(
                'Unsupported hardening language: ' . $language
                . '. Supported: ' . implode(', ', self::getSupportedLanguages())
            );
        }

        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        $class = self::LANGUAGE_MAP[$key];
        $instance = new $class();
        self::$instances[$key] = $instance;
        return $instance;
    }

    /**
     * 返回支持的语言列表（去重后的规范名称）。
     *
     * @return string[]
     */
    public static function getSupportedLanguages(): array
    {
        // 去重，保留每个加固器的主语言名
        $seen = [];
        $result = [];
        foreach (self::LANGUAGE_MAP as $alias => $class) {
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;
            $result[] = $alias;
        }
        return $result;
    }

    /**
     * 根据文件扩展名推断语言并创建加固器。
     *
     * @param string $extension 文件扩展名（不含点，不区分大小写）
     * @return HardenerInterface
     * @throws InvalidArgumentException 当扩展名无法识别时
     */
    public static function createFromExtension(string $extension): HardenerInterface
    {
        $ext = strtolower(trim(ltrim($extension, '.')));
        foreach (self::LANGUAGE_MAP as $lang => $class) {
            $hardener = self::create($lang);
            if (in_array($ext, $hardener->getSupportedExtensions(), true)) {
                return $hardener;
            }
        }
        throw new InvalidArgumentException('Cannot determine hardener from extension: .' . $extension);
    }

    /**
     * 判断语言是否被支持。
     */
    public static function isSupported(string $language): bool
    {
        return isset(self::LANGUAGE_MAP[strtolower(trim($language))]);
    }
}
