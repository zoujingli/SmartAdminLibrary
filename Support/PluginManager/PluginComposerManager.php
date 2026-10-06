<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Support\PluginManager;

/**
 * 根 composer.json 的插件依赖维护器。
 *
 * 插件是本地 path package；安装/恢复只追加 path repository 与 require，移除只删除对应项，
 * 不创建额外安装器状态文件。
 */
final class PluginComposerManager
{
    public function __construct(private readonly string $root) {}

    /**
     * @return array{composer_path:string,package:string,constraint:string,repository_added:bool,require_added:bool}
     */
    public function addPathPackage(PluginMetadata $metadata, string $relativePath): array
    {
        $prepared = $this->preparePathPackage($metadata, $relativePath);
        $this->writeRootComposer($prepared['report']['composer_path'], $prepared['data']);

        return $prepared['report'];
    }

    /** 在独立清单/锁文件中求解候选包，禁止安装依赖、执行脚本或加载 Composer 插件。 */
    public function preflightPathPackage(PluginMetadata $metadata, string $relativePath): array
    {
        $prepared = $this->preparePathPackage($metadata, $relativePath);
        // 优先读取解压区的新包，不能让已安装的同版本 path 包掩盖新增依赖。
        array_unshift($prepared['data']['repositories'], [
            'type' => 'path',
            'url' => $metadata->directory,
            'options' => ['versions' => [$metadata->composerName => $metadata->version]],
        ]);
        $prefix = $this->root . '/.plugin-preflight-' . bin2hex(random_bytes(12));
        $manifest = $prefix . '.json';
        $lock = $prefix . '.lock';
        try {
            // 放在项目根目录以保持全部相对仓库路径语义；写入前限制权限，避免清单中的私有仓库配置泄漏。
            foreach ([$manifest, $lock] as $path) {
                $handle = fopen($path, 'x');
                if ($handle === false) {
                    throw new \RuntimeException('无法创建插件依赖预检文件。');
                }
                fclose($handle);
                if (!chmod($path, 0600)) {
                    throw new \RuntimeException('无法保护插件依赖预检文件。');
                }
            }
            $this->writeRootComposer($manifest, $prepared['data']);
            if (is_file($this->root . '/composer.lock')) {
                if (!copy($this->root . '/composer.lock', $lock)) {
                    throw new \RuntimeException('无法复制 Composer 锁文件进行预检。');
                }
            } else {
                unlink($lock);
            }

            return $this->runRuntimeComposer([
                'update', $metadata->composerName, '--with-dependencies', '--dry-run',
                '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--no-progress',
            ], ['COMPOSER' => $manifest]);
        } finally {
            foreach ([$manifest, $lock] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @return array{composer_path:string,package:string,repository_removed:bool,require_removed:bool}
     */
    public function removePathPackage(PluginMetadata $metadata, string $relativePath): array
    {
        $composerPath = $this->root . '/composer.json';
        $rootComposer = PluginMetadata::readJson($composerPath, '根 composer.json');
        $rootComposer['repositories'] = is_array($rootComposer['repositories'] ?? null) ? $rootComposer['repositories'] : [];
        $rootComposer['require'] = is_array($rootComposer['require'] ?? null) ? $rootComposer['require'] : [];

        $requireRemoved = array_key_exists($metadata->composerName, $rootComposer['require']);
        unset($rootComposer['require'][$metadata->composerName]);
        $repositoryRemoved = $this->removePathRepository($rootComposer['repositories'], $relativePath);
        ksort($rootComposer['require']);

        $this->writeRootComposer($composerPath, $rootComposer);

        return [
            'composer_path' => $composerPath,
            'package' => $metadata->composerName,
            'repository_removed' => $repositoryRemoved,
            'require_removed' => $requireRemoved,
        ];
    }

    /**
     * @param array<int|string,mixed> $arguments
     * @return array{command:string,exit_code:int,output:string}
     */
    public function runRuntimeComposer(array $arguments, array $environment = []): array
    {
        $command = [$this->root . '/bin/smart.php', 'composer'];
        foreach ($arguments as $argument) {
            $command[] = (string)$argument;
        }

        return $this->runProcess($command, $environment + ['COMPOSER' => $this->root . '/composer.json']);
    }

    public function versionConstraint(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)(?:\.\d+)?(?:[-+][A-Za-z0-9_.-]+)?$/', $version, $matches) === 1) {
            return '^' . $matches[1] . '.' . $matches[2];
        }

        return '*';
    }

    /** 清单变更计划供预检与正式写入共用，避免两个阶段使用不同依赖约束。 */
    private function preparePathPackage(PluginMetadata $metadata, string $relativePath): array
    {
        $composerPath = $this->root . '/composer.json';
        $rootComposer = PluginMetadata::readJson($composerPath, '根 composer.json');
        $rootComposer['repositories'] = is_array($rootComposer['repositories'] ?? null) ? $rootComposer['repositories'] : [];
        $rootComposer['require'] = is_array($rootComposer['require'] ?? null) ? $rootComposer['require'] : [];

        $repositoryAdded = $this->upsertPathRepository($rootComposer['repositories'], $relativePath, $metadata);
        $constraint = $this->versionConstraint($metadata->version);
        $requireAdded = !isset($rootComposer['require'][$metadata->composerName]) || $rootComposer['require'][$metadata->composerName] !== $constraint;
        $rootComposer['require'][$metadata->composerName] = $constraint;
        ksort($rootComposer['require']);

        return ['data' => $rootComposer, 'report' => [
            'composer_path' => $composerPath,
            'package' => $metadata->composerName,
            'constraint' => $constraint,
            'repository_added' => $repositoryAdded,
            'require_added' => $requireAdded,
        ]];
    }

    /**
     * @param array<int,mixed> $repositories
     */
    private function upsertPathRepository(array &$repositories, string $relativePath, PluginMetadata $metadata): bool
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        foreach ($repositories as &$repository) {
            if (!is_array($repository) || (string)($repository['type'] ?? '') !== 'path') {
                continue;
            }
            if ($this->normalizeRelativePath((string)($repository['url'] ?? '')) !== $relativePath) {
                continue;
            }
            $this->ensureRepositoryVersion($repository, $metadata);
            return false;
        }
        unset($repository);

        $repository = [
            'type' => 'path',
            'url' => $relativePath,
        ];
        $this->ensureRepositoryVersion($repository, $metadata);
        $repositories[] = $repository;

        return true;
    }

    /**
     * @param array<int,mixed> $repositories
     */
    private function removePathRepository(array &$repositories, string $relativePath): bool
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $removed = false;
        $kept = [];
        foreach ($repositories as $repository) {
            if (is_array($repository) && (string)($repository['type'] ?? '') === 'path') {
                $url = $this->normalizeRelativePath((string)($repository['url'] ?? ''));
                if ($url === $relativePath) {
                    $removed = true;
                    continue;
                }
            }
            $kept[] = $repository;
        }
        $repositories = $kept;

        return $removed;
    }

    /**
     * @param array<string,mixed> $repository
     */
    private function ensureRepositoryVersion(array &$repository, PluginMetadata $metadata): void
    {
        // composer.json 没有 version 时，用 path repository 的 options.versions 补齐版本，保证“plugin.json/composer.json 二选一”可被 Composer 解析。
        if (trim((string)($metadata->composer['version'] ?? '')) !== '') {
            return;
        }

        $repository['options'] = is_array($repository['options'] ?? null) ? $repository['options'] : [];
        $repository['options']['versions'] = is_array($repository['options']['versions'] ?? null) ? $repository['options']['versions'] : [];
        $repository['options']['versions'][$metadata->composerName] = $metadata->version;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeRootComposer(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('根 composer.json 编码失败。');
        }
        $encoded = preg_replace_callback('/^( +)/m', static function (array $matches): string {
            return str_repeat(' ', (int)(strlen($matches[1]) / 2));
        }, $encoded) ?: $encoded;
        if (file_put_contents($path, $encoded . "\n") === false) {
            throw new \RuntimeException('根 composer.json 写入失败。');
        }
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?: '';

        return trim($path, '/');
    }

    /**
     * @param array<int,string> $command
     * @return array{command:string,exit_code:int,output:string}
     */
    private function runProcess(array $command, array $environment): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // 合并错误流，避免 Composer 的大量 stderr 填满管道后与顺序读取 stdout 互相等待。
            2 => ['redirect', 1],
        ];
        $process = proc_open($command, $descriptor, $pipes, $this->root, array_merge(getenv(), $environment));
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动 Composer 进程。');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        $output = trim((string)$stdout);
        $commandText = implode(' ', array_map('escapeshellarg', $command));
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf("Composer 命令执行失败（%d）：%s\n%s", $exitCode, $commandText, $output));
        }

        return [
            'command' => $commandText,
            'exit_code' => $exitCode,
            'output' => $output,
        ];
    }
}
