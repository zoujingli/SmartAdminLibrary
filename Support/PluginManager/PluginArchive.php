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
 * 插件 ZIP 归档工具。
 *
 * 所有解压都逐项校验相对路径，不使用 ZipArchive::extractTo，避免插件包通过 ../、绝对路径或软链覆盖项目外文件。
 */
final class PluginArchive
{
    private const BACKUP_META = '_xadmin/plugin-backup.json';

    public function __construct(private readonly string $tempRoot) {}

    public function createPackage(PluginMetadata $metadata, string $outputDir, ?string $password = null): string
    {
        $this->ensureDirectory($outputDir);
        $path = rtrim($outputDir, '/\\') . '/' . $this->safeName('plugin-' . $metadata->code . '-' . $metadata->version) . '.zip';
        $zip = $this->openForWrite($path, $password);
        try {
            $this->addDirectory($zip, $metadata->directory, '', $password);
        } finally {
            $zip->close();
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $backupMeta
     */
    public function createBackup(
        PluginMetadata $metadata,
        string $outputDir,
        ?string $schemaPath,
        ?string $dataPath,
        array $backupMeta,
        ?string $password = null,
    ): string {
        $this->ensureDirectory($outputDir);
        $path = rtrim($outputDir, '/\\') . '/' . $this->safeName($metadata->code . '-' . $metadata->version . '-backup-' . date('Ymd-His')) . '.zip';
        $zip = $this->openForWrite($path, $password);
        try {
            $this->addDirectory($zip, $metadata->directory, '', $password);
            $metaContent = json_encode($backupMeta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (!is_string($metaContent)) {
                throw new \RuntimeException('插件备份元数据编码失败。');
            }
            $this->addString($zip, self::BACKUP_META, $metaContent . "\n", $password);
            if (($schemaPath === null) !== ($dataPath === null)) {
                throw new \RuntimeException('插件数据备份必须同时提供结构和数据快照。');
            }
            if ($schemaPath !== null && $dataPath !== null) {
                $this->addFile($zip, $schemaPath, '_xadmin/database.schema.gz', $password);
                $this->addFile($zip, $dataPath, '_xadmin/database.data.gz', $password);
            }
        } finally {
            $zip->close();
        }

        return $path;
    }

    /**
     * @return array{extract_dir:string,root:string,backup_meta:null|array<string,mixed>}
     */
    public function extract(string $zipPath, ?string $password = null): array
    {
        if (!is_file($zipPath)) {
            throw new \RuntimeException('ZIP 文件不存在：' . $zipPath);
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new \RuntimeException('无法打开 ZIP 文件：' . $zipPath);
        }
        try {
            if ($password !== null && $password !== '') {
                $zip->setPassword($password);
            }
            $this->assertReadable($zip, $password);

            $target = $this->makeTempDir('extract-');
            $backupMeta = null;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = (string)$zip->getNameIndex($index);
                $relative = $this->normalizeEntryName($name);
                if ($relative === '') {
                    continue;
                }
                $targetPath = $target . '/' . $relative;
                if (str_ends_with($name, '/')) {
                    $this->ensureDirectory($targetPath);
                    continue;
                }

                $this->ensureDirectory(dirname($targetPath));
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    throw new \RuntimeException('无法读取 ZIP 条目，可能密码错误：' . $name);
                }
                $output = fopen($targetPath, 'wb');
                if (!is_resource($output)) {
                    fclose($stream);
                    throw new \RuntimeException('无法写入解压文件：' . $targetPath);
                }
                stream_copy_to_stream($stream, $output);
                fclose($stream);
                fclose($output);

                if ($relative === self::BACKUP_META) {
                    $data = json_decode((string)file_get_contents($targetPath), true);
                    $backupMeta = is_array($data) ? $data : null;
                }
            }
        } finally {
            $zip->close();
        }

        return [
            'extract_dir' => $target,
            'root' => $this->locatePluginRoot($target),
            'backup_meta' => $backupMeta,
        ];
    }

    public function makeTempDir(string $prefix): string
    {
        $this->ensureDirectory($this->tempRoot);
        $path = rtrim($this->tempRoot, '/\\') . '/' . $prefix . bin2hex(random_bytes(6));
        $this->ensureDirectory($path);

        return $path;
    }

    public function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
                continue;
            }
            @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    private function openForWrite(string $path, ?string $password): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建 ZIP 文件：' . $path);
        }
        if ($password !== null && $password !== '') {
            $zip->setPassword($password);
        }

        return $zip;
    }

    private function addDirectory(\ZipArchive $zip, string $directory, string $base, ?string $password): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }
            $relative = ltrim($base . '/' . substr(str_replace('\\', '/', $item->getPathname()), strlen(rtrim(str_replace('\\', '/', $directory), '/')) + 1), '/');
            if ($this->shouldSkip($relative)) {
                continue;
            }
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            $this->addFile($zip, $item->getPathname(), $relative, $password);
        }
    }

    private function addFile(\ZipArchive $zip, string $path, string $entry, ?string $password): void
    {
        if (!$zip->addFile($path, $entry)) {
            throw new \RuntimeException('添加 ZIP 文件失败：' . $entry);
        }
        if ($password !== null && $password !== '') {
            $zip->setEncryptionName($entry, \ZipArchive::EM_AES_256);
        }
    }

    private function addString(\ZipArchive $zip, string $entry, string $content, ?string $password): void
    {
        if (!$zip->addFromString($entry, $content)) {
            throw new \RuntimeException('添加 ZIP 内容失败：' . $entry);
        }
        if ($password !== null && $password !== '') {
            $zip->setEncryptionName($entry, \ZipArchive::EM_AES_256);
        }
    }

    private function assertReadable(\ZipArchive $zip, ?string $password): void
    {
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = (string)$zip->getNameIndex($index);
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $stream = $zip->getStream($name);
            if (!is_resource($stream)) {
                $hint = ($password === null || $password === '') ? '请使用 -p/--password 提供 ZIP 密码。' : '请确认 ZIP 密码是否正确。';
                throw new \RuntimeException('ZIP 文件无法读取，' . $hint);
            }
            fread($stream, 1);
            fclose($stream);
            return;
        }
    }

    private function normalizeEntryName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) === 1) {
            throw new \RuntimeException('ZIP 包含非法路径：' . $name);
        }
        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new \RuntimeException('ZIP 包含非法路径：' . $name);
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function locatePluginRoot(string $root): string
    {
        if (is_file($root . '/composer.json') && is_file($root . '/plugin.json')) {
            return $root;
        }

        $candidates = [];
        foreach (new \DirectoryIterator($root) as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }
            $path = $item->getPathname();
            if (is_file($path . '/composer.json') && is_file($path . '/plugin.json')) {
                $candidates[] = $path;
            }
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        throw new \RuntimeException('ZIP 顶层必须包含 composer.json 与 plugin.json，或只包含一个插件目录。');
    }

    private function shouldSkip(string $relative): bool
    {
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if (in_array($segment, ['.git', '.idea', '.vscode', 'vendor', 'node_modules', 'runtime'], true)) {
                return true;
            }
        }

        return str_ends_with($relative, '.zip') || basename($relative) === '.DS_Store';
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('无法创建目录：' . $path);
        }
    }

    private function safeName(string $name): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?: 'plugin', '-');
    }
}
