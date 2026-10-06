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
 * 源码插件安装/恢复的代码事务：只保护本插件、Composer 清单/锁文件及 vendor。
 *
 * 数据迁移、快照恢复和菜单同步必须在此事务提交后执行，不能假装能回滚外部数据副作用。
 */
final class PluginCodeTransaction
{
    public function __construct(private readonly string $root, private readonly PluginArchive $archive) {}

    /** 串行化插件代码/Composer 变更，移除也使用此锁，避免一方回滚覆盖另一方的依赖状态。 */
    public function exclusive(callable $callback): mixed
    {
        $directory = $this->controlledPath('runtime/plugin');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建插件操作锁目录。');
        }
        $path = $this->controlledPath('runtime/plugin/mutation.lock');
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new \RuntimeException('无法创建插件操作锁。');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('已有插件安装、恢复或移除正在执行，请稍后重试。');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 预检不改变运行目录；复制回滚材料全部成功后才允许执行代码替换与 Composer。 */
    public function run(string $pluginPath, callable $preflight, callable $activate): mixed
    {
        return $this->exclusive(function () use ($pluginPath, $preflight, $activate): mixed {
            $composer = PluginMetadata::readJson($this->controlledPath('composer.json'), '根 composer.json');
            $vendor = str_replace('\\', '/', (string)(getenv('COMPOSER_VENDOR_DIR') ?: ($composer['config']['vendor-dir'] ?? 'vendor')));
            $root = rtrim(str_replace('\\', '/', $this->root), '/');
            if (str_starts_with($vendor, $root . '/')) {
                $vendor = substr($vendor, strlen($root) + 1);
            }
            $paths = [$pluginPath, 'composer.json', 'composer.lock', $vendor];
            $bin = str_replace('\\', '/', (string)(getenv('COMPOSER_BIN_DIR') ?: ($composer['config']['bin-dir'] ?? $vendor . '/bin')));
            if (str_starts_with($bin, $root . '/')) {
                $bin = substr($bin, strlen($root) + 1);
            }
            // 自定义 bin-dir 可能不在 vendor 中，同样保存其可执行文件；项目外路径不能纳入破坏性回滚。
            if ($bin !== $vendor && !str_starts_with($bin, $vendor . '/')) {
                $paths[] = $bin;
            }
            foreach ($paths as $index => $relative) {
                $this->controlledPath($relative);
                if ($relative === 'runtime' || $relative === 'runtime/plugin' || str_starts_with($relative, 'runtime/plugin/')) {
                    throw new \RuntimeException('Composer 状态目录不能覆盖插件操作锁或事务目录。');
                }
                foreach (array_slice($paths, 0, $index) as $other) {
                    if ($relative === $other || str_starts_with($relative, $other . '/') || str_starts_with($other, $relative . '/')) {
                        throw new \RuntimeException('插件与 Composer 状态目录不能重叠。');
                    }
                }
            }
            $preflight();
            $backup = $this->archive->makeTempDir('code-transaction-');
            if (!chmod($backup, 0700)) {
                throw new \RuntimeException('无法保护插件代码回滚目录。');
            }
            $preserveBackup = false;
            $exists = [];
            try {
                foreach ($paths as $index => $relative) {
                    $path = $this->controlledPath($relative);
                    if (str_starts_with(str_replace('\\', '/', $backup), rtrim($path, '/') . '/')) {
                        throw new \RuntimeException('回滚材料不能保存在待替换目录中。');
                    }
                    $exists[$index] = file_exists($path);
                    if ($exists[$index]) {
                        $this->copyEntry($path, $backup . '/' . $index);
                    }
                }
                try {
                    return $activate();
                } catch (\Throwable $failure) {
                    $errors = [];
                    foreach ($paths as $index => $relative) {
                        try {
                            $path = $this->controlledPath($relative, true);
                            $this->removeEntry($path);
                            if ($exists[$index]) {
                                $this->copyEntry($backup . '/' . $index, $path);
                            }
                        } catch (\Throwable $rollbackFailure) {
                            $errors[] = $relative . ': ' . $rollbackFailure->getMessage();
                        }
                    }
                    if ($errors !== []) {
                        $preserveBackup = true;
                        throw new \RuntimeException('插件代码回滚未完成，保留恢复材料：' . $backup . "\n" . implode("\n", $errors), 0, $failure);
                    }
                    throw $failure;
                }
            } finally {
                if (!$preserveBackup) {
                    $this->removeEntry($backup);
                }
            }
        });
    }

    /** 事务只管理项目内明确的相对路径；拒绝路径越界及父目录软链，回滚时仅允许删除叶子软链本身。 */
    private function controlledPath(string $relative, bool $allowLeafLink = false): string
    {
        $parts = explode('/', $relative);
        if ($relative === '' || preg_match('/[:\\\\\x00]/', $relative) === 1 || array_intersect($parts, ['', '.', '..']) !== []) {
            throw new \RuntimeException('插件事务路径必须是项目内受控的相对路径。');
        }
        $path = rtrim($this->root, '/\\');
        foreach ($parts as $index => $part) {
            $path .= '/' . $part;
            if (is_link($path) && !($allowLeafLink && $index === count($parts) - 1)) {
                throw new \RuntimeException('插件事务路径不能使用软链：' . $relative);
            }
        }
        return $path;
    }

    /** vendor 中的 path 包软链只复制链接文本，绝不递归复制插件源码或链接外部目录。 */
    private function copyEntry(string $source, string $target): void
    {
        if (is_link($source)) {
            $link = readlink($source);
            if ($link === false || !symlink($link, $target)) {
                throw new \RuntimeException('无法复制回滚软链：' . $source);
            }
            return;
        }
        if (is_dir($source)) {
            if (!is_dir($target) && !mkdir($target, 0700, true)) {
                throw new \RuntimeException('无法创建回滚目录：' . $target);
            }
            foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $item) {
                $this->copyEntry($item->getPathname(), $target . '/' . $item->getFilename());
            }
        } elseif (!is_file($source) || !copy($source, $target)) {
            throw new \RuntimeException('无法复制代码回滚文件：' . $source);
        }
        if (!chmod($target, fileperms($source) & 0777)) {
            throw new \RuntimeException('无法恢复代码文件权限：' . $target);
        }
    }

    /** 删除已验证的事务状态路径；软链只删除链接本身，不跟随到外部路径。 */
    private function removeEntry(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException('无法删除事务文件：' . $path);
            }
        } elseif (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
                $this->removeEntry($item->getPathname());
            }
            if (!rmdir($path)) {
                throw new \RuntimeException('无法删除事务目录：' . $path);
            }
        }
    }
}
