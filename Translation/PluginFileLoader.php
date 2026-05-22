<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Translation;

use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\Translation\FileLoader;

/**
 * 插件语言包加载器。
 *
 * Hyperf 默认只读取一个语言目录；项目插件化后，各插件需要把自己的语言包放在
 * plugin/<Module>/stc/languages 下。本加载器在普通 group 读取时合并所有插件语言目录，
 * 并保留 storage/languages 作为项目级覆盖目录，避免插件业务文案继续堆到公共目录。
 */
final class PluginFileLoader extends FileLoader
{
    /**
     * @param array<int, string> $paths 插件语言包目录列表，目录结构为 <path>/<locale>/<group>.php。
     */
    public function __construct(Filesystem $files, string $path, private array $paths = [])
    {
        parent::__construct($files, $path);

        $this->paths = $this->normalizePaths($paths);
    }

    /**
     * 加载指定语言组。
     *
     * 普通 group 从插件语言目录合并读取；命名空间和 JSON 翻译保持 Hyperf 原生行为。
     */
    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        if ($group === '*' && $namespace === '*') {
            return parent::load($locale, $group, $namespace);
        }

        if ($namespace === null || $namespace === '*') {
            return $this->loadMergedPaths($locale, $group);
        }

        return parent::load($locale, $group, $namespace);
    }

    /**
     * 读取所有插件语言包后，再读取 storage/languages 覆盖项。
     *
     * 同名 key 后加载的目录会覆盖先加载目录；插件之间应使用独立 group 避免互相覆盖。
     *
     * @return array<string, mixed>
     */
    private function loadMergedPaths(string $locale, string $group): array
    {
        $lines = [];
        foreach ($this->paths as $path) {
            $lines = array_replace_recursive($lines, $this->loadPath($path, $locale, $group));
        }

        return array_replace_recursive($lines, $this->loadPath($this->path, $locale, $group));
    }

    /**
     * @param array<int, mixed> $paths
     * @return array<int, string>
     */
    private function normalizePaths(array $paths): array
    {
        $basePath = $this->normalizePath($this->path);
        $normalized = [];

        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }

            $path = $this->normalizePath($path);
            if ($path === '' || $path === $basePath || !is_dir($path)) {
                continue;
            }

            $normalized[$path] = $path;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
