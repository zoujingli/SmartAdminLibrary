<?php

declare(strict_types=1);

namespace Library\Support\PluginManager;

/**
 * 插件元数据读取器。
 *
 * 插件安装、打包、备份和恢复都只信任 composer.json 与 plugin.json 两份入口；版本号允许二选一声明，
 * 但两边同时声明时必须一致，避免 ZIP 文件、Composer 约束和后台插件信息出现版本漂移。
 */
final class PluginMetadata
{
    /** @var array<string,mixed> */
    public readonly array $composer;

    /** @var array<string,mixed> */
    public readonly array $manifest;

    /** @var array<int,string> */
    public readonly array $tables;

    /** @var array<int,string> */
    public readonly array $tablePrefixes;

    /** @var array<int,int> */
    public readonly array $menuIds;

    /** @var array<int,string> */
    public readonly array $menuCodes;

    private function __construct(
        public readonly string $directory,
        public readonly string $code,
        public readonly string $name,
        public readonly string $version,
        public readonly string $composerName,
        public readonly string $module,
        array $composer,
        array $manifest,
        array $tables,
        array $tablePrefixes,
        array $menuIds,
        array $menuCodes,
    ) {
        $this->composer = $composer;
        $this->manifest = $manifest;
        $this->tables = $tables;
        $this->tablePrefixes = $tablePrefixes;
        $this->menuIds = $menuIds;
        $this->menuCodes = $menuCodes;
    }

    public static function load(string $directory): self
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        if ($directory === '' || !is_dir($directory)) {
            throw new \InvalidArgumentException('插件目录不存在：' . $directory);
        }

        $composer = self::readJson($directory . '/composer.json', 'composer.json');
        $manifest = self::readJson($directory . '/plugin.json', 'plugin.json');
        $plugin = is_array($manifest['plugin'] ?? null) ? $manifest['plugin'] : [];

        $composerName = trim((string)($composer['name'] ?? ''));
        if ($composerName === '') {
            throw new \RuntimeException('插件 composer.json 必须声明 name。');
        }

        $pluginVersion = trim((string)($plugin['version'] ?? $manifest['version'] ?? ''));
        $composerVersion = trim((string)($composer['version'] ?? ''));
        if ($pluginVersion !== '' && $composerVersion !== '' && $pluginVersion !== $composerVersion) {
            throw new \RuntimeException(sprintf('插件版本不一致：plugin.json=%s, composer.json=%s。', $pluginVersion, $composerVersion));
        }
        $version = $pluginVersion !== '' ? $pluginVersion : $composerVersion;
        if ($version === '') {
            throw new \RuntimeException('插件必须在 plugin.json 的 plugin.version 或 composer.json 的 version 中声明版本。');
        }

        $code = trim((string)($plugin['code'] ?? $manifest['code'] ?? self::kebab(basename($directory))));
        if ($code === '') {
            throw new \RuntimeException('插件 plugin.code 不能为空。');
        }

        $tables = self::normalizeNames(is_array($plugin['tables'] ?? null) ? $plugin['tables'] : []);
        $prefixes = self::normalizePrefixes(is_array($plugin['table_prefixes'] ?? null) ? $plugin['table_prefixes'] : []);
        if ($tables === [] && $prefixes === []) {
            $prefixes[] = self::snake($code) . '_';
        }

        $menus = self::collectMenuRefs(is_array($manifest['apps'] ?? null) ? $manifest['apps'] : []);

        return new self(
            $directory,
            $code,
            (string)($plugin['name'] ?? $manifest['name'] ?? basename($directory)),
            $version,
            $composerName,
            self::resolveModuleName($composer, $composerName, $code, basename($directory)),
            $composer,
            $manifest,
            $tables,
            $prefixes,
            $menus['ids'],
            $menus['codes'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function readJson(string $path, string $label = 'JSON'): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('缺少插件 %s：%s', $label, $path));
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new \RuntimeException(sprintf('无法读取插件 %s：%s', $label, $path));
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('插件 %s 格式无效：%s', $label, $path));
        }

        return $data;
    }

    public static function snake(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?: $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?: '';

        return strtolower(trim($value, '_'));
    }

    public static function kebab(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?: $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?: '';

        return strtolower(trim($value, '-'));
    }

    public static function studly(string $value): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $value) ?: [];
        $name = '';
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '') {
                $name .= ucfirst($word);
            }
        }

        return $name !== '' ? $name : 'Plugin';
    }

    /**
     * @param array<string,mixed> $composer
     */
    private static function resolveModuleName(array $composer, string $composerName, string $code, string $fallback): string
    {
        $autoload = is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : [];
        foreach (array_keys($autoload) as $namespace) {
            $namespace = trim((string)$namespace, '\\');
            $parts = explode('\\', $namespace);
            if (count($parts) >= 2 && $parts[0] === 'Plugin') {
                return self::safeModule($parts[1]);
            }
        }

        $extraConfig = (string)($composer['extra']['hyperf']['config'] ?? '');
        if ($extraConfig !== '') {
            $parts = explode('\\', trim($extraConfig, '\\'));
            if (count($parts) >= 2 && $parts[0] === 'Plugin') {
                return self::safeModule($parts[1]);
            }
            if (count($parts) >= 1 && !in_array($parts[0], ['Library', 'System', 'Builder'], true)) {
                return self::safeModule($parts[0]);
            }
        }

        $package = basename(str_replace('\\', '/', $composerName));
        foreach (['smart_admin_plugin_', 'smart-admin-plugin-'] as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return self::studly(substr($package, strlen($prefix)));
            }
        }

        return self::safeModule($fallback !== '' ? $fallback : self::studly($code));
    }

    private static function safeModule(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9]/', '', $value) ?: '';
        if ($value === '') {
            throw new \RuntimeException('无法解析插件目录名称。');
        }

        return ucfirst($value);
    }

    /**
     * @param array<int|string,mixed> $names
     * @return array<int,string>
     */
    private static function normalizeNames(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $name = strtolower(trim((string)$name));
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+$/', $name)) {
                throw new \RuntimeException('插件表名只能包含小写字母、数字和下划线：' . $name);
            }
            $result[$name] = $name;
        }

        return array_values($result);
    }

    /**
     * @param array<int|string,mixed> $prefixes
     * @return array<int,string>
     */
    private static function normalizePrefixes(array $prefixes): array
    {
        $result = [];
        foreach ($prefixes as $prefix) {
            $prefix = strtolower(trim((string)$prefix));
            if ($prefix === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+$/', $prefix)) {
                throw new \RuntimeException('插件表前缀只能包含小写字母、数字和下划线：' . $prefix);
            }
            $result[$prefix] = $prefix;
        }

        return array_values($result);
    }

    /**
     * @param array<int,mixed> $items
     * @return array{ids:array<int,int>,codes:array<int,string>}
     */
    private static function collectMenuRefs(array $items): array
    {
        $ids = [];
        $codes = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
            $code = trim((string)($item['code'] ?? ''));
            if ($code !== '') {
                $codes[$code] = $code;
            }
            foreach (['menus', 'children', 'permissions'] as $key) {
                $children = is_array($item[$key] ?? null) ? $item[$key] : [];
                $nested = self::collectMenuRefs($children);
                foreach ($nested['ids'] as $childId) {
                    $ids[$childId] = $childId;
                }
                foreach ($nested['codes'] as $childCode) {
                    $codes[$childCode] = $childCode;
                }
            }
        }

        return [
            'ids' => array_values($ids),
            'codes' => array_values($codes),
        ];
    }
}
