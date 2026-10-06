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

use Library\Service\PluginManagerService;
use Library\Support\PluginManager\PluginArchive;
use Library\Support\PluginManager\PluginCodeTransaction;
use Library\Support\PluginManager\PluginComposerManager;
use Library\Support\PluginManager\PluginMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PluginArchive::class)]
#[CoversClass(PluginCodeTransaction::class)]
#[CoversClass(PluginComposerManager::class)]
#[CoversClass(PluginMetadata::class)]
#[CoversClass(PluginManagerService::class)]
final class PluginManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/xadmin-plugin-manager-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        parent::tearDown();
    }

    public function testMetadataUsesConsistentVersionAndExplicitTableRules(): void
    {
        $plugin = $this->makePlugin('DemoShop', [
            'composer_version' => '1.2.3',
            'plugin_version' => '1.2.3',
            'tables' => ['demo_order'],
            'table_prefixes' => ['demo_ext_'],
        ]);

        $metadata = PluginMetadata::load($plugin);

        self::assertSame('demo-shop', $metadata->code);
        self::assertSame('1.2.3', $metadata->version);
        self::assertSame('vendor/smart-plugin-demo-shop', $metadata->composerName);
        self::assertSame('DemoShop', $metadata->module);
        self::assertSame(['demo_order'], $metadata->tables);
        self::assertSame(['demo_ext_'], $metadata->tablePrefixes);
        self::assertSame(['demo.index', 'demo.order.index'], $metadata->menuCodes);
    }

    public function testMetadataFallsBackToCodeTablePrefixAndRejectsVersionDrift(): void
    {
        $plugin = $this->makePlugin('DemoOnlyPluginJson', [
            'composer_version' => '',
            'plugin_version' => '2.0.0',
        ]);

        $metadata = PluginMetadata::load($plugin);
        self::assertSame('2.0.0', $metadata->version);
        self::assertSame(['demo_only_plugin_json_'], $metadata->tablePrefixes);

        $drift = $this->makePlugin('DemoDrift', [
            'composer_version' => '1.0.0',
            'plugin_version' => '2.0.0',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('插件版本不一致');
        PluginMetadata::load($drift);
    }

    public function testEncryptedPackageRequiresPasswordAndExtractsSafely(): void
    {
        $plugin = $this->makePlugin('DemoZip', [
            'composer_version' => '1.0.0',
            'plugin_version' => '1.0.0',
        ]);
        $metadata = PluginMetadata::load($plugin);
        $archive = new PluginArchive($this->root . '/tmp');
        $zip = $archive->createPackage($metadata, $this->root . '/out', 'secret');

        self::assertSame('plugin-demo-zip-1.0.0.zip', basename($zip));

        $this->expectException(\RuntimeException::class);
        $archive->extract($zip);
    }

    public function testEncryptedPackageExtractsWithPassword(): void
    {
        $plugin = $this->makePlugin('DemoZipOk', [
            'composer_version' => '1.0.0',
            'plugin_version' => '1.0.0',
        ]);
        $metadata = PluginMetadata::load($plugin);
        $archive = new PluginArchive($this->root . '/tmp');
        $zip = $archive->createPackage($metadata, $this->root . '/out', 'secret');
        $extracted = $archive->extract($zip, 'secret');

        self::assertFileExists($extracted['root'] . '/composer.json');
        self::assertFileExists($extracted['root'] . '/plugin.json');
        self::assertFileExists($extracted['root'] . '/src/Provider.php');
        self::assertFileExists($extracted['root'] . '/stc/view/demo/index.vue');
    }

    public function testArchiveRejectsZipTraversalPath(): void
    {
        $zipPath = $this->root . '/evil.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('composer.json', '{}');
        $zip->addFromString('plugin.json', '{}');
        $zip->addFromString('../evil.php', 'bad');
        $zip->close();

        $archive = new PluginArchive($this->root . '/tmp');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('非法路径');
        $archive->extract($zipPath);
    }

    public function testCodeOnlyBackupContainsMetadataButNoDatabaseSnapshots(): void
    {
        $plugin = $this->makePlugin('DemoCodeBackup', [
            'composer_version' => '',
            'plugin_version' => '1.0.0',
        ]);
        $metadata = PluginMetadata::load($plugin);
        $archive = new PluginArchive($this->root . '/tmp');
        $zipPath = $archive->createBackup($metadata, $this->root . '/backups', null, null, [
            'format' => 'xadmin-plugin-backup',
            'version' => 1,
            'with_data' => false,
            'tables' => [],
            'rows' => 0,
        ]);

        self::assertMatchesRegularExpression('/demo-code-backup-1\.0\.0-backup-\d{8}-\d{6}\.zip$/', $zipPath);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        self::assertNotFalse($zip->locateName('_xadmin/plugin-backup.json'));
        self::assertFalse($zip->locateName('_xadmin/database.schema.gz'));
        self::assertFalse($zip->locateName('_xadmin/database.data.gz'));
        $meta = json_decode((string)$zip->getFromName('_xadmin/plugin-backup.json'), true);
        $zip->close();

        self::assertIsArray($meta);
        self::assertFalse($meta['with_data']);
    }

    public function testDataBackupContainsDatabaseSnapshotsAndSupportsPassword(): void
    {
        $plugin = $this->makePlugin('DemoDataBackup', [
            'composer_version' => '',
            'plugin_version' => '1.0.0',
        ]);
        $schema = $this->root . '/database.schema.gz';
        $data = $this->root . '/database.data.gz';
        file_put_contents($schema, (string)gzencode('schema'));
        file_put_contents($data, (string)gzencode('data'));

        $metadata = PluginMetadata::load($plugin);
        $archive = new PluginArchive($this->root . '/tmp');
        $zipPath = $archive->createBackup($metadata, $this->root . '/backups', $schema, $data, [
            'format' => 'xadmin-plugin-backup',
            'version' => 1,
            'with_data' => true,
            'tables' => ['demo_data_backup_order'],
            'rows' => 3,
        ], 'secret');

        $this->expectException(\RuntimeException::class);
        $archive->extract($zipPath);
    }

    public function testDataBackupExtractsWithPasswordAndKeepsSnapshots(): void
    {
        $plugin = $this->makePlugin('DemoDataBackupOk', [
            'composer_version' => '',
            'plugin_version' => '1.0.0',
        ]);
        $schema = $this->root . '/database.schema.gz';
        $data = $this->root . '/database.data.gz';
        file_put_contents($schema, (string)gzencode('schema'));
        file_put_contents($data, (string)gzencode('data'));

        $metadata = PluginMetadata::load($plugin);
        $archive = new PluginArchive($this->root . '/tmp');
        $zipPath = $archive->createBackup($metadata, $this->root . '/backups', $schema, $data, [
            'format' => 'xadmin-plugin-backup',
            'version' => 1,
            'with_data' => true,
            'tables' => ['demo_data_backup_ok_order'],
            'rows' => 3,
        ], 'secret');
        $extracted = $archive->extract($zipPath, 'secret');

        self::assertIsArray($extracted['backup_meta']);
        self::assertTrue($extracted['backup_meta']['with_data']);
        self::assertFileExists($extracted['extract_dir'] . '/_xadmin/database.schema.gz');
        self::assertFileExists($extracted['extract_dir'] . '/_xadmin/database.data.gz');
    }

    public function testRestoreSourceUsesDefaultBackupDirectoryOnlyForPlainName(): void
    {
        $backupDir = $this->root . '/runtime/plugin/backups';
        mkdir($backupDir, 0777, true);
        file_put_contents($backupDir . '/demo-1.0.0-backup-20260522-123000.zip', 'zip');
        mkdir($this->root . '/custom', 0777, true);
        file_put_contents($this->root . '/custom/demo-backup', 'zip');

        $service = new PluginManagerService($this->root, $this->root);
        $method = new \ReflectionMethod(PluginManagerService::class, 'resolveZipSource');
        $method->setAccessible(true);

        self::assertSame(
            $backupDir . '/demo-1.0.0-backup-20260522-123000.zip',
            $method->invoke($service, 'demo-1.0.0-backup-20260522-123000', $backupDir, true)
        );
        self::assertSame(
            $this->root . '/custom/demo-backup',
            $method->invoke($service, 'custom/demo-backup', $backupDir, true)
        );
    }

    public function testComposerManagerAddsPathRepositoryVersionOptionAndRemovesIt(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'require' => ['php' => '>=8.4'],
            'repositories' => [],
        ], JSON_PRETTY_PRINT));
        $plugin = $this->makePlugin('DemoComposer', [
            'composer_version' => '',
            'plugin_version' => '1.5.0',
        ]);
        $metadata = PluginMetadata::load($plugin);
        $manager = new PluginComposerManager($this->root);

        $manager->addPathPackage($metadata, 'plugin/DemoComposer');
        $rootComposer = json_decode((string)file_get_contents($this->root . '/composer.json'), true);

        self::assertSame('^1.5', $rootComposer['require']['vendor/smart-plugin-demo-composer']);
        self::assertSame('plugin/DemoComposer', $rootComposer['repositories'][0]['url']);
        self::assertSame('1.5.0', $rootComposer['repositories'][0]['options']['versions']['vendor/smart-plugin-demo-composer']);

        $manager->removePathPackage($metadata, 'plugin/DemoComposer');
        $rootComposer = json_decode((string)file_get_contents($this->root . '/composer.json'), true);
        self::assertArrayNotHasKey('vendor/smart-plugin-demo-composer', $rootComposer['require']);
        self::assertSame([], $rootComposer['repositories']);
    }

    #[DataProvider('activationFailures')]
    public function testFailedActivationPreservesPreviousCodeAndComposerState(string $operation, bool $installed, string $failure): void
    {
        $candidate = $this->makePlugin('DemoActivation', ['composer_version' => '1.0.0', 'plugin_version' => '1.0.0']);
        file_put_contents($candidate . '/marker.txt', 'candidate');
        mkdir($this->root . '/bin');
        mkdir($this->root . '/vendor/composer', 0777, true);
        mkdir($this->root . '/vendor/bin');
        mkdir($this->root . '/plugin');
        $target = $this->root . '/plugin/DemoActivation';
        if ($installed) {
            rename($candidate, $target);
            file_put_contents($target . '/marker.txt', 'previous');
            file_put_contents($target . '/local-only.txt', 'preserve-user-file');
            $candidate = $this->makePlugin('DemoActivation', ['composer_version' => '1.0.0', 'plugin_version' => '1.0.0']);
            file_put_contents($candidate . '/marker.txt', 'candidate');
            symlink('../../plugin/DemoActivation', $this->root . '/vendor/bin/plugin-link');
        }
        $originalComposer = "{\n  \"name\": \"fixture/root\",\n  \"require\": {},\n  \"repositories\": []\n}\n";
        file_put_contents($this->root . '/composer.json', $originalComposer);
        if ($installed) {
            file_put_contents($this->root . '/composer.lock', '{"packages":[]}');
        }
        file_put_contents($this->root . '/vendor/composer/installed.json', 'previous-vendor');
        file_put_contents($this->root . '/vendor/bin/tool', 'previous-tool');
        chmod($this->root . '/vendor/bin/tool', 0755);
        // 子进程模拟预检失败，或预检通过后 Composer 已写锁文件/vendor 才失败的情况。
        $script = '#!' . PHP_BINARY . "\n<?php\n" . '$failure = ' . var_export($failure, true) . ";\n" . <<<'PHP'
$args = array_slice($argv, 1);
$preflight = in_array('--dry-run', $args, true);
file_put_contents('commands.log', json_encode($args) . "\n", FILE_APPEND);
if ($preflight) {
    if ($failure === 'preflight') { fwrite(STDERR, 'fixture dependency conflict'); exit(2); }
    exit(0);
}
file_put_contents('composer.lock', 'partially-updated-lock');
file_put_contents('vendor/composer/installed.json', 'partially-updated-vendor');
unlink('vendor/bin/tool');
file_put_contents('vendor/new-file', 'partial dependency');
fwrite(STDERR, 'fixture installation failed');
exit(2);
PHP;
        file_put_contents($this->root . '/bin/smart.php', $script);
        chmod($this->root . '/bin/smart.php', 0755);
        $archive = new PluginArchive($this->root . '/tmp');
        $metadata = PluginMetadata::load($candidate);
        $zip = $operation === 'install'
            ? $archive->createPackage($metadata, $this->root . '/out')
            : $archive->createBackup($metadata, $this->root . '/out', null, null, ['format' => 'xadmin-plugin-backup', 'with_data' => false]);
        $service = new PluginManagerService($this->root, $this->root);
        try {
            if ($operation === 'install') {
                $service->install($zip, force: true, migrate: false, sync: false);
            } else {
                $service->restore($zip, force: true, migrate: false, sync: false, restoreData: false);
            }
            self::fail('失败的 Composer 不应返回成功');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('fixture', $exception->getMessage());
        }
        self::assertSame($originalComposer, file_get_contents($this->root . '/composer.json'));
        self::assertSame('previous-vendor', file_get_contents($this->root . '/vendor/composer/installed.json'));
        self::assertSame('previous-tool', file_get_contents($this->root . '/vendor/bin/tool'));
        self::assertSame(0755, fileperms($this->root . '/vendor/bin/tool') & 0777);
        self::assertFileDoesNotExist($this->root . '/vendor/new-file');
        if ($installed) {
            self::assertSame('previous', file_get_contents($target . '/marker.txt'));
            self::assertSame('preserve-user-file', file_get_contents($target . '/local-only.txt'));
            self::assertSame('../../plugin/DemoActivation', readlink($this->root . '/vendor/bin/plugin-link'));
            self::assertSame('{"packages":[]}', file_get_contents($this->root . '/composer.lock'));
        } else {
            self::assertDirectoryDoesNotExist($target);
            self::assertFileDoesNotExist($this->root . '/composer.lock');
        }
        $commands = file($this->root . '/commands.log', FILE_IGNORE_NEW_LINES);
        self::assertCount($failure === 'preflight' ? 1 : 2, $commands);
        self::assertContains('--dry-run', json_decode($commands[0], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([], glob($this->root . '/.plugin-preflight-*'));
    }

    public static function activationFailures(): array
    {
        $cases = [];
        foreach (['install', 'restore'] as $operation) {
            foreach ([true, false] as $installed) {
                foreach (['preflight', 'activation'] as $failure) {
                    $cases[$operation . '-' . (int)$installed . '-' . $failure] = [$operation, $installed, $failure];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('realComposerOperations')]
    public function testRealComposerGateKeepsOldCallbackOrActivatesCompatiblePackage(string $operation, bool $compatible, bool $scriptFailure = false): void
    {
        $composerBinary = (string)getenv('COMPOSER_BINARY');
        if ($composerBinary === '') {
            foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
                if (is_file($directory . '/composer')) {
                    $composerBinary = $directory . '/composer';
                    break;
                }
            }
        }
        self::assertFileExists($composerBinary, '独立导出 CI 已安装 Composer；自定义位置可用 COMPOSER_BINARY 指定 PHP/Phar 入口');
        mkdir($this->root . '/bin');
        mkdir($this->root . '/packages/Base/src', 0777, true);
        mkdir($this->root . '/plugin');
        $base = ['name' => 'fixture/base', 'version' => '1.0.0', 'autoload' => ['psr-4' => ['FixtureBase\\' => 'src/']]];
        if ($compatible) {
            $base['provide'] = ['fixture/new-feature' => '1.0.0'];
            file_put_contents($this->root . '/packages/Base/src/NewFeature.php', '<?php namespace FixtureBase; final class NewFeature { public static function value(): string { return "new callback"; } }');
        }
        file_put_contents($this->root . '/packages/Base/composer.json', json_encode($base, JSON_THROW_ON_ERROR));
        $old = $this->makePlugin('DemoReal', ['composer_version' => '1.0.0', 'plugin_version' => '1.0.0']);
        file_put_contents($old . '/src/Probe.php', '<?php namespace Plugin\DemoReal; final class Probe { public static function value(): string { return "old callback"; } }');
        rename($old, $this->root . '/plugin/DemoReal');
        $target = $this->root . '/plugin/DemoReal';
        $rootComposer = [
            'name' => 'fixture/root', 'version' => '1.0.0',
            'repositories' => [
                ['type' => 'path', 'url' => 'plugin/DemoReal', 'options' => ['symlink' => true]],
                ['type' => 'path', 'url' => 'packages/Base', 'options' => ['symlink' => true]],
                ['packagist.org' => false],
            ],
            'require' => ['fixture/base' => '^1.0', 'vendor/smart-plugin-demo-real' => '^1.0'],
        ];
        if ($scriptFailure) {
            // 真实 Composer 已安装并生成 autoload 后，根项目脚本失败仍要恢复旧插件及其依赖状态。
            $rootComposer['scripts'] = ['post-update-cmd' => '@php -r "fwrite(STDERR, \'fixture post-update failure\'); exit(23);"'];
        }
        file_put_contents($this->root . '/composer.json', json_encode($rootComposer, JSON_THROW_ON_ERROR));
        [$code, $output] = $this->runFixtureProcess([PHP_BINARY, $composerBinary, 'update', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins', '--no-audit']);
        self::assertSame(0, $code, $output);
        $before = [file_get_contents($this->root . '/composer.json'), file_get_contents($this->root . '/composer.lock')];
        $wrapper = '#!' . PHP_BINARY . "\n<?php\n" . '$command = ' . var_export([PHP_BINARY, $composerBinary], true) . ";\n" . <<<'PHP'
putenv('COMPOSER_HOME=' . dirname(__DIR__) . '/composer-home');
putenv('COMPOSER_DISABLE_NETWORK=1');
putenv('COMPOSER_AUTH={}');
$process = proc_open(array_merge($command, array_slice($argv, 2)), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
exit(proc_close($process));
PHP;
        file_put_contents($this->root . '/bin/smart.php', $wrapper);
        chmod($this->root . '/bin/smart.php', 0755);
        $candidate = $this->makePlugin('DemoReal', ['composer_version' => '1.0.0', 'plugin_version' => '1.0.0']);
        $metadata = PluginMetadata::load($candidate);
        $newComposer = $metadata->composer;
        $newComposer['require'] = ['fixture/base' => '^1.0', 'fixture/new-feature' => '^1.0'];
        file_put_contents($candidate . '/composer.json', json_encode($newComposer, JSON_THROW_ON_ERROR));
        file_put_contents($candidate . '/src/Probe.php', '<?php namespace Plugin\DemoReal; final class Probe { public static function value(): string { return \FixtureBase\NewFeature::value(); } }');
        $archive = new PluginArchive($this->root . '/tmp');
        $metadata = PluginMetadata::load($candidate);
        $zip = $operation === 'install'
            ? $archive->createPackage($metadata, $this->root . '/out')
            : $archive->createBackup($metadata, $this->root . '/out', null, null, ['format' => 'xadmin-plugin-backup', 'with_data' => false]);
        $service = new PluginManagerService($this->root, $this->root);
        try {
            $result = $operation === 'install'
                ? $service->install($zip, force: true, migrate: false, sync: false)
                : $service->restore($zip, force: true, migrate: false, sync: false, restoreData: false);
            self::assertTrue($compatible && !$scriptFailure, '预检或实际 Composer 失败都不能返回成功');
            self::assertSame(0, $result['composer_run']['exit_code']);
        } catch (\RuntimeException $exception) {
            self::assertTrue(!$compatible || $scriptFailure, $exception->getMessage());
            self::assertStringContainsString($scriptFailure ? 'fixture post-update failure' : 'fixture/new-feature', $exception->getMessage());
            self::assertSame($before, [file_get_contents($this->root . '/composer.json'), file_get_contents($this->root . '/composer.lock')]);
        }
        // 使用独立 PHP 进程从真实 vendor autoload/path 软链调用，防止当前进程缓存类掩盖坏状态。
        [$code, $output] = $this->runFixtureProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo Plugin\DemoReal\Probe::value();']);
        self::assertSame(0, $code, $output);
        self::assertSame($compatible && !$scriptFailure ? 'new callback' : 'old callback', $output);
        self::assertSame([], glob($this->root . '/.plugin-preflight-*'));
        self::assertSame([], glob($this->root . '/runtime/plugin/tmp/extract-*'));
        self::assertStringNotContainsString('extract-', (string)file_get_contents($this->root . '/composer.lock'));
        self::assertFileExists($target . '/src/Probe.php');
    }

    public static function realComposerOperations(): array
    {
        return [['install', false], ['restore', false], ['install', true], ['restore', true], ['install', true, true], ['restore', true, true]];
    }

    public function testCodeTransactionRestoresCustomVendorAndBinDirectory(): void
    {
        mkdir($this->root . '/plugin/Demo', 0777, true);
        mkdir($this->root . '/dependencies/vendor', 0777, true);
        mkdir($this->root . '/tools');
        file_put_contents($this->root . '/composer.json', json_encode(['config' => ['vendor-dir' => 'dependencies/vendor', 'bin-dir' => 'tools']], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/plugin/Demo/value', 'old-code');
        file_put_contents($this->root . '/dependencies/vendor/value', 'old-vendor');
        file_put_contents($this->root . '/tools/tool', 'old-bin');
        $transaction = new PluginCodeTransaction($this->root, new PluginArchive($this->root . '/tmp'));
        try {
            $transaction->run('plugin/Demo', static fn () => null, function (): void {
                file_put_contents($this->root . '/plugin/Demo/value', 'new-code');
                file_put_contents($this->root . '/dependencies/vendor/value', 'new-vendor');
                file_put_contents($this->root . '/tools/tool', 'new-bin');
                throw new \RuntimeException('activation failed');
            });
            self::fail('必须转发安装失败');
        } catch (\RuntimeException $exception) {
            self::assertSame('activation failed', $exception->getMessage());
        }
        self::assertSame('old-code', file_get_contents($this->root . '/plugin/Demo/value'));
        self::assertSame('old-vendor', file_get_contents($this->root . '/dependencies/vendor/value'));
        self::assertSame('old-bin', file_get_contents($this->root . '/tools/tool'));
    }

    public function testCodeTransactionRetainsRecoveryMaterialsOnUnsafeRollbackPath(): void
    {
        mkdir($this->root . '/plugin/Demo', 0777, true);
        mkdir($this->root . '/unrelated/Demo', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->root . '/plugin/Demo/value', 'old-code');
        file_put_contents($this->root . '/unrelated/Demo/value', 'untouched');
        $transaction = new PluginCodeTransaction($this->root, new PluginArchive($this->root . '/tmp'));
        try {
            $transaction->run('plugin/Demo', static fn () => null, function (): void {
                // 模拟 Composer 脚本改变父目录；回滚绝不能跟随新软链删除不相关数据。
                rename($this->root . '/plugin', $this->root . '/original-plugin');
                symlink($this->root . '/unrelated', $this->root . '/plugin');
                throw new \RuntimeException('activation failed');
            });
            self::fail('不安全回滚必须失败并保留恢复材料');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('回滚未完成', $exception->getMessage());
            self::assertSame('activation failed', $exception->getPrevious()?->getMessage());
        }
        self::assertSame('untouched', file_get_contents($this->root . '/unrelated/Demo/value'));
        $backups = glob($this->root . '/tmp/code-transaction-*');
        self::assertCount(1, $backups);
        self::assertSame('old-code', file_get_contents($backups[0] . '/0/value'));
        self::assertSame(0700, fileperms($backups[0]) & 0777);
    }

    public function testConcurrentMutationCannotEnterWhileCodeTransactionHoldsLock(): void
    {
        $archive = new PluginArchive($this->root . '/tmp');
        $transaction = new PluginCodeTransaction($this->root, $archive);
        $transaction->exclusive(function () use ($archive): void {
            foreach ([1, 2] as $attempt) {
                try {
                    (new PluginCodeTransaction($this->root, $archive))->exclusive(static fn () => null);
                    self::fail('另一个操作不能进入同一项目的代码事务');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('正在执行', $exception->getMessage());
                }
            }
            try {
                (new PluginManagerService($this->root, $this->root))->remove('nonexistent');
                self::fail('移除操作必须先取得同一把锁');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('正在执行', $exception->getMessage());
            }
        });
        self::assertSame('released', $transaction->exclusive(static fn () => 'released'));
    }

    /** 真实 Composer 仅使用本地 fixture 仓库，绝不访问平台账号或业务数据库。 */
    private function runFixtureProcess(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->root, array_merge(getenv(), ['COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_HOME' => $this->root . '/composer-home', 'COMPOSER_AUTH' => '{}', 'COMPOSER' => $this->root . '/composer.json']));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), (string)$output];
    }

    /**
     * @param array{composer_version?:string,plugin_version?:string,tables?:array<int,string>,table_prefixes?:array<int,string>} $options
     */
    private function makePlugin(string $module, array $options): string
    {
        $path = $this->root . '/' . $module;
        mkdir($path . '/src', 0777, true);
        mkdir($path . '/stc/view/demo', 0777, true);
        file_put_contents($path . '/src/Provider.php', "<?php\ndeclare(strict_types=1);\nnamespace Plugin\\{$module};\nfinal class Provider{}\n");
        file_put_contents($path . '/stc/view/demo/index.vue', '<template><div /></template>');

        $composer = [
            'name' => 'vendor/smart-plugin-' . PluginMetadata::kebab($module),
            'type' => 'library',
            'autoload' => [
                'psr-4' => [
                    'Plugin\\' . $module . '\\' => 'src/',
                ],
            ],
            'extra' => [
                'hyperf' => [
                    'config' => 'Plugin\\' . $module . '\Provider',
                ],
            ],
        ];
        if (($options['composer_version'] ?? '') !== '') {
            $composer['version'] = $options['composer_version'];
        }
        file_put_contents($path . '/composer.json', json_encode($composer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $manifest = [
            'plugin' => [
                'code' => PluginMetadata::kebab($module),
                'name' => $module,
                'version' => $options['plugin_version'] ?? '1.0.0',
                'view_root' => 'stc/view',
                'tables' => $options['tables'] ?? [],
                'table_prefixes' => $options['table_prefixes'] ?? [],
            ],
            'apps' => [[
                'id' => 990001,
                'name' => 'Demo',
                'code' => 'demo.index',
                'route' => '/demo',
                'type' => 'D',
                'menus' => [[
                    'id' => 990002,
                    'name' => 'DemoOrder',
                    'code' => 'demo.order.index',
                    'route' => '/demo/order',
                    'view' => 'demo/index.vue',
                ]],
            ]],
        ];
        if (($options['plugin_version'] ?? null) === '') {
            unset($manifest['plugin']['version']);
        }
        file_put_contents($path . '/plugin.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
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
        foreach ($iterator as $item) {
            /* @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
