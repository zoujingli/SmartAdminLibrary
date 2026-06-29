<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Cache\Driver;

use Hyperf\Cache\Collector\FileStorage;
use Hyperf\Cache\Driver\FileSystemDriver;
use Hyperf\Support\Filesystem\Filesystem;
use Psr\Container\ContainerInterface;

final class RuntimeFileSystemDriver extends FileSystemDriver
{
    private Filesystem $filesystem;

    public function __construct(ContainerInterface $container, array $config)
    {
        $this->storePath = rtrim((string)($config['store_path'] ?? runpath('runtime/cache')), '/\\');
        parent::__construct($container, $config);
        $this->filesystem = $container->get(Filesystem::class);
    }

    public function get($key, $default = null): mixed
    {
        $storage = $this->readStorage($this->getCacheKey((string)$key));
        if (!$storage instanceof FileStorage || $storage->isExpired()) {
            return $default;
        }

        return $storage->getData();
    }

    public function fetch(string $key, $default = null): array
    {
        $storage = $this->readStorage($this->getCacheKey($key));
        if (!$storage instanceof FileStorage || $storage->isExpired()) {
            return [false, $default];
        }

        return [true, $storage->getData()];
    }

    public function set($key, $value, $ttl = null): bool
    {
        $file = $this->getCacheKey((string)$key);
        $content = $this->packer->pack(new FileStorage($value, $this->secondsUntil($ttl)));

        try {
            $this->filesystem->replace($file, $content);

            return true;
        } catch (\Throwable) {
            return $this->writeWithLock($file, $content);
        }
    }

    private function readStorage(string $file): ?FileStorage
    {
        if (!file_exists($file)) {
            return null;
        }

        try {
            $storage = $this->unpackStorage($this->filesystem->get($file, true));
        } catch (\Throwable) {
            $this->deleteBrokenCacheFile($file);

            return null;
        }

        if (!$storage instanceof FileStorage) {
            $this->deleteBrokenCacheFile($file);

            return null;
        }

        return $storage;
    }

    private function unpackStorage(string $payload): mixed
    {
        // 文件缓存可能因并发写入或进程中断留下空内容；局部抑制解包 warning，避免 Swoole 进程级错误处理器串扰其它协程。
        if ($payload === '') {
            return null;
        }

        return @$this->packer->unpack($payload);
    }

    private function deleteBrokenCacheFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        try {
            @unlink($file);
        } catch (\Throwable) {
            // 坏缓存清理失败时仍按未命中返回；下一次正常写入会覆盖该缓存文件。
        }
    }

    private function writeWithLock(string $file, string $content): bool
    {
        $handle = @fopen($file, 'wb');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $written = fwrite($handle, $content);
            fflush($handle);
            flock($handle, LOCK_UN);

            return $written === strlen($content);
        } finally {
            fclose($handle);
        }
    }
}
