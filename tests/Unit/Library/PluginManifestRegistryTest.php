<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library;

use Library\Support\PluginManifestRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PluginManifestRegistry::class)]
final class PluginManifestRegistryTest extends TestCase
{
    public function testSortMenuRowsUsesSystemMenuOrdering(): void
    {
        $rows = [
            ['id' => 110, 'pid' => 20, 'sort' => 10, 'name' => '子菜单-低排序'],
            ['id' => 15, 'pid' => 0, 'sort' => 20, 'name' => '根菜单-同排序大 ID'],
            ['id' => 100, 'pid' => 20, 'sort' => 30, 'name' => '子菜单-同排序大 ID'],
            ['id' => 20, 'pid' => 0, 'sort' => 80, 'name' => '根菜单-高排序'],
            ['id' => 90, 'pid' => 20, 'sort' => 30, 'name' => '子菜单-同排序小 ID'],
            ['id' => 10, 'pid' => 0, 'sort' => 20, 'name' => '根菜单-同排序小 ID'],
        ];

        $sorted = PluginManifestRegistry::sortMenuRows($rows);

        self::assertSame([20, 90, 100, 10, 15, 110], array_column($sorted, 'id'));
        self::assertSame([20, 10, 15], $this->idsByPid($sorted, 0));
        self::assertSame([90, 100, 110], $this->idsByPid($sorted, 20));
    }

    public function testGuideEntriesReadPublicPluginEntrypoints(): void
    {
        // SmartAdminLibrary 独立导出仓不包含 Project/Asset/Points 业务插件目录，
        // 这里用清单 fixture 验证排序和安全展示字段，避免同步验证依赖 Developer 私有仓目录结构。
        $this->withPluginManifests([
            $this->guideManifest('Project', 'project', '/project/portal', '/project/login', 30),
            $this->guideManifest('Asset', 'asset', '/asset/self', '/asset/login', 20),
            $this->guideManifest('Points', 'points', '/points/portal', '/points/dingtalk/entry', 10),
        ], function (): void {
            $entries = PluginManifestRegistry::guideEntries();
            $byCode = [];
            foreach ($entries as $entry) {
                $byCode[(string)$entry['code']] = $entry;
            }

            self::assertSame(['project', 'asset', 'points'], array_slice(array_column($entries, 'code'), 0, 3));
            self::assertSame('/project/portal', $byCode['project']['home_path'] ?? null);
            self::assertSame('/project/login', $byCode['project']['login_path'] ?? null);
            self::assertSame('/asset/self', $byCode['asset']['home_path'] ?? null);
            self::assertSame('/asset/login', $byCode['asset']['login_path'] ?? null);
            self::assertSame('/points/portal', $byCode['points']['home_path'] ?? null);
            self::assertSame('/points/dingtalk/entry', $byCode['points']['login_path'] ?? null);
            self::assertTrue((bool)($byCode['project']['enabled'] ?? false));
        });
    }

    public function testGuideEntryNormalizesDefaultsAndClientPaths(): void
    {
        $entry = $this->normalizeGuideEntry([
            'name' => '测试插件',
            'description' => '默认描述',
            'guide_entry' => [
                'name' => ' 员工入口 ',
                'home_path' => '/demo/home/?from=guide#hash',
                'login_path' => ' demo/login/ ',
                'sort' => '8',
            ],
        ]);

        self::assertSame('员工入口', $entry['name']);
        self::assertSame('默认描述', $entry['description']);
        self::assertSame('/demo/home', $entry['home_path']);
        self::assertSame('/demo/login', $entry['login_path']);
        self::assertSame('lucide:blocks', $entry['icon']);
        self::assertSame(8, $entry['sort']);
        self::assertTrue($entry['enabled']);
    }

    public function testGuideEntryRejectsRootOrTraversalPaths(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plugin.guide_entry.home_path 必须是有效前端业务路由');

        $this->normalizeGuideEntry([
            'name' => '测试插件',
            'guide_entry' => [
                'name' => '入口',
                'home_path' => '/../../',
            ],
        ]);
    }

    public function testGuideEntryRejectsExternalClientPaths(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plugin.guide_entry.home_path 必须是前端业务路由，不能是外部地址');

        $this->normalizeGuideEntry([
            'name' => '测试插件',
            'guide_entry' => [
                'name' => '入口',
                'home_path' => 'https://example.com/project',
            ],
        ]);
    }

    public function testGuideEntryRejectsBooleanClientPaths(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plugin.guide_entry.home_path 必须是前端路由字符串');

        $this->normalizeGuideEntry([
            'name' => '测试插件',
            'guide_entry' => [
                'name' => '入口',
                'home_path' => true,
            ],
        ]);
    }

    public function testGuideEntryAllowsMissingOptionalLoginPath(): void
    {
        $entry = $this->normalizeGuideEntry([
            'name' => '测试插件',
            'guide_entry' => [
                'name' => '入口',
                'home_path' => '/demo/home',
            ],
        ]);

        self::assertSame('', $entry['login_path']);
    }

    public function testGuideEntryRejectsInvalidOptionalLoginPathType(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plugin.guide_entry.login_path 必须是前端路由字符串');

        $this->normalizeGuideEntry([
            'name' => '测试插件',
            'guide_entry' => [
                'name' => '入口',
                'home_path' => '/demo/home',
                'login_path' => 123,
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function idsByPid(array $rows, int $pid): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int)$row['id'],
            array_filter($rows, static fn (array $row): bool => (int)($row['pid'] ?? 0) === $pid)
        ));
    }

    /**
     * @param array<string, mixed> $pluginMeta
     * @return array<string, mixed>
     */
    private function normalizeGuideEntry(array $pluginMeta): array
    {
        $method = new \ReflectionMethod(PluginManifestRegistry::class, 'normalizeGuideEntry');
        $method->setAccessible(true);

        return $method->invoke(null, $pluginMeta, syspath('plugin/Test/plugin.json'));
    }

    /**
     * @return array{plugin:string,code:string,guide_entry:array<string, mixed>}
     */
    private function guideManifest(string $plugin, string $code, string $homePath, string $loginPath, int $sort): array
    {
        return [
            'plugin' => $plugin,
            'code' => $code,
            'guide_entry' => [
                'name' => $plugin,
                'description' => $plugin . ' 公开入口',
                'icon' => 'lucide:blocks',
                'home_path' => $homePath,
                'login_path' => $loginPath,
                'sort' => $sort,
                'enabled' => true,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $manifests
     */
    private function withPluginManifests(array $manifests, \Closure $callback): void
    {
        $property = new \ReflectionProperty(PluginManifestRegistry::class, 'manifests');
        $original = $property->getValue();
        $property->setValue(null, $manifests);

        try {
            $callback();
        } finally {
            $property->setValue(null, $original);
        }
    }
}
