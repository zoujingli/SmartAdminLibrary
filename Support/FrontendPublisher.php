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

use Library\Constants\System;

/**
 * 前端静态资源发布器。
 *
 * 源码模式从 web/dist 复制，Phar 模式只从包内 storage/extra/web-dist.zip 解压；
 * _app.config.js 由 SiteMiddleware 按 .env 动态生成，发布过程必须跳过，避免覆盖部署环境配置。
 */
final class FrontendPublisher
{
    private const ARCHIVE_PATH = 'storage/extra/web-dist.zip';

    private const REQUIRED_ENTRIES = ['index.html'];

    private const REQUIRED_PREFIXES = ['static/'];

    private const DYNAMIC_CONFIGS = ['_app.config.js'];

    private const CLEAN_TARGETS = ['index.html'];

    private const LEGACY_CLEAN_TARGETS = ['css', 'js', 'jse', 'favicon.ico'];

    private const MANIFEST_PATH = 'runtime/site-publish-manifest.json';

    /**
     * 检查 public 是否具备前端最小入口和 static 资源；动态配置由中间件兜底。
     */
    public static function publicReady(?string $targetDir = null): bool
    {
        $targetDir = rtrim($targetDir ?? runpath('public'), '/');
        foreach (self::REQUIRED_ENTRIES as $entry) {
            if (!is_file($targetDir . '/' . $entry)) {
                return false;
            }
        }

        $index = (string)file_get_contents($targetDir . '/index.html');
        $references = self::extractStaticReferences($index);
        if ($references === []) {
            return false;
        }

        foreach ($references as $reference) {
            $path = self::resolveTargetPath($targetDir, $reference);
            if ($path === null || !is_file($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 发布前端静态资源到运行目录 public。
     *
     * @param null|callable(string): mixed $logger
     * @return int 实际发布或 dry-run 预览的文件数量
     */
    public static function publish(bool $dryRun = false, ?callable $logger = null, ?string $targetDir = null, ?string $sourcePath = null): int
    {
        $targetDir = rtrim($targetDir ?? runpath('public'), '/');
        $source = self::resolveSource($sourcePath);
        $logger && $logger(sprintf('发布前端资源：%s -> %s', $source['label'], $targetDir));

        if (!$dryRun && !is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('创建 public 目录失败：' . $targetDir);
        }

        // 先清理上次 manifest 记录与标准构建目录，再发布新资源；清理范围固定，避免误删上传文件。
        self::cleanPublished($targetDir, $dryRun, $logger, true);
        self::removeCleanTargets($targetDir, $dryRun, $logger);

        $files = $source['type'] === 'zip'
            ? self::publishFromZip($source['path'], $targetDir, $dryRun, $logger)
            : self::publishFromDirectory($source['path'], $targetDir, $dryRun, $logger);

        if (!$dryRun) {
            self::writeManifest($targetDir, $files);
            if (!self::publicReady($targetDir)) {
                throw new \RuntimeException('前端资源发布失败：public 入口文件不完整');
            }
        }

        $logger && $logger($dryRun ? 'dry-run 完成，未写入文件' : '前端资源发布完成');
        return count($files);
    }

    /**
     * 清理发布器负责写入的静态资源。
     *
     * @param null|callable(string): mixed $logger
     */
    public static function clean(bool $dryRun = false, ?callable $logger = null, ?string $targetDir = null): int
    {
        $targetDir = rtrim($targetDir ?? runpath('public'), '/');
        $count = self::cleanPublished($targetDir, $dryRun, $logger, false);
        $count += self::removeCleanTargets($targetDir, $dryRun, $logger);
        if (!$dryRun) {
            @unlink(self::manifestPath($targetDir));
        }
        return $count;
    }

    /**
     * Phar 运行只接受包内 zip；源码运行读取 web/dist，保证开发发布与二进制发布规则一致。
     *
     * @return array{type: 'dir'|'zip', path: string, label: string}
     */
    private static function resolveSource(?string $sourcePath = null): array
    {
        if (is_string($sourcePath) && $sourcePath !== '') {
            $path = str_replace('\\', '/', $sourcePath);
            if (is_file($path)) {
                return ['type' => 'zip', 'path' => $path, 'label' => $path];
            }
            if (is_dir($path)) {
                return ['type' => 'dir', 'path' => $path, 'label' => $path];
            }
            throw new \RuntimeException('前端资源来源不存在：' . $path);
        }

        if (System::isPharMode()) {
            $archive = syspath(self::ARCHIVE_PATH);
            if (!is_file($archive)) {
                throw new \RuntimeException('Phar 缺少前端资源包：' . self::ARCHIVE_PATH);
            }
            return ['type' => 'zip', 'path' => $archive, 'label' => self::ARCHIVE_PATH];
        }

        $webDist = syspath('web/dist');
        if (!is_dir($webDist)) {
            throw new \RuntimeException('源码前端资源目录不存在：' . $webDist);
        }
        return ['type' => 'dir', 'path' => $webDist, 'label' => 'web/dist'];
    }

    /**
     * 解压 Phar 内的 web-dist.zip；ZipArchive 不能稳定直接打开 phar://，因此先复制到临时文件。
     *
     * @param null|callable(string): mixed $logger
     * @return string[]
     */
    private static function publishFromZip(string $archivePath, string $targetDir, bool $dryRun, ?callable $logger): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xadmin-web-dist-');
        if ($tmp === false) {
            throw new \RuntimeException('创建前端资源解压临时文件失败');
        }

        $files = [];
        $indexHtml = null;
        try {
            $content = file_get_contents($archivePath);
            if (!is_string($content) || $content === '') {
                throw new \RuntimeException('读取前端资源包失败：' . $archivePath);
            }
            file_put_contents($tmp, $content, LOCK_EX);

            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('打开前端资源包失败：' . $archivePath);
            }
            try {
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $name = $zip->getNameIndex($index);
                    if (!is_string($name)) {
                        continue;
                    }
                    $rawName = str_replace('\\', '/', $name);
                    self::assertSafeRelativePath($rawName);
                    $relative = self::normalizeRelativePath($rawName);
                    if ($relative === '' || str_ends_with($rawName, '/')) {
                        continue;
                    }
                    if (self::shouldSkip($relative)) {
                        continue;
                    }

                    $files[] = $relative;
                    if ($relative === 'index.html') {
                        $indexSource = $zip->getFromName($name);
                        $indexHtml = is_string($indexSource) ? $indexSource : null;
                    }
                    $logger && $logger(($dryRun ? '[dry-run] ' : '') . 'copy  ' . $relative);
                    if (!$dryRun) {
                        self::writeZipEntry($zip, $name, $targetDir . '/' . $relative);
                    }
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }

        self::assertPublishedFiles($files, $indexHtml);
        return array_values(array_unique($files));
    }

    /**
     * 源码模式按相同规则复制 web/dist，便于本地验证发布逻辑。
     *
     * @param null|callable(string): mixed $logger
     * @return string[]
     */
    private static function publishFromDirectory(string $sourceDir, string $targetDir, bool $dryRun, ?callable $logger): array
    {
        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        $files = [];
        $indexHtml = null;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $source = str_replace('\\', '/', $fileInfo->getPathname());
            $relative = self::normalizeRelativePath(substr($source, strlen($sourceDir)));
            if ($relative === '' || self::shouldSkip($relative)) {
                continue;
            }
            self::assertSafeRelativePath($relative);

            $files[] = $relative;
            if ($relative === 'index.html') {
                $sourceHtml = file_get_contents($fileInfo->getPathname());
                $indexHtml = is_string($sourceHtml) ? $sourceHtml : null;
            }
            $logger && $logger(($dryRun ? '[dry-run] ' : '') . 'copy  ' . $relative);
            if (!$dryRun) {
                $target = $targetDir . '/' . $relative;
                is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
                if (!copy($fileInfo->getPathname(), $target)) {
                    throw new \RuntimeException('复制前端资源失败：' . $relative);
                }
            }
        }

        self::assertPublishedFiles($files, $indexHtml);
        return array_values(array_unique($files));
    }

    private static function writeZipEntry(\ZipArchive $zip, string $name, string $target): void
    {
        $source = $zip->getStream($name);
        if ($source === false) {
            throw new \RuntimeException('读取前端资源包条目失败：' . $name);
        }
        is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
        $dest = fopen($target, 'wb');
        if ($dest === false) {
            fclose($source);
            throw new \RuntimeException('写入前端资源失败：' . $target);
        }
        try {
            if (stream_copy_to_stream($source, $dest) === false) {
                throw new \RuntimeException('解压前端资源失败：' . $name);
            }
        } finally {
            fclose($dest);
            fclose($source);
        }
    }

    /**
     * @param string[] $files
     */
    private static function assertPublishedFiles(array $files, ?string $indexHtml): void
    {
        $lookup = array_fill_keys($files, true);
        foreach (self::REQUIRED_ENTRIES as $entry) {
            if (!isset($lookup[$entry])) {
                throw new \RuntimeException('前端资源缺少入口页：' . $entry);
            }
        }

        foreach ($files as $file) {
            if (isset($lookup[$file])) {
                foreach (self::REQUIRED_ENTRIES as $entry) {
                    if ($file === $entry) {
                        continue 2;
                    }
                }
                foreach (self::REQUIRED_PREFIXES as $prefix) {
                    if (str_starts_with($file, $prefix)) {
                        continue 2;
                    }
                }
            }

            throw new \RuntimeException('前端资源包含非 static 根路径：' . $file);
        }

        foreach (self::REQUIRED_PREFIXES as $prefix) {
            foreach ($files as $file) {
                if (str_starts_with($file, $prefix)) {
                    continue 2;
                }
            }

            throw new \RuntimeException('前端资源缺少静态目录：' . rtrim($prefix, '/'));
        }

        if (is_string($indexHtml)) {
            $references = self::extractStaticReferences($indexHtml);
            if ($references === []) {
                throw new \RuntimeException('前端资源入口未引用 static 资源');
            }
            foreach ($references as $reference) {
                if (!isset($lookup[$reference])) {
                    throw new \RuntimeException('前端资源缺少静态文件：' . $reference);
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private static function extractStaticReferences(string $html): array
    {
        if (!preg_match_all('~(?:src|href)=["\'][^"\']*(static/[^"\'?#]+)(?:[?#][^"\']*)?["\']~i', $html, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $path): string => self::normalizeRelativePath($path),
            $matches[1]
        )));
    }

    private static function shouldSkip(string $relative): bool
    {
        return basename($relative) === '.DS_Store' || in_array($relative, self::DYNAMIC_CONFIGS, true);
    }

    private static function normalizeRelativePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private static function assertSafeRelativePath(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        $relative = trim($path, '/');
        if ($relative === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1 || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
            throw new \RuntimeException('前端资源包含非法路径：' . $path);
        }
    }

    /**
     * @param string[] $files
     */
    private static function writeManifest(string $targetDir, array $files): void
    {
        $path = self::manifestPath($targetDir);
        is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode([
            'published_at' => date('c'),
            'files' => array_values(array_unique($files)),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @return string[]
     */
    private static function loadManifestFiles(string $targetDir): array
    {
        $path = self::manifestPath($targetDir);
        if (!is_file($path)) {
            return [];
        }

        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload) || !is_array($payload['files'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $file): string => self::normalizeRelativePath((string)$file), $payload['files']),
            static fn (string $file): bool => $file !== '' && preg_match('#(^|/)\.\.(/|$)#', $file) !== 1
        ));
    }

    /**
     * @param null|callable(string): mixed $logger
     */
    private static function cleanPublished(string $targetDir, bool $dryRun, ?callable $logger, bool $quiet): int
    {
        $files = self::loadManifestFiles($targetDir);
        if ($files === [] || !is_dir($targetDir)) {
            return 0;
        }

        $count = 0;
        foreach ($files as $relative) {
            $path = self::resolveTargetPath($targetDir, $relative);
            if ($path === null || !is_file($path)) {
                continue;
            }
            !$quiet && $logger && $logger(($dryRun ? '[dry-run] ' : '') . 'del   ' . $relative);
            if (!$dryRun) {
                @unlink($path);
            }
            ++$count;
        }

        if (!$dryRun) {
            self::removeEmptyPublishedDirs($targetDir, $files);
        }
        return $count;
    }

    /**
     * @param null|callable(string): mixed $logger
     */
    private static function removeCleanTargets(string $targetDir, bool $dryRun, ?callable $logger): int
    {
        $count = 0;
        // 新版 static 资源只按 manifest 精确删除，避免误删 public/static/uploads 等运行期文件。
        // 旧版 css/js/jse/favicon.ico 是历史构建目录，可作为固定目标清理，避免新旧资源混用。
        foreach (array_merge(self::CLEAN_TARGETS, self::LEGACY_CLEAN_TARGETS) as $relative) {
            $path = self::resolveTargetPath($targetDir, $relative);
            if ($path === null || (!file_exists($path) && !is_link($path))) {
                continue;
            }
            $logger && $logger(($dryRun ? '[dry-run] ' : '') . 'clean ' . $relative);
            if (!$dryRun) {
                self::removePath($path);
            }
            ++$count;
        }
        return $count;
    }

    private static function manifestPath(string $targetDir): string
    {
        return rtrim(dirname(rtrim($targetDir, '/')), '/') . '/' . self::MANIFEST_PATH;
    }

    private static function resolveTargetPath(string $targetDir, string $relative): ?string
    {
        $relative = self::normalizeRelativePath($relative);
        if ($relative === '' || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
            return null;
        }

        return rtrim($targetDir, '/') . '/' . $relative;
    }

    /**
     * @param string[] $files
     */
    private static function removeEmptyPublishedDirs(string $targetDir, array $files): void
    {
        $dirs = [];
        foreach ($files as $relative) {
            $path = self::resolveTargetPath($targetDir, $relative);
            if ($path !== null) {
                $dirs[] = dirname($path);
            }
        }

        usort($dirs, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        $targetDir = rtrim($targetDir, '/');
        foreach (array_unique($dirs) as $dir) {
            while ($dir !== $targetDir && str_starts_with($dir, $targetDir . '/')) {
                if (@rmdir($dir) === false) {
                    break;
                }
                $dir = dirname($dir);
            }
        }
    }

    private static function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException('删除前端资源文件失败：' . $path);
            }
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $item = $fileInfo->getPathname();
            if ($fileInfo->isDir() && !$fileInfo->isLink()) {
                if (!rmdir($item)) {
                    throw new \RuntimeException('删除前端资源目录失败：' . $item);
                }
            } elseif (!unlink($item)) {
                throw new \RuntimeException('删除前端资源文件失败：' . $item);
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('删除前端资源目录失败：' . $path);
        }
    }
}
