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

use function Hyperf\Config\config;

/**
 * 聚合各插件声明的模块能力元数据。
 */
final class ModuleRegistry
{
    /**
     * @return array<int, array{key:string,name:string,description:string}>
     */
    public static function commonCapabilities(): array
    {
        $configured = self::config('xadmin.common_capabilities', []);
        if (!is_array($configured)) {
            return [];
        }

        $items = [];
        foreach ($configured as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $capabilityKey = (string)($item['key'] ?? (is_string($key) ? $key : ''));
            $name = (string)($item['name'] ?? '');
            $description = (string)($item['description'] ?? '');
            if ($capabilityKey === '' || $name === '') {
                continue;
            }

            $items[$capabilityKey] = [
                'key' => $capabilityKey,
                'name' => $name,
                'description' => $description,
            ];
        }

        return array_values($items);
    }

    /**
     * @return array<int, array{
     *   key:string,
     *   name:string,
     *   code:string,
     *   path:string,
     *   icon:string,
     *   summary:string,
     *   features:array<int, string>,
     *   page_count:int,
     *   action_count:int,
     *   hidden_page_count:int
     * }>
     */
    public static function modules(): array
    {
        $rows = MenuSeedRegistry::rows(0, '1970-01-01 00:00:00');
        $rootIds = self::moduleRootIds();
        if ($rootIds === []) {
            return [];
        }

        $catalog = self::moduleCatalog();
        $modules = [];

        foreach ($rows as $row) {
            if (!in_array((int)($row['pid'] ?? 0), $rootIds, true) || (string)($row['type'] ?? '') === MenuType::BUTTON) {
                continue;
            }

            $code = (string)($row['code'] ?? '');
            $meta = $catalog[$code] ?? [
                'key' => trim(str_replace(['system.', 'tenant.', '.index'], '', $code), '.'),
                'summary' => '后台业务模块。',
                'features' => [],
            ];

            $descendants = self::collectDescendants((int)($row['id'] ?? 0), $rows);
            $pageCount = 1;
            $actionCount = 0;
            $hiddenPageCount = (int)(!empty($row['hide_in_menu']));

            foreach ($descendants as $descendant) {
                if ((string)($descendant['type'] ?? '') === MenuType::BUTTON) {
                    ++$actionCount;

                    continue;
                }

                ++$pageCount;
                if (!empty($descendant['hide_in_menu'])) {
                    ++$hiddenPageCount;
                }
            }

            $modules[] = [
                'key' => (string)$meta['key'],
                'name' => (string)($row['name'] ?? ''),
                'code' => $code,
                'path' => (string)($row['route'] ?? ''),
                'icon' => (string)($row['icon'] ?? ''),
                'summary' => (string)$meta['summary'],
                'features' => array_values(array_map('strval', $meta['features'] ?? [])),
                'page_count' => $pageCount,
                'action_count' => $actionCount,
                'hidden_page_count' => $hiddenPageCount,
                'sort' => (int)($row['sort'] ?? 0),
            ];
        }

        usort($modules, static fn (array $left, array $right): int => [$left['sort'], $left['path']] <=> [$right['sort'], $right['path']]);

        return array_map(static function (array $module): array {
            unset($module['sort']);

            return $module;
        }, $modules);
    }

    /**
     * @return array<int, int>
     */
    private static function moduleRootIds(): array
    {
        // 模块根只从 plugin.json 声明读取，保证模块概览、菜单同步和release 安装包使用同一份结构化清单。
        return PluginManifestRegistry::moduleRootIds();
    }

    /**
     * @return array<string, array{key:string,summary:string,features:array<int, string>}>
     */
    private static function moduleCatalog(): array
    {
        // 模块摘要只从 plugin.json 声明读取，避免 Provider 分散配置导致开源文档和运行时不一致。
        return PluginManifestRegistry::moduleCatalog();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function collectDescendants(int $parentId, array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            if ((int)($row['pid'] ?? 0) !== $parentId) {
                continue;
            }

            $items[] = $row;
            foreach (self::collectDescendants((int)($row['id'] ?? 0), $rows) as $descendant) {
                $items[] = $descendant;
            }
        }

        return $items;
    }

    private static function config(string $key, mixed $default): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
