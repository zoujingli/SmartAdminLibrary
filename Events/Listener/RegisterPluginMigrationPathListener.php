<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Events\Listener;

use Hyperf\Database\Migrations\Migrator;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Library\Support\PluginManifestRegistry;

#[Listener]
final class RegisterPluginMigrationPathListener implements ListenerInterface
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {}

    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    public function process(object $event): void
    {
        if (!$event instanceof BootApplication) {
            return;
        }

        foreach ($this->migrationPaths() as $path) {
            $this->migrator->path($path);
        }
    }

    /**
     * 迁移目录必须由 plugin.json 显式声明；未配置的插件不注册迁移资源。
     *
     * @return array<int, string>
     */
    private function migrationPaths(): array
    {
        try {
            return PluginManifestRegistry::migrationPaths();
        } catch (\Throwable) {
            // 迁移目录是开发期资源，清单异常时不阻断应用启动，保留默认 migrations 目录兜底。
            return [];
        }
    }
}
