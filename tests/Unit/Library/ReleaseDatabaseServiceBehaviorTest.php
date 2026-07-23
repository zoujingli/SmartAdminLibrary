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

use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class ReleaseDatabaseServiceBehaviorTest extends TestCase
{
    public function testInstallRestoreRejectsDatabasePrefixMismatchBeforeOpeningDatabase(): void
    {
        $root = sys_get_temp_dir() . '/smartadmin-release-prefix-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/storage/extra/release.staging-prefix', 0755, true));
        self::assertTrue(mkdir($root . '/config', 0755, true));
        file_put_contents($root . '/composer.json', "{}\n");
        $root = (string)realpath($root);

        $script = $root . '/probe-database-prefix.php';
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        file_put_contents($script, str_replace('__AUTOLOAD__', $autoload, <<<'PHP'
<?php

declare(strict_types=1);

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Library\Service\ReleaseDatabaseService;
use Psr\Container\ContainerInterface;

define('BASE_PATH', __DIR__);
require __AUTOLOAD__;

$staging = __DIR__ . '/storage/extra/release.staging-prefix';
$schemas = [];
foreach (['mysql', 'sqlite'] as $driver) {
    $filename = 'database.schema.' . $driver . '.gz';
    file_put_contents($staging . '/' . $filename, gzencode('schema-' . $driver, 9));
    $schemas[$driver] = [
        'driver' => $driver,
        'file' => $filename,
        'sha256' => hash_file('sha256', $staging . '/' . $filename),
    ];
}
file_put_contents($staging . '/database.data.gz', gzencode('', 9));
file_put_contents($staging . '/database.meta.json', json_encode([
    'format_version' => 2,
    'kind' => 'install',
    'with_data' => false,
    'database_prefix' => 'built_',
    'schema' => $schemas,
    'data' => [
        'file' => 'database.data.gz',
        'sha256' => hash_file('sha256', $staging . '/database.data.gz'),
    ],
], JSON_THROW_ON_ERROR));

$config = new class implements ConfigInterface {
    public function get($key, $default = null): mixed
    {
        return match ($key) {
            'databases.default.prefix' => 'target_',
            'release.backup_tables', 'release.ignore_tables' => [],
            default => $default,
        };
    }

    public function set($key, $value): void {}

    public function has($key): bool
    {
        return in_array($key, ['databases.default.prefix', 'release.backup_tables', 'release.ignore_tables'], true);
    }
};
$container = new class($config) implements ContainerInterface {
    public function __construct(private readonly ConfigInterface $config) {}

    public function get(string $id): mixed
    {
        if ($id === ConfigInterface::class) {
            return $this->config;
        }
        throw new RuntimeException('Database service was accessed before prefix validation: ' . $id);
    }

    public function has(string $id): bool
    {
        return $id === ConfigInterface::class;
    }
};
ApplicationContext::setContainer($container);
putenv('RELEASE_INSTALL_STAGING_DIR=' . $staging);

$service = new ReleaseDatabaseService();
$mergeError = '';
try {
    (new ReflectionMethod(ReleaseDatabaseService::class, 'assertInstallStagingMeta'))->invoke(
        $service,
        [
            'format_version' => 2,
            'kind' => 'install',
            'database_prefix' => 'built_',
            'schema_tables' => [],
            'backup_tables' => [],
            'ignore_tables' => [],
        ],
        $staging,
        [],
        ['backup_tables' => [], 'ignore_tables' => []],
        'target_'
    );
} catch (RuntimeException $exception) {
    $mergeError = $exception->getMessage();
}

$restoreError = '';
try {
    $service->restore(true, false, false, true);
} catch (RuntimeException $exception) {
    $restoreError = $exception->getMessage();
}
echo json_encode([
    'merge_error' => $mergeError,
    'restore_error' => $restoreError,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP));

        try {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errorOutput ?: $output);
            $result = json_decode(trim((string)$output), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('SQLite and MySQL release database prefixes differ.', $result['merge_error']);
            self::assertStringContainsString(
                'Release install database prefix mismatch: package=built_, target=target_',
                (string)$result['restore_error']
            );
            self::assertStringNotContainsString('Database service was accessed', (string)$result['restore_error']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testInstallStagingPathRejectsSymlinkAndNestedDirectoryWithoutDatabaseConnection(): void
    {
        $root = sys_get_temp_dir() . '/smartadmin-release-staging-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/storage/extra/release.staging-valid', 0755, true));
        self::assertTrue(mkdir($root . '/config', 0755, true));
        file_put_contents($root . '/composer.json', "{}\n");
        $root = (string)realpath($root);
        self::assertTrue(mkdir($root . '/outside', 0755, true));
        self::assertTrue(symlink($root . '/outside', $root . '/storage/extra/release.staging-link'));

        $script = $root . '/probe-staging-path.php';
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        file_put_contents($script, str_replace('__AUTOLOAD__', $autoload, <<<'PHP'
<?php

declare(strict_types=1);

use Library\Service\ReleaseDatabaseService;

define('BASE_PATH', __DIR__);
require __AUTOLOAD__;

$method = new ReflectionMethod(ReleaseDatabaseService::class, 'assertInstallStagingPath');
$service = new ReleaseDatabaseService();
$probe = static function (string $path) use ($method, $service): string {
    try {
        return (string)$method->invoke($service, $path);
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
};

echo json_encode([
    'valid' => $probe(__DIR__ . '/storage/extra/release.staging-valid'),
    'missing' => $probe(__DIR__ . '/storage/extra/release.staging-new'),
    'symlink' => $probe(__DIR__ . '/storage/extra/release.staging-link'),
    'nested' => $probe(__DIR__ . '/storage/extra/release.staging-valid/child'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP));

        try {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errorOutput ?: $output);
            $result = json_decode(trim((string)$output), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame($root . '/storage/extra/release.staging-valid', $result['valid']);
            self::assertSame($root . '/storage/extra/release.staging-new', $result['missing']);
            self::assertStringContainsString('outside the controlled build directory', (string)$result['symlink']);
            self::assertStringContainsString('outside the controlled build directory', (string)$result['nested']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testInstallPollutionFailsBeforeWritesWhileRuntimeBackupStillWorks(): void
    {
        $fixture = $this->createFixture();

        try {
            $sentinelBefore = [];
            foreach ($fixture['sentinels'] as $path) {
                $sentinelBefore[$path] = hash_file('sha256', $path);
            }

            // Library 独立导出不依赖 symfony/process，使用 PHP 标准子进程验证真实写盘行为。
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $fixture['script'], $fixture['database']],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $fixture['root']
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errorOutput ?: $output);
            $result = json_decode(trim((string)$output), true, 512, JSON_THROW_ON_ERROR);

            self::assertStringContainsString('backup_*/bak_* tables detected', (string)$result['install_error']);
            self::assertStringContainsString('backup_legacy', (string)$result['install_error']);
            foreach ($fixture['sentinels'] as $path) {
                self::assertSame($sentinelBefore[$path], hash_file('sha256', $path));
            }

            $runtime = $result['runtime'];
            self::assertSame('backup', $runtime['kind']);
            self::assertFalse($runtime['install']);
            self::assertFalse($runtime['dry_run']);
            self::assertSame(1, $runtime['data_rows']);
            self::assertSame(['active_data'], $runtime['data_tables']);
            self::assertFileExists($runtime['schema_path']);
            self::assertFileExists($runtime['data_path']);
            self::assertFileExists($runtime['meta_path']);

            $meta = json_decode((string)file_get_contents($runtime['meta_path']), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('backup', $meta['kind']);
            self::assertArrayNotHasKey('database_prefix', $meta);
            self::assertContains('sa_active_data', $meta['schema_tables']);
            self::assertContains('sa_backup_legacy', $meta['schema_tables']);
            self::assertSame('database.schema.sqlite.gz', basename((string)$result['install_preview']['schema_path']));
            self::assertSame(2, $result['install_preview']['meta']['format_version']);
            self::assertSame('sa_', $result['install_preview']['meta']['database_prefix']);
            self::assertStringContainsString('Legacy single-schema release install package is not supported', (string)$result['legacy_error']);

            $runtimeFull = $result['runtime_full'];
            self::assertTrue($runtimeFull['with_data']);
            self::assertSame(2, $runtimeFull['data_rows']);
            self::assertSame(['active_data', 'backup_legacy'], $runtimeFull['data_tables']);
            self::assertSame('database.schema.gz', basename((string)$runtimeFull['schema_path']));
            $runtimeFullMeta = json_decode(
                (string)file_get_contents($runtimeFull['meta_path']),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame('backup', $runtimeFullMeta['kind']);
            self::assertTrue($runtimeFullMeta['with_data']);
            self::assertSame(['active_data', 'backup_legacy'], $runtimeFullMeta['data_tables']);
            self::assertArrayNotHasKey('format_version', $runtimeFullMeta);
        } finally {
            $this->removeDirectory($fixture['root']);
        }
    }

    /**
     * 使用独立进程固定 BASE_PATH 和容器连接，保证安装包哨兵及运行备份都只写入临时项目根。
     *
     * @return array{root:string,database:string,script:string,sentinels:list<string>}
     */
    private function createFixture(): array
    {
        $root = sys_get_temp_dir() . '/smartadmin-release-database-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/config', 0755, true));
        self::assertTrue(mkdir($root . '/storage/extra/release', 0755, true));
        file_put_contents($root . '/composer.json', "{}\n");

        $database = $root . '/release.sqlite';
        $pdo = new PDO('sqlite:' . $database);
        $pdo->exec('CREATE TABLE sa_active_data (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO sa_active_data (value) VALUES ('kept')");
        $pdo->exec('CREATE TABLE sa_backup_legacy (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO sa_backup_legacy (value) VALUES ('pollution')");
        unset($pdo);

        $sentinels = [];
        foreach (['database.schema.gz', 'database.data.gz', 'database.meta.json'] as $filename) {
            $path = $root . '/storage/extra/release/' . $filename;
            file_put_contents($path, 'existing-install-' . $filename);
            $sentinels[] = $path;
        }

        $script = $root . '/exercise-release-database.php';
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        file_put_contents($script, str_replace('__AUTOLOAD__', $autoload, <<<'PHP'
<?php

declare(strict_types=1);

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\SQLite\SQLiteConnection;
use Hyperf\DbConnection\Db;
use Doctrine\DBAL\DriverManager;
use Library\Service\ReleaseDataRepairService;
use Library\Service\ReleaseDatabaseService;
use Psr\Container\ContainerInterface;

define('BASE_PATH', __DIR__);
require __AUTOLOAD__;

$database = (string)($argv[1] ?? '');
$container = new class($database) implements ContainerInterface {
    private ConfigInterface $config;

    private ConnectionResolverInterface $resolver;

    private Db $db;

    public function __construct(string $database)
    {
        $this->config = new class($database) implements ConfigInterface {
            public function __construct(private readonly string $database) {}

            public function get($key, $default = null): mixed
            {
                return match ($key) {
                    'databases.default' => [
                        'driver' => 'sqlite',
                        'database' => $this->database,
                        'prefix' => 'sa_',
                    ],
                    'databases.default.driver' => 'sqlite',
                    'databases.default.prefix' => 'sa_',
                    'release.backup_tables' => ['active_data'],
                    'release.ignore_tables' => [],
                    default => $default,
                };
            }

            public function set($key, $value): void {}

            public function has($key): bool
            {
                return in_array($key, [
                    'databases.default',
                    'databases.default.driver',
                    'databases.default.prefix',
                    'release.backup_tables',
                    'release.ignore_tables',
                ], true);
            }
        };
        $connection = new SQLiteConnection(new PDO('sqlite:' . $database), $database, 'sa_');
        $this->resolver = new ConnectionResolver(['default' => $connection]);
        $this->db = new Db($this);
    }

    public function get(string $id): mixed
    {
        return match ($id) {
            ConfigInterface::class => $this->config,
            ConnectionResolverInterface::class => $this->resolver,
            Db::class => $this->db,
            ReleaseDataRepairService::class => new ReleaseDataRepairService([]),
            default => throw new RuntimeException('Unknown fixture service: ' . $id),
        };
    }

    public function has(string $id): bool
    {
        return in_array($id, [ConfigInterface::class, ConnectionResolverInterface::class, Db::class, ReleaseDataRepairService::class], true);
    }
};
ApplicationContext::setContainer($container);

$service = new ReleaseDatabaseService();
$installError = '';
try {
    $service->backup(false, true, false);
} catch (RuntimeException $exception) {
    $installError = $exception->getMessage();
}
if ($installError === '') {
    throw new RuntimeException('Polluted install package was not rejected.');
}

$runtime = $service->backup(false, false, false);
$runtimeFull = $service->backup(true, false, false);

$staging = __DIR__ . '/storage/extra/release.staging-behavior';
mkdir($staging, 0755, true);
$schema = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database])
    ->createSchemaManager()
    ->introspectSchema();
$schemas = [];
foreach (['mysql', 'sqlite'] as $driver) {
    $filename = 'database.schema.' . $driver . '.gz';
    file_put_contents($staging . '/' . $filename, gzencode(serialize($schema), 9));
    $schemas[$driver] = [
        'driver' => $driver,
        'file' => $filename,
        'sha256' => hash_file('sha256', $staging . '/' . $filename),
    ];
}
file_put_contents($staging . '/database.data.gz', gzencode('', 9));
file_put_contents($staging . '/database.meta.json', json_encode([
    'format_version' => 2,
    'kind' => 'install',
    'with_data' => false,
    'database_prefix' => 'sa_',
    'backup_id' => null,
    'schema' => $schemas,
    'data' => [
        'file' => 'database.data.gz',
        'sha256' => hash_file('sha256', $staging . '/database.data.gz'),
    ],
], JSON_THROW_ON_ERROR));
putenv('RELEASE_INSTALL_STAGING_DIR=' . $staging);
$installPreview = $service->restore(true, false, false, true);

file_put_contents($staging . '/database.schema.gz', gzencode(serialize($schema), 9));
file_put_contents($staging . '/database.meta.json', json_encode([
    'schema' => 1,
    'kind' => 'install',
    'with_data' => false,
], JSON_THROW_ON_ERROR));
$legacyError = '';
try {
    $service->restore(true, false, false, true);
} catch (RuntimeException $exception) {
    $legacyError = $exception->getMessage();
}
echo json_encode([
    'install_error' => $installError,
    'runtime' => $runtime,
    'runtime_full' => $runtimeFull,
    'install_preview' => $installPreview,
    'legacy_error' => $legacyError,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP));

        return [
            'root' => $root,
            'database' => $database,
            'script' => $script,
            'sentinels' => $sentinels,
        ];
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
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
