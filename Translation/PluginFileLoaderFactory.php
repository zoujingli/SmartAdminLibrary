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

use Hyperf\Contract\ConfigInterface;
use Hyperf\Support\Filesystem\Filesystem;
use Library\Support\PluginManifestRegistry;
use Psr\Container\ContainerInterface;

use function Hyperf\Support\make;

/**
 * 插件语言包加载器工厂。
 *
 * 默认读取 plugin.json 显式声明的 language_root，并允许通过 translation.paths 追加目录。
 * 未在清单声明 language_root 的插件不会自动加载语言包。
 */
final class PluginFileLoaderFactory
{
    public function __invoke(ContainerInterface $container): PluginFileLoader
    {
        $config = $container->get(ConfigInterface::class);
        $files = $container->get(Filesystem::class);
        $path = (string)$config->get('translation.path', syspath('storage/languages'));
        $paths = $this->languagePaths($config->get('translation.paths', []));

        return make(PluginFileLoader::class, compact('files', 'path', 'paths'));
    }

    /**
     * @return array<int, string>
     */
    private function languagePaths(mixed $configured): array
    {
        $paths = $this->scanPluginLanguagePaths();

        if (is_array($configured)) {
            foreach ($configured as $path) {
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function scanPluginLanguagePaths(): array
    {
        try {
            return PluginManifestRegistry::languagePaths();
        } catch (\Throwable) {
            // 语言包是可选能力；插件清单不可用时返回空列表，交由默认目录兜底。
            return [];
        }
    }
}
