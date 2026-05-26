<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Service;

use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;
use Library\Support\PluginManager\PluginArchive;
use Library\Support\PluginManager\PluginComposerManager;
use Library\Support\PluginManager\PluginDatabaseSnapshot;
use Library\Support\PluginManager\PluginMetadata;

/**
 * Library 内置插件管理服务。
 *
 * 该服务只服务源码/CI 命令：负责本地插件包的 ZIP 打包、安装、移除、备份与恢复，生产 Phar/SFX 不暴露这些命令。
 */
final class PluginManagerService
{
    private const PROTECTED_MODULES = ['Builder', 'Library', 'System'];

    private readonly string $root;

    private readonly string $runtimeRoot;

    private readonly PluginArchive $archive;

    private readonly PluginDatabaseSnapshot $database;

    private readonly PluginComposerManager $composer;

    public function __construct(?string $root = null, ?string $runtimeRoot = null)
    {
        $this->root = rtrim($root ?? \syspath(), '/\\');
        $this->runtimeRoot = rtrim($runtimeRoot ?? \runpath(), '/\\');
        $this->archive = new PluginArchive($this->defaultTempDirectory());
        $this->database = new PluginDatabaseSnapshot();
        $this->composer = new PluginComposerManager($this->root);
    }

    /**
     * @return array<string,mixed>
     */
    public function package(string $plugin, ?string $outputDir = null, ?string $password = null): array
    {
        $metadata = PluginMetadata::load($this->resolvePluginDirectory($plugin));
        $path = $this->archive->createPackage($metadata, $outputDir ?: $this->defaultPackageDirectory(), $password);

        return [
            'plugin' => $metadata->code,
            'name' => $metadata->name,
            'version' => $metadata->version,
            'package' => $metadata->composerName,
            'path' => $path,
            'encrypted' => $this->hasPassword($password),
            'structure' => ['composer.json', 'plugin.json', 'src', 'stc'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function backup(string $plugin, ?string $outputDir = null, ?string $password = null, bool $withData = false): array
    {
        $metadata = PluginMetadata::load($this->resolvePluginDirectory($plugin));
        $database = [
            'schema_path' => null,
            'data_path' => null,
            'tables' => [],
            'rows' => 0,
            'skipped_tables' => [],
        ];
        $workDir = null;
        try {
            if ($withData) {
                $workDir = $this->archive->makeTempDir('backup-work-');
                $database = $this->database->backup($metadata, $workDir);
            }
            $backupMeta = [
                'format' => 'xadmin-plugin-backup',
                'version' => 1,
                'with_data' => $withData,
                'created_at' => date(DATE_ATOM),
                'plugin' => [
                    'code' => $metadata->code,
                    'name' => $metadata->name,
                    'version' => $metadata->version,
                    'package' => $metadata->composerName,
                    'module' => $metadata->module,
                ],
                'tables' => $database['tables'],
                'rows' => $database['rows'],
            ];
            $path = $this->archive->createBackup(
                $metadata,
                $outputDir ?: $this->defaultBackupDirectory(),
                is_string($database['schema_path']) ? $database['schema_path'] : null,
                is_string($database['data_path']) ? $database['data_path'] : null,
                $backupMeta,
                $password
            );
        } finally {
            if ($workDir !== null) {
                $this->archive->removeDirectory($workDir);
            }
        }

        return [
            'plugin' => $metadata->code,
            'name' => $metadata->name,
            'version' => $metadata->version,
            'path' => $path,
            'encrypted' => $this->hasPassword($password),
            'with_data' => $withData,
            'tables' => $database['tables'],
            'rows' => $database['rows'],
            'skipped_tables' => $database['skipped_tables'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function install(string $source, ?string $password = null, bool $force = false, bool $migrate = true, bool $sync = true): array
    {
        $zipPath = $this->resolveZipSource($source);
        $extracted = $this->archive->extract($zipPath, $password);
        $metadata = PluginMetadata::load($extracted['root']);
        $this->assertNotProtected($metadata);
        $target = $this->root . '/plugin/' . $metadata->module;
        $relativePath = 'plugin/' . $metadata->module;

        if (is_dir($target) && !$force) {
            throw new \RuntimeException('插件目录已存在，如需覆盖请使用 --force：' . $relativePath);
        }
        if (is_dir($target)) {
            $this->archive->removeDirectory($target);
        }
        $this->ensureDirectory(dirname($target));
        $this->movePluginDirectory($extracted['root'], $target, false);

        $metadata = PluginMetadata::load($target);
        $composer = $this->composer->addPathPackage($metadata, $relativePath);
        $composerRun = $this->composer->runRuntimeComposer([
            'update',
            $metadata->composerName,
            '--with-dependencies',
            '--no-interaction',
            '--prefer-dist',
            '--no-progress',
        ]);
        $migrationRun = $migrate ? $this->runPluginMigrations($metadata) : null;
        $syncRun = $sync ? $this->syncRegistries() : null;

        return [
            'plugin' => $metadata->code,
            'name' => $metadata->name,
            'version' => $metadata->version,
            'package' => $metadata->composerName,
            'path' => $relativePath,
            'composer' => $composer,
            'composer_run' => $composerRun,
            'migration' => $migrationRun,
            'sync' => $syncRun,
            'web_build_notice' => $this->webBuildNotice($metadata),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function remove(string $plugin, ?string $backupPassword = null): array
    {
        $directory = $this->resolvePluginDirectory($plugin);
        $metadata = PluginMetadata::load($directory);
        $this->assertNotProtected($metadata);
        $relativePath = $this->relativePath($directory);

        $backup = $this->backup($directory, null, $backupPassword, true);
        $ownedTables = $this->database->resolveOwnedTables($metadata)['tables'];
        $menuCleanup = $this->softDeletePluginMenus($metadata);
        $composer = $this->composer->removePathPackage($metadata, $relativePath);
        $composerRun = $this->composer->runRuntimeComposer([
            'update',
            $metadata->composerName,
            '--with-dependencies',
            '--no-interaction',
            '--prefer-dist',
            '--no-progress',
        ]);
        $drop = $this->database->dropTables($ownedTables);
        $this->archive->removeDirectory($directory);
        $sync = $this->syncRegistries();

        return [
            'plugin' => $metadata->code,
            'name' => $metadata->name,
            'version' => $metadata->version,
            'backup' => $backup,
            'composer' => $composer,
            'composer_run' => $composerRun,
            'dropped_tables' => $drop['tables'],
            'menu_cleanup' => $menuCleanup,
            'sync' => $sync,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function restore(string $backupZip, ?string $password = null, bool $force = false, bool $migrate = true, bool $sync = true, bool $restoreData = true): array
    {
        $zipPath = $this->resolveZipSource($backupZip, $this->defaultBackupDirectory(), true);
        $extracted = $this->archive->extract($zipPath, $password);
        $backupMeta = $extracted['backup_meta'];
        if (!is_array($backupMeta) || (string)($backupMeta['format'] ?? '') !== 'xadmin-plugin-backup') {
            throw new \RuntimeException('备份 ZIP 缺少 _xadmin/plugin-backup.json 或格式无效。');
        }

        $metadata = PluginMetadata::load($extracted['root']);
        $this->assertNotProtected($metadata);
        $target = $this->root . '/plugin/' . $metadata->module;
        $relativePath = 'plugin/' . $metadata->module;
        if (is_dir($target) && !$force) {
            throw new \RuntimeException('插件目录已存在，如需覆盖恢复请使用 --force：' . $relativePath);
        }
        if (is_dir($target)) {
            $this->archive->removeDirectory($target);
        }
        $this->ensureDirectory(dirname($target));
        $this->movePluginDirectory($extracted['root'], $target, true);

        $metadata = PluginMetadata::load($target);
        $composer = $this->composer->addPathPackage($metadata, $relativePath);
        $composerRun = $this->composer->runRuntimeComposer([
            'update',
            $metadata->composerName,
            '--with-dependencies',
            '--no-interaction',
            '--prefer-dist',
            '--no-progress',
        ]);
        $migrationRun = $migrate ? $this->runPluginMigrations($metadata) : null;
        $tables = is_array($backupMeta['tables'] ?? null) ? array_values(array_map('strval', $backupMeta['tables'])) : [];
        $hasData = $this->backupContainsData($backupMeta, $extracted['extract_dir']);
        if ($hasData && $restoreData) {
            [$schemaPath, $dataPath] = $this->databaseSnapshotPaths($extracted['extract_dir']);
            $database = $this->database->restore($schemaPath, $dataPath, $tables, $force);
        } else {
            $database = [
                'skipped' => true,
                'reason' => $hasData ? 'no-data option' : 'backup_without_data',
                'tables' => [],
                'rows' => 0,
            ];
        }
        $syncRun = $sync ? $this->syncRegistries() : null;
        $this->archive->removeDirectory($extracted['extract_dir']);

        return [
            'plugin' => $metadata->code,
            'name' => $metadata->name,
            'version' => $metadata->version,
            'package' => $metadata->composerName,
            'path' => $relativePath,
            'composer' => $composer,
            'composer_run' => $composerRun,
            'migration' => $migrationRun,
            'database' => $database,
            'sync' => $syncRun,
            'web_build_notice' => $this->webBuildNotice($metadata),
        ];
    }

    public function resolvePluginDirectory(string $plugin): string
    {
        $plugin = trim($plugin);
        if ($plugin === '') {
            throw new \InvalidArgumentException('插件名或目录不能为空。');
        }

        $candidates = [];
        if (str_contains($plugin, '/') || str_contains($plugin, '\\')) {
            $candidates[] = str_starts_with($plugin, '/') ? $plugin : $this->root . '/' . $plugin;
        } else {
            $candidates[] = $this->root . '/plugin/' . $plugin;
            $candidates[] = $this->root . '/plugin/' . PluginMetadata::studly($plugin);
        }
        foreach ($candidates as $candidate) {
            $path = rtrim(str_replace('\\', '/', $candidate), '/');
            if (is_dir($path) && is_file($path . '/composer.json') && is_file($path . '/plugin.json')) {
                return $path;
            }
        }

        $pluginRoot = $this->root . '/plugin';
        if (is_dir($pluginRoot)) {
            foreach (new \DirectoryIterator($pluginRoot) as $item) {
                if ($item->isDot() || !$item->isDir()) {
                    continue;
                }
                $path = $item->getPathname();
                if (!is_file($path . '/composer.json') || !is_file($path . '/plugin.json')) {
                    continue;
                }
                $metadata = PluginMetadata::load($path);
                if (in_array($plugin, [$metadata->code, $metadata->name, $metadata->composerName, $metadata->module], true)) {
                    return $path;
                }
            }
        }

        throw new \RuntimeException('无法定位插件：' . $plugin);
    }

    private function assertNotProtected(PluginMetadata $metadata): void
    {
        if (in_array($metadata->module, self::PROTECTED_MODULES, true) || in_array($metadata->code, ['builder', 'library', 'system'], true)) {
            throw new \RuntimeException('内置基础插件不允许通过 xadmin:plugin:* 安装、覆盖、移除或恢复：' . $metadata->module);
        }
    }

    private function resolveZipSource(string $source, ?string $defaultDirectory = null, bool $appendZipForName = false): string
    {
        $source = trim($source);
        if (preg_match('#^https?://#i', $source) === 1) {
            $content = @file_get_contents($source);
            if (!is_string($content)) {
                throw new \RuntimeException('无法下载插件 ZIP：' . $source);
            }
            $tmp = $this->archive->makeTempDir('download-') . '/plugin.zip';
            file_put_contents($tmp, $content);
            return $tmp;
        }

        if ($defaultDirectory !== null && !$this->hasDirectorySegment($source)) {
            $filename = $appendZipForName && !str_ends_with(strtolower($source), '.zip') ? $source . '.zip' : $source;
            $path = rtrim($defaultDirectory, '/\\') . '/' . $filename;
        } else {
            $path = $this->isAbsolutePath($source) ? $source : $this->root . '/' . $source;
        }
        if (!is_file($path)) {
            throw new \RuntimeException('插件 ZIP 文件不存在：' . $path);
        }

        return $path;
    }

    /**
     * @return null|array<string,mixed>
     */
    private function runPluginMigrations(PluginMetadata $metadata): ?array
    {
        $plugin = is_array($metadata->manifest['plugin'] ?? null) ? $metadata->manifest['plugin'] : [];
        $migrationRoot = trim((string)($plugin['migration_root'] ?? ''));
        if ($migrationRoot === '') {
            return null;
        }
        $path = $metadata->directory . '/' . trim($migrationRoot, '/');
        if (!is_dir($path)) {
            return null;
        }

        return $this->runShell([$this->root . '/bin/smart', 'migrate', '--path=' . $path, '--realpath']);
    }

    /**
     * @return array{menu:array<string,mixed>,node:array<string,mixed>}
     */
    private function syncRegistries(): array
    {
        return [
            'menu' => $this->runShell([$this->root . '/bin/smart', 'xadmin:menu:sync', '--details']),
            'node' => $this->runShell([$this->root . '/bin/smart', 'xadmin:node:sync', '--details']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function softDeletePluginMenus(PluginMetadata $metadata): array
    {
        if (!Schema::hasTable('system_menu')) {
            return ['skipped' => true, 'menus' => 0, 'nodes' => 0];
        }

        $now = date('Y-m-d H:i:s');
        $menus = 0;
        if ($metadata->menuIds !== []) {
            $menus += Db::table('system_menu')->whereIn('id', $metadata->menuIds)->update(['deleted_at' => $now, 'updated_at' => $now]);
        }
        if ($metadata->menuCodes !== []) {
            $menus += Db::table('system_menu')->whereIn('code', $metadata->menuCodes)->update(['deleted_at' => $now, 'updated_at' => $now]);
        }

        $nodes = 0;
        if ($metadata->menuCodes !== [] && Schema::hasTable('system_node')) {
            $nodes = Db::table('system_node')->whereIn('node', $metadata->menuCodes)->update(['status' => 0, 'updated_at' => $now]);
        }

        return ['skipped' => false, 'menus' => $menus, 'nodes' => $nodes];
    }

    /**
     * @param array<int,string> $command
     * @return array{command:string,exit_code:int,output:string}
     */
    private function runShell(array $command): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $this->root);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动命令进程。');
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
            throw new \RuntimeException(sprintf("命令执行失败（%d）：%s\n%s", $exitCode, $commandText, $output));
        }

        return ['command' => $commandText, 'exit_code' => $exitCode, 'output' => $output];
    }

    private function webBuildNotice(PluginMetadata $metadata): string
    {
        $plugin = is_array($metadata->manifest['plugin'] ?? null) ? $metadata->manifest['plugin'] : [];
        return trim((string)($plugin['view_root'] ?? '')) !== ''
            ? '插件包含前端资源，请执行 composer web:build 重新生成前端产物。'
            : '';
    }

    private function movePluginDirectory(string $source, string $target, bool $keepSource): void
    {
        // 备份 ZIP 的 _xadmin 目录只服务恢复流程，不能落入最终 plugin/<Module> 运行目录。
        if (!$keepSource && !is_dir($source . '/_xadmin') && @rename($source, $target)) {
            return;
        }
        $this->copyDirectory($source, $target, ['_xadmin']);
        if (!$keepSource) {
            $this->archive->removeDirectory($source);
        }
    }

    /**
     * @param array<int,string> $excludeTopLevel
     */
    private function copyDirectory(string $source, string $target, array $excludeTopLevel = []): void
    {
        $this->ensureDirectory($target);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen(rtrim($source, '/\\')) + 1));
            $topLevel = explode('/', $relative)[0] ?? '';
            if (in_array($topLevel, $excludeTopLevel, true)) {
                continue;
            }
            $destination = $target . '/' . $relative;
            if ($item->isDir()) {
                $this->ensureDirectory($destination);
                continue;
            }
            $this->ensureDirectory(dirname($destination));
            copy($item->getPathname(), $destination);
        }
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        $root = str_replace('\\', '/', $this->root);
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    private function defaultTempDirectory(): string
    {
        return $this->runtimeRoot . '/runtime/plugin/tmp';
    }

    private function defaultPackageDirectory(): string
    {
        return $this->runtimeRoot . '/runtime/plugin/packages';
    }

    private function defaultBackupDirectory(): string
    {
        return $this->runtimeRoot . '/runtime/plugin/backups';
    }

    private function hasDirectorySegment(string $path): bool
    {
        return str_contains($path, '/') || str_contains($path, '\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\]/', $path) === 1;
    }

    /**
     * 兼容旧备份包：旧包没有 with_data 字段，但只要包含数据库快照文件，仍按带数据备份处理。
     *
     * @param array<string,mixed> $backupMeta
     */
    private function backupContainsData(array $backupMeta, string $extractDir): bool
    {
        if (array_key_exists('with_data', $backupMeta)) {
            return (bool)$backupMeta['with_data'];
        }

        return is_file($extractDir . '/_xadmin/database.schema.gz') || is_file($extractDir . '/_xadmin/database.data.gz');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function databaseSnapshotPaths(string $extractDir): array
    {
        $schemaPath = $extractDir . '/_xadmin/database.schema.gz';
        $dataPath = $extractDir . '/_xadmin/database.data.gz';
        if (!is_file($schemaPath) || !is_file($dataPath)) {
            throw new \RuntimeException('备份 ZIP 标记包含数据，但缺少数据库结构或数据快照文件。');
        }

        return [$schemaPath, $dataPath];
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('无法创建目录：' . $path);
        }
    }

    private function hasPassword(?string $password): bool
    {
        return $password !== null && $password !== '';
    }
}
