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

/**
 * 聚合各插件声明的菜单基线。
 */
final class MenuSeedRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rows(int $userId, ?string $now = null): array
    {
        // plugin.json 是应用插件菜单的唯一业务声明来源，构建、同步和release 安装包都读取同一份清单。
        $rows = PluginManifestRegistry::menuRows($userId, $now);

        self::assertUniqueRows($rows);

        return PluginManifestRegistry::sortMenuRows($rows);
    }

    /**
     * @return array<int, int>
     */
    public static function ids(): array
    {
        return array_map('intval', array_column(self::rows(0, '1970-01-01 00:00:00'), 'id'));
    }

    /**
     * 菜单 ID、权限编码和路由必须全局唯一，避免不同插件清单产生不确定覆盖。
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private static function assertUniqueRows(array $rows): void
    {
        try {
            PluginManifestRegistry::assertUniqueRows($rows, '菜单基线');
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException($exception->getMessage() . ' 请检查 plugin.json 是否重复声明。', 0, $exception);
        }
    }
}
