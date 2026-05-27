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
        $composerPath = $this->root . '/composer.json';
        $rootComposer = PluginMetadata::readJson($composerPath, '根 composer.json');
        $rootComposer['repositories'] = is_array($rootComposer['repositories'] ?? null) ? $rootComposer['repositories'] : [];
        $rootComposer['require'] = is_array($rootComposer['require'] ?? null) ? $rootComposer['require'] : [];

        $repositoryAdded = $this->upsertPathRepository($rootComposer['repositories'], $relativePath, $metadata);
        $constraint = $this->versionConstraint($metadata->version);
        $requireAdded = !isset($rootComposer['require'][$metadata->composerName]) || $rootComposer['require'][$metadata->composerName] !== $constraint;
        $rootComposer['require'][$metadata->composerName] = $constraint;
        ksort($rootComposer['require']);

        $this->writeRootComposer($composerPath, $rootComposer);

        return [
            'composer_path' => $composerPath,
            'package' => $metadata->composerName,
            'constraint' => $constraint,
            'repository_added' => $repositoryAdded,
            'require_added' => $requireAdded,
        ];
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
    public function runRuntimeComposer(array $arguments): array
    {
        $command = [$this->root . '/bin/smart.php', 'composer'];
        foreach ($arguments as $argument) {
            $command[] = (string)$argument;
        }

        return $this->runProcess($command);
    }

    public function versionConstraint(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)(?:\.\d+)?(?:[-+][A-Za-z0-9_.-]+)?$/', $version, $matches) === 1) {
            return '^' . $matches[1] . '.' . $matches[2];
        }

        return '*';
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
        file_put_contents($path, $encoded . "\n");
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
    private function runProcess(array $command): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $this->root);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动 Composer 进程。');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $output = trim((string)$stdout . ((string)$stderr !== '' ? "\n" . (string)$stderr : ''));
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
