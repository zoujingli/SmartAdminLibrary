<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library\Cache;

use Hyperf\Codec\Packer\PhpSerializerPacker;
use Hyperf\Support\Filesystem\Filesystem;
use Library\Cache\Driver\RuntimeFileSystemDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * @internal
 */
#[CoversClass(RuntimeFileSystemDriver::class)]
final class RuntimeFileSystemDriverTest extends TestCase
{
    private string $root = '';

    public static function setUpBeforeClass(): void
    {
        if (!defined('Hyperf\Cache\Driver\BASE_PATH')) {
            define('Hyperf\Cache\Driver\BASE_PATH', dirname(__DIR__, 4));
        }
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/smartadmin-runtime-cache-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testBrokenCacheFileIsTreatedAsMissAndDeleted(): void
    {
        $driver = $this->driver();
        $file = $driver->getCacheKey('broken');
        file_put_contents($file, '');

        $this->assertSame('fallback', $driver->get('broken', 'fallback'));
        $this->assertSame([false, 'fallback'], $driver->fetch('broken', 'fallback'));
        $this->assertFileDoesNotExist($file);
    }

    public function testMalformedCacheFileIsTreatedAsMissAndDeleted(): void
    {
        $driver = $this->driver();
        $file = $driver->getCacheKey('malformed');
        file_put_contents($file, 'not-a-serialized-storage');

        $this->assertSame('fallback', $driver->get('malformed', 'fallback'));
        $this->assertFileDoesNotExist($file);
    }

    public function testSetWritesReadableFileStorage(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->set('healthy', ['ok' => true], 60));
        $this->assertSame(['ok' => true], $driver->get('healthy'));
        $this->assertSame([true, ['ok' => true]], $driver->fetch('healthy'));
    }

    public function testSetFallsBackToLockedWriteWhenAtomicReplaceFails(): void
    {
        $driver = new RuntimeFileSystemDriver(new RuntimeFileSystemDriverTestContainer(new FailingReplaceFilesystem()), [
            'packer' => PhpSerializerPacker::class,
            'prefix' => 'test:',
            'store_path' => $this->root,
        ]);

        $this->assertTrue($driver->set('fallback', 'value', 60));
        $this->assertSame('value', $driver->get('fallback'));
    }

    private function driver(): RuntimeFileSystemDriver
    {
        return new RuntimeFileSystemDriver(new RuntimeFileSystemDriverTestContainer(), [
            'packer' => PhpSerializerPacker::class,
            'prefix' => 'test:',
            'store_path' => $this->root,
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}

final class RuntimeFileSystemDriverTestContainer implements ContainerInterface
{
    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem()
    ) {}

    public function get(string $id): mixed
    {
        return match ($id) {
            Filesystem::class => $this->filesystem,
            PhpSerializerPacker::class => new PhpSerializerPacker(),
            default => throw new class(sprintf('Service "%s" not found.', $id)) extends \RuntimeException implements NotFoundExceptionInterface {},
        };
    }

    public function has(string $id): bool
    {
        return in_array($id, [Filesystem::class, PhpSerializerPacker::class], true);
    }
}

final class FailingReplaceFilesystem extends Filesystem
{
    public function replace(string $path, string $content): never
    {
        throw new \RuntimeException('replace failed');
    }
}
