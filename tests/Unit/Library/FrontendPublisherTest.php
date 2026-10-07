<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library;

use Library\Support\FrontendPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FrontendPublisher::class)]
final class FrontendPublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/xadmin-frontend-publisher-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/web-dist/static/js', 0777, true);
        mkdir($this->root . '/public', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testPublishFromDirectorySkipsDynamicConfigAndWritesManifest(): void
    {
        file_put_contents($this->root . '/web-dist/index.html', '<script type="module" src="/static/js/app.js"></script>');
        file_put_contents($this->root . '/web-dist/_app.config.js', 'window.appConfig = {};');
        file_put_contents($this->root . '/web-dist/static/js/app.js', 'console.log("ok");');
        mkdir($this->root . '/public/static/uploads', 0777, true);
        file_put_contents($this->root . '/public/static/uploads/user.png', 'uploaded');

        $messages = [];
        $count = FrontendPublisher::publish(false, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }, $this->root . '/public', $this->root . '/web-dist');

        self::assertSame(2, $count);
        self::assertFileExists($this->root . '/public/index.html');
        self::assertFileExists($this->root . '/public/static/js/app.js');
        self::assertFileExists($this->root . '/public/static/uploads/user.png');
        self::assertFileDoesNotExist($this->root . '/public/_app.config.js');
        self::assertFileExists($this->root . '/runtime/site-publish-manifest.json');
        self::assertTrue(FrontendPublisher::publicReady($this->root . '/public'));
        self::assertContains('copy  index.html', $messages);
    }

    public function testPublicReadyRequiresStaticFile(): void
    {
        file_put_contents($this->root . '/public/index.html', '<script type="module" src="/static/js/app.js"></script>');

        self::assertFalse(FrontendPublisher::publicReady($this->root . '/public'));

        mkdir($this->root . '/public/static/uploads', 0777, true);
        file_put_contents($this->root . '/public/static/uploads/user.png', 'uploaded');
        self::assertFalse(FrontendPublisher::publicReady($this->root . '/public'));

        mkdir($this->root . '/public/static/js', 0777, true);
        file_put_contents($this->root . '/public/static/js/app.js', 'console.log("ok");');

        self::assertTrue(FrontendPublisher::publicReady($this->root . '/public'));
    }

    public function testPublishRejectsMissingReferencedStaticFile(): void
    {
        file_put_contents($this->root . '/web-dist/index.html', '<script type="module" src="/static/js/missing.js"></script>');
        file_put_contents($this->root . '/web-dist/static/js/app.js', 'console.log("ok");');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('缺少静态文件');

        FrontendPublisher::publish(false, null, $this->root . '/public', $this->root . '/web-dist');
    }

    public function testPublishKeepsPreviousHashedAssetsForAlreadyOpenPages(): void
    {
        $first = $this->root . '/web-dist-first';
        mkdir($first . '/static/js', 0777, true);
        file_put_contents($first . '/index.html', '<script type="module" src="/static/js/index-first.js"></script>');
        file_put_contents($first . '/static/js/index-first.js', 'console.log("first");');

        $second = $this->root . '/web-dist-second';
        mkdir($second . '/static/js', 0777, true);
        file_put_contents($second . '/index.html', '<script type="module" src="/static/js/index-second.js"></script>');
        file_put_contents($second . '/static/js/index-second.js', 'console.log("second");');

        FrontendPublisher::publish(false, null, $this->root . '/public', $first);
        FrontendPublisher::publish(false, null, $this->root . '/public', $second);

        self::assertFileExists($this->root . '/public/static/js/index-first.js');
        self::assertFileExists($this->root . '/public/static/js/index-second.js');
        self::assertTrue(FrontendPublisher::publicReady($this->root . '/public'));

        FrontendPublisher::clean(false, null, $this->root . '/public');
        self::assertFileDoesNotExist($this->root . '/public/static/js/index-first.js');
        self::assertFileDoesNotExist($this->root . '/public/static/js/index-second.js');
    }

    public function testPublishFromZipRejectsUnsafeRelativePath(): void
    {
        $zipFile = $this->root . '/web-dist.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('index.html', '<script type="module" src="/static/js/app.js"></script>');
        $zip->addFromString('static/js/app.js', 'console.log("ok");');
        $zip->addFromString('../evil.php', '<?php echo "bad";');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('非法路径');

        FrontendPublisher::publish(false, null, $this->root . '/public', $zipFile);
    }

    public function testPublishRejectsNonStaticRootFile(): void
    {
        file_put_contents($this->root . '/web-dist/index.html', '<script type="module" src="/static/js/app.js"></script>');
        file_put_contents($this->root . '/web-dist/static/js/app.js', 'console.log("ok");');
        file_put_contents($this->root . '/web-dist/favicon.ico', 'ico');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('非 static 根路径');

        FrontendPublisher::publish(false, null, $this->root . '/public', $this->root . '/web-dist');
    }

    public function testCleanRemovesPublishedFilesButKeepsDynamicConfig(): void
    {
        file_put_contents($this->root . '/web-dist/index.html', '<script type="module" src="/static/js/app.js"></script>');
        file_put_contents($this->root . '/web-dist/_app.config.js', 'window.appConfig = {};');
        file_put_contents($this->root . '/web-dist/static/js/app.js', 'console.log("ok");');
        file_put_contents($this->root . '/public/_app.config.js', 'window.runtime = {};');
        mkdir($this->root . '/public/static/uploads', 0777, true);
        file_put_contents($this->root . '/public/static/uploads/user.png', 'uploaded');
        mkdir($this->root . '/public/jse', 0777, true);
        file_put_contents($this->root . '/public/jse/old-entry.js', 'console.log("old");');

        FrontendPublisher::publish(false, null, $this->root . '/public', $this->root . '/web-dist');
        $count = FrontendPublisher::clean(false, null, $this->root . '/public');

        self::assertGreaterThanOrEqual(2, $count);
        self::assertFileDoesNotExist($this->root . '/public/index.html');
        self::assertFileDoesNotExist($this->root . '/public/static/js/app.js');
        self::assertFileExists($this->root . '/public/static/uploads/user.png');
        self::assertFileDoesNotExist($this->root . '/public/jse/old-entry.js');
        self::assertFileExists($this->root . '/public/_app.config.js');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            /* @var \SplFileInfo $fileInfo */
            $fileInfo->isDir() && !$fileInfo->isLink()
                ? @rmdir($fileInfo->getPathname())
                : @unlink($fileInfo->getPathname());
        }
        @rmdir($path);
    }
}
