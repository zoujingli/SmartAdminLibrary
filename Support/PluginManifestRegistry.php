<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Support;

use Library\Constants\MenuType;
use Library\Constants\Status;

use function syspath;

/**
 * 应用插件清单注册表。
 *
 * `plugin.json` 是应用插件业务元数据的唯一推荐入口，负责声明菜单、路由组件、按钮权限、模块摘要与资源目录；
 * `plugin.view_root`、`plugin.language_root`、`plugin.migration_root` 均为显式启用项，未配置时对应资源不生效。
 * Composer 只负责本地 path 包 autoload 与 Provider 注册；源码/CI 插件分发由 Library 的 xadmin:plugin:* 命令维护 ZIP、依赖和备份，不把业务菜单或前端资源塞进 Composer 元数据。
 */
final class PluginManifestRegistry
{
    private const MANIFEST_FILE = 'plugin.json';

    /**
     * @var null|array<int, array<string, mixed>>
     */
    private static ?array $manifests = null;

    /**
     * @return array<int, array{
     *   plugin:string,
     *   code:string,
     *   name:string,
     *   version:string,
     *   description:string,
     *   install_path:string,
     *   manifest_path:string,
     *   view_root:string,
     *   language_root:string,
     *   migration_root:string,
     *   apps:array<int, array<string, mixed>>,
     *   module_roots:array<int, int>,
     *   module_catalog:array<string, array{key:string,summary:string,features:array<int, string>}>
     * }>
     */
    public static function manifests(): array
    {
        if (self::$manifests !== null) {
            return self::$manifests;
        }

        $items = [];
        foreach (self::manifestFiles() as $file) {
            $items[] = self::normalizeManifest($file);
        }

        usort($items, static fn (array $left, array $right): int => strcmp((string)$left['install_path'], (string)$right['install_path']));

        return self::$manifests = $items;
    }

    /**
     * @return array<int, string>
     */
    public static function languagePaths(): array
    {
        return self::configuredResourcePaths('language_root');
    }

    /**
     * @return array<int, string>
     */
    public static function migrationPaths(): array
    {
        return self::configuredResourcePaths('migration_root');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function menuRows(int $userId, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $rows = [];

        foreach (self::manifests() as $manifest) {
            foreach ($manifest['apps'] as $app) {
                self::appendMenuRows($rows, $manifest, $app, 0, $userId, $now);
            }
        }

        self::assertUniqueRows($rows, 'plugin.json');

        usort($rows, static fn (array $left, array $right): int => [(int)($left['sort'] ?? 0), (int)($left['id'] ?? 0)] <=> [(int)($right['sort'] ?? 0), (int)($right['id'] ?? 0)]);

        return $rows;
    }

    /**
     * @return array<int, int>
     */
    public static function moduleRootIds(): array
    {
        $ids = [];
        foreach (self::manifests() as $manifest) {
            foreach ($manifest['module_roots'] as $id) {
                $ids[] = (int)$id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @return array<string, array{key:string,summary:string,features:array<int, string>}>
     */
    public static function moduleCatalog(): array
    {
        $catalog = [];
        foreach (self::manifests() as $manifest) {
            foreach ($manifest['module_catalog'] as $code => $meta) {
                $catalog[$code] = $meta;
            }
        }

        return $catalog;
    }

    /**
     * @return array{exists:bool,path:string,hash:string,app_count:int,menu_count:int,view_root:string,language_root:string,migration_root:string}
     */
    public static function manifestSummary(string $installPath): array
    {
        $path = self::rootPath($installPath . '/' . self::MANIFEST_FILE);
        if (!is_file($path)) {
            return [
                'exists' => false,
                'path' => self::normalizeRelativePath($installPath . '/' . self::MANIFEST_FILE),
                'hash' => '',
                'app_count' => 0,
                'menu_count' => 0,
                'view_root' => '',
                'language_root' => '',
                'migration_root' => '',
            ];
        }

        $manifest = self::normalizeManifest($path);
        $menuCount = 0;
        foreach ($manifest['apps'] as $app) {
            $menuCount += 1 + self::countChildren($app);
        }

        return [
            'exists' => true,
            'path' => self::relativePath($path),
            'hash' => 'sha256:' . hash_file('sha256', $path),
            'app_count' => count($manifest['apps']),
            'menu_count' => $menuCount,
            'view_root' => (string)$manifest['view_root'],
            'language_root' => (string)$manifest['language_root'],
            'migration_root' => (string)$manifest['migration_root'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public static function assertUniqueRows(array $rows, string $source): void
    {
        $ids = [];
        $codes = [];
        $routes = [];

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException(sprintf('%s 存在无效菜单 ID。', $source));
            }

            if (isset($ids[$id])) {
                throw new \RuntimeException(sprintf('%s 存在重复菜单 ID：%d。', $source, $id));
            }
            $ids[$id] = true;

            $code = (string)($row['code'] ?? '');
            if ($code !== '') {
                if (isset($codes[$code])) {
                    throw new \RuntimeException(sprintf('%s 存在重复权限编码：%s。', $source, $code));
                }
                $codes[$code] = true;
            }

            $route = (string)($row['route'] ?? '');
            if ($route !== '') {
                if (isset($routes[$route])) {
                    throw new \RuntimeException(sprintf('%s 存在重复路由：%s。', $source, $route));
                }
                $routes[$route] = true;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private static function manifestFiles(): array
    {
        $pluginRoot = self::rootPath('plugin');
        if (!is_dir($pluginRoot)) {
            return [];
        }

        $files = [];
        foreach (new \DirectoryIterator($pluginRoot) as $plugin) {
            if ($plugin->isDot() || !$plugin->isDir()) {
                continue;
            }

            $file = $plugin->getPathname() . '/' . self::MANIFEST_FILE;
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeManifest(string $file): array
    {
        $data = self::readJson($file);
        $pluginDir = dirname($file);
        $installPath = self::relativePath($pluginDir);
        $plugin = basename($pluginDir);
        $pluginMeta = is_array($data['plugin'] ?? null) ? $data['plugin'] : [];
        $viewRoot = self::normalizeOptionalResourcePath($pluginMeta, $file, 'view_root');
        $languageRoot = self::normalizeOptionalResourcePath($pluginMeta, $file, 'language_root');
        $migrationRoot = self::normalizeOptionalResourcePath($pluginMeta, $file, 'migration_root');

        $apps = [];
        $moduleRoots = [];
        $moduleCatalog = [];
        foreach (self::arrayList($data['apps'] ?? []) as $index => $app) {
            $normalized = self::normalizeMenuItem($app, $file, sprintf('apps[%d]', $index), true);
            $apps[] = $normalized;
            if (($normalized['module_root'] ?? true) !== false) {
                $moduleRoots[] = (int)$normalized['id'];
            }
            self::collectModuleCatalog($moduleCatalog, $normalized);
        }

        $manifest = [
            'plugin' => $plugin,
            'code' => (string)($pluginMeta['code'] ?? $data['code'] ?? self::kebab($plugin)),
            'name' => (string)($pluginMeta['name'] ?? $data['name'] ?? $plugin),
            'version' => (string)($pluginMeta['version'] ?? $data['version'] ?? ''),
            'description' => (string)($pluginMeta['description'] ?? $data['description'] ?? ''),
            'install_path' => $installPath,
            'manifest_path' => self::relativePath($file),
            'view_root' => $viewRoot,
            'language_root' => $languageRoot,
            'migration_root' => $migrationRoot,
            'apps' => $apps,
            'module_roots' => array_values(array_unique(array_filter($moduleRoots))),
            'module_catalog' => $moduleCatalog,
        ];

        // 单个清单在插件列表/锁文件同步时也要完成菜单唯一性与 view 文件存在性校验。
        $rows = [];
        foreach ($manifest['apps'] as $app) {
            self::appendMenuRows($rows, $manifest, $app, 0, 0, '1970-01-01 00:00:00');
        }
        self::assertUniqueRows($rows, (string)$manifest['manifest_path']);

        return $manifest;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function normalizeMenuItem(array $item, string $file, string $path, bool $isApp = false): array
    {
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException(sprintf('%s 的 %s.id 必须为正整数。', self::relativePath($file), $path));
        }

        $type = MenuType::normalize((string)($item['type'] ?? ($isApp ? MenuType::PATH : MenuType::MENU)));
        $code = (string)($item['code'] ?? '');
        $name = (string)($item['name'] ?? '');
        $route = (string)($item['route'] ?? '');
        if ($name === '') {
            throw new \RuntimeException(sprintf('%s 的 %s.name 不能为空。', self::relativePath($file), $path));
        }

        if ($type !== MenuType::BUTTON && $route === '') {
            throw new \RuntimeException(sprintf('%s 的 %s.route 不能为空。', self::relativePath($file), $path));
        }

        $normalized = [
            'id' => $id,
            'pid' => (int)($item['pid'] ?? 0),
            'name' => $name,
            'code' => $code,
            'icon' => (string)($item['icon'] ?? ''),
            'type' => $type,
            'route' => $route,
            'component' => (string)($item['component'] ?? ''),
            'view' => self::normalizeView((string)($item['view'] ?? ''), $file, $path),
            'redirect' => (string)($item['redirect'] ?? ''),
            'link' => (string)($item['link'] ?? ''),
            'iframe_src' => (string)($item['iframe_src'] ?? ''),
            'hide_in_menu' => (int)($item['hide_in_menu'] ?? ($type === MenuType::BUTTON ? 1 : 0)),
            'hide_in_breadcrumb' => (int)($item['hide_in_breadcrumb'] ?? 0),
            'hide_in_tab' => (int)($item['hide_in_tab'] ?? 0),
            'keep_alive' => (int)($item['keep_alive'] ?? 0),
            'affix_tab' => (int)($item['affix_tab'] ?? 0),
            'sort' => (int)($item['sort'] ?? 0),
            'status' => (int)($item['status'] ?? Status::ENABLED),
            'remark' => (string)($item['remark'] ?? ($type === MenuType::BUTTON ? '按钮权限节点' : '')),
            'module_root' => (bool)($item['module_root'] ?? true),
            'module' => is_array($item['module'] ?? null) ? $item['module'] : [],
            'menus' => [],
            'permissions' => [],
        ];

        foreach (self::arrayList($item['menus'] ?? $item['children'] ?? []) as $index => $child) {
            $normalized['menus'][] = self::normalizeMenuItem($child, $file, sprintf('%s.menus[%d]', $path, $index));
        }

        foreach (self::arrayList($item['permissions'] ?? []) as $index => $permission) {
            $normalized['permissions'][] = self::normalizePermission($permission, $file, sprintf('%s.permissions[%d]', $path, $index));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function normalizePermission(array $item, string $file, string $path): array
    {
        $item['type'] = MenuType::BUTTON;
        $item['route'] = '';
        $item['component'] = '';
        $item['view'] = '';
        $item['hide_in_menu'] = 1;
        $item['remark'] = (string)($item['remark'] ?? '按钮权限节点');

        return self::normalizeMenuItem($item, $file, $path);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $item
     */
    private static function appendMenuRows(array &$rows, array $manifest, array $item, int $parentId, int $userId, string $now): void
    {
        $id = (int)$item['id'];
        $component = (string)$item['component'];
        if ((string)$item['view'] !== '') {
            self::assertViewFile($manifest, (string)$item['view']);
            $component = sprintf('@plugin/%s/views/%s', (string)$manifest['plugin'], (string)$item['view']);
        }

        $rows[] = [
            'id' => $id,
            'pid' => $parentId > 0 ? $parentId : (int)($item['pid'] ?? 0),
            'level' => '',
            'name' => (string)$item['name'],
            'code' => (string)$item['code'],
            'icon' => (string)$item['icon'],
            'type' => (string)$item['type'],
            'route' => (string)$item['route'],
            'component' => $component,
            'redirect' => (string)$item['redirect'],
            'link' => (string)$item['link'],
            'iframe_src' => (string)$item['iframe_src'],
            'hide_in_menu' => (int)$item['hide_in_menu'],
            'hide_in_breadcrumb' => (int)$item['hide_in_breadcrumb'],
            'hide_in_tab' => (int)$item['hide_in_tab'],
            'keep_alive' => (int)$item['keep_alive'],
            'affix_tab' => (int)$item['affix_tab'],
            'sort' => (int)$item['sort'],
            'status' => (int)$item['status'],
            'remark' => (string)$item['remark'],
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        foreach ($item['menus'] as $child) {
            self::appendMenuRows($rows, $manifest, $child, $id, $userId, $now);
        }

        foreach ($item['permissions'] as $permission) {
            self::appendMenuRows($rows, $manifest, $permission, $id, $userId, $now);
        }
    }

    /**
     * @param array<string, array{key:string,summary:string,features:array<int, string>}> $catalog
     * @param array<string, mixed> $item
     */
    private static function collectModuleCatalog(array &$catalog, array $item): void
    {
        $code = (string)($item['code'] ?? '');
        $module = is_array($item['module'] ?? null) ? $item['module'] : [];
        if ($code !== '' && $module !== []) {
            $key = (string)($module['key'] ?? '');
            $summary = (string)($module['summary'] ?? '');
            if ($key !== '' && $summary !== '') {
                $catalog[$code] = [
                    'key' => $key,
                    'summary' => $summary,
                    'features' => array_values(array_map('strval', is_array($module['features'] ?? null) ? $module['features'] : [])),
                ];
            }
        }

        foreach ($item['menus'] as $child) {
            self::collectModuleCatalog($catalog, $child);
        }
    }

    private static function normalizeView(string $view, string $file, string $path): string
    {
        $view = trim($view);
        if ($view === '') {
            return '';
        }

        $view = self::normalizeManifestPath($view, $file, $path . '.view');

        if (!str_ends_with($view, '.vue')) {
            throw new \RuntimeException(sprintf('%s 的 %s.view 必须指向 .vue 文件。', self::relativePath($file), $path));
        }

        return $view;
    }

    private static function normalizeManifestPath(string $path, string $file, string $field): string
    {
        $raw = trim($path);
        if ($raw === '') {
            throw new \RuntimeException(sprintf('%s 的 %s 不能为空。', self::relativePath($file), $field));
        }

        if (str_starts_with($raw, '/') || preg_match('/^[A-Za-z]:[\/\\\]/', $raw) === 1) {
            throw new \RuntimeException(sprintf('%s 的 %s 必须是插件目录内的相对路径。', self::relativePath($file), $field));
        }

        $normalized = self::normalizeRelativePath($raw);
        if ($normalized === '') {
            throw new \RuntimeException(sprintf('%s 的 %s 不能为空。', self::relativePath($file), $field));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $pluginMeta
     */
    private static function normalizeOptionalResourcePath(array $pluginMeta, string $file, string $field): string
    {
        $value = $pluginMeta[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        return self::normalizeManifestPath($value, $file, 'plugin.' . $field);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function assertViewFile(array $manifest, string $view): void
    {
        $viewRoot = (string)$manifest['view_root'];
        if ($viewRoot === '') {
            throw new \RuntimeException(sprintf('%s 声明了 view 文件，但未配置 plugin.view_root。', (string)$manifest['manifest_path']));
        }

        $file = self::rootPath(sprintf('%s/%s/%s', (string)$manifest['install_path'], (string)$manifest['view_root'], $view));
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('%s 声明的 view 文件不存在：%s。', (string)$manifest['manifest_path'], $view));
        }
    }

    /**
     * @return array<int, string>
     */
    private static function configuredResourcePaths(string $field): array
    {
        $paths = [];
        foreach (self::manifests() as $manifest) {
            $root = (string)($manifest[$field] ?? '');
            if ($root === '') {
                continue;
            }

            $path = self::rootPath(sprintf('%s/%s', (string)$manifest['install_path'], $root));
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function arrayList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function countChildren(array $item): int
    {
        $count = count($item['permissions'] ?? []);
        foreach ($item['menus'] ?? [] as $child) {
            if (is_array($child)) {
                $count += 1 + self::countChildren($child);
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        $content = file_get_contents($file);
        if (!is_string($content)) {
            throw new \RuntimeException('无法读取插件清单：' . self::relativePath($file));
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException('插件清单 JSON 格式无效：' . self::relativePath($file));
        }

        return $data;
    }

    private static function rootPath(string $path = ''): string
    {
        $root = rtrim(\syspath(), '/\\');

        return $path === '' ? $root : $root . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    private static function relativePath(string $path): string
    {
        $root = str_replace('\\', '/', self::rootPath());
        $normalized = self::normalizeAbsolutePath($path);
        if (str_starts_with($normalized, $root . '/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return self::normalizeRelativePath($path);
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $scheme = '';
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*://)(.*)$#', $path, $matches) === 1) {
            // Phar 打包后清单路径形如 phar:///path/app.bin/plugin/xx/plugin.json。
            // 归一化时必须保留 stream scheme 的双斜杠，否则会被压成 phar:/path，
            // 后续 install_path 无法相对 syspath() 截断，导致包内 view/language/migration 资源误判不存在。
            $scheme = $matches[1];
            $path = $matches[2];
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        $prefix = $scheme !== '' || str_starts_with($path, '/') ? '/' : '';

        return $scheme . $prefix . implode('/', $parts);
    }

    private static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?: '';
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new \RuntimeException('插件清单路径不允许包含上级目录：' . $path);
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private static function kebab(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?: $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?: '';

        return strtolower(trim($value, '-'));
    }
}
