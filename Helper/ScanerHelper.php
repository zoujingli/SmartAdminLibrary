<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Helper;

/**
 * 文件扫描与操作工具类.
 *
 * 提供目录扫描、文件查找、复制和删除等功能。
 */
final class ScanerHelper
{
    /**
     * 扫描目录下的文件列表，并可按扩展名过滤.
     *
     * @param string $path 扫描目录路径
     * @param null|int $depth 扫描深度，null 表示无限制
     * @param string $ext 文件扩展名筛选，空字符串表示不过滤
     * @param bool $short 是否返回相对路径，否则返回绝对路径
     * @return array 文件路径数组
     */
    public static function scan(string $path, ?int $depth = null, string $ext = '', bool $short = true): array
    {
        return self::find($path, $depth, fn (\SplFileInfo $info) => $info->isDir() || $ext === '' || strtolower($info->getExtension()) === strtolower($ext), $short);
    }

    /**
     * 扫描目录并返回文件路径数组，可自定义过滤逻辑.
     *
     * @param string $path 扫描目录路径
     * @param null|int $depth 扫描深度
     * @param null|\Closure $filter 文件过滤闭包，返回 false 表示忽略该文件
     * @param bool $short 是否返回相对路径
     * @return array 包含文件路径的数组
     */
    public static function find(string $path, ?int $depth = null, ?\Closure $filter = null, bool $short = true): array
    {
        $info = new \SplFileInfo($path);
        $files = [];

        if ($info->isFile() && ($filter === null || $filter($info) !== false)) {
            $files[] = $short ? $info->getBasename() : $info->getPathname();
        }

        if ($info->isDir()) {
            foreach (self::findFilesYield($info->getPathname(), $depth, $filter) as $file) {
                $files[] = $short ? substr($file->getPathname(), strlen($info->getPathname()) + 1) : $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * 深度拷贝文件到指定目录.
     *
     * @param string $frdir 来源目录
     * @param string $todir 目标目录
     * @param array $files 指定文件列表，为空表示扫描全部文件
     * @param bool $force 是否覆盖已有文件
     * @param bool $remove 是否删除源文件及目录
     * @return bool 操作是否成功
     */
    public static function copy(string $frdir, string $todir, array $files = [], bool $force = true, bool $remove = true): bool
    {
        $frdir = rtrim($frdir, '\/') . DIRECTORY_SEPARATOR;
        $todir = rtrim($todir, '\/') . DIRECTORY_SEPARATOR;

        if (empty($files) && is_dir($frdir)) {
            $files = self::find($frdir, null, fn (\SplFileInfo $info) => $info->getBasename()[0] !== '.');
        }

        foreach ($files as $target) {
            $fromPath = $frdir . $target;
            $destPath = $todir . $target;
            if ($force || !is_file($destPath)) {
                is_dir($dir = dirname($destPath)) || mkdir($dir, 0777, true);
                copy($fromPath, $destPath);
            }
            $remove && unlink($fromPath);
        }

        $remove && self::remove($frdir);
        return true;
    }

    /**
     * 移除指定目录或文件（递归清空目录）.
     *
     * @param string $path 文件或目录路径
     * @return bool 是否成功删除
     */
    public static function remove(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        if (is_file($path)) {
            return unlink($path);
        }

        $dirs = [$path];

        // 删除目录下文件
        iterator_to_array(self::findFilesYield($path, null, function (\SplFileInfo $file) use (&$dirs) {
            $file->isDir() ? $dirs[] = $file->getPathname() : unlink($file->getPathname());
        }));

        // 按路径长度从长到短删除目录，确保子目录先删除
        usort($dirs, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($dirs as $dir) {
            is_dir($dir) && rmdir($dir);
        }

        return !file_exists($path);
    }

    /**
     * 递归扫描目录，返回 SplFileInfo 对象生成器.
     *
     * @param string $path 目录路径
     * @param null|int $depth 扫描深度
     * @param null|\Closure $filter 文件过滤闭包，返回 false 表示忽略
     * @param bool $appendPath 是否包含目录本身
     * @param int $currDepth 当前深度（递归内部使用）
     * @return \Generator<\SplFileInfo> 文件或目录的生成器
     */
    public static function findFilesYield(string $path, ?int $depth = null, ?\Closure $filter = null, bool $appendPath = false, int $currDepth = 1): \Generator
    {
        if (!file_exists($path) || !is_dir($path) || (!is_null($depth) && $currDepth > $depth)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($filter !== null && $filter($item) === false) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                $appendPath && yield $item;
                yield from self::findFilesYield($item->getPathname(), $depth, $filter, $appendPath, $currDepth + 1);
            } else {
                yield $item;
            }
        }
    }
}
