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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\DecimalType;
use Hyperf\DbConnection\Db;
use Library\Constants\System;
use System\Service\AuthRegistryService;
use System\Service\MenuSeedSyncService;
use System\Service\SystemBootstrapService;
use System\Service\TenantRepairService;

use function Hyperf\Config\config;
use function Hyperf\Support\make;

/**
 * 发布数据库结构、安装包和运行备份服务。
 */
final class ReleaseDatabaseService
{
    public const INSTALL_FORMAT_VERSION = 2;

    public const INSTALL_DIR = 'storage/extra/release';

    public const BACKUP_DIR = 'runtime/backup';

    public const SCHEMA_FILENAME = 'database.schema.gz';

    public const MYSQL_SCHEMA_FILENAME = 'database.schema.mysql.gz';

    public const SQLITE_SCHEMA_FILENAME = 'database.schema.sqlite.gz';

    public const DATA_FILENAME = 'database.data.gz';

    public const META_FILENAME = 'database.meta.json';

    /**
     * 生成 release 备份或安装包。
     *
     * `--install` 写入 Phar 会携带的 storage 安装包，且只允许必要数据；默认写入 runtime/backup 运行备份。
     *
     * @return array<string,mixed>
     */
    public function backup(bool $withData = false, bool $install = false, bool $dryRun = false): array
    {
        if ($install && $withData) {
            throw new \InvalidArgumentException('Release install package cannot be built with --with-data.');
        }
        if ($install && System::isPharMode()) {
            throw new \RuntimeException('Release install package can only be built from source mode.');
        }

        $config = $this->releaseConfig();
        $connection = $this->makeConnection();
        $driver = $this->databaseDriver();
        $databasePrefix = $install || $withData ? $this->databasePrefix() : '';
        $schema = $connection->createSchemaManager()->introspectSchema();
        $schemaTables = $this->schemaTableNames($schema);
        if ($install) {
            // 物理表名可能包含 DB_PREFIX；污染判定必须先还原逻辑表名，避免前缀掩盖 backup_/bak_ 副本表。
            $pollution = $this->installPollutionTables($schemaTables);
            if ($pollution !== []) {
                throw new \RuntimeException(sprintf(
                    'Release install package refused: backup_*/bak_* tables detected (%s). Build from a fresh isolated database.',
                    implode(', ', $pollution)
                ));
            }
        }
        // DBAL 返回物理表名，而 Hyperf 查询会自动追加 DB_PREFIX；全量运行备份必须先还原逻辑表名，避免重复前缀后静默漏数。
        $dataTables = $withData ? $this->runtimeFullDataTables($schemaTables, $databasePrefix) : $config['backup_tables'];
        $backupId = $install ? null : $this->nextBackupId();
        $basePath = $install ? $this->installStagingPath() : runpath(self::BACKUP_DIR . '/' . $backupId);
        $schemaPath = $basePath . '/' . ($install ? $this->installSchemaFilename($driver) : self::SCHEMA_FILENAME);
        $dataPath = $basePath . '/' . self::DATA_FILENAME;
        $metaPath = $basePath . '/' . self::META_FILENAME;
        $dataReport = [
            'rows' => 0,
            'skipped_tables' => [],
        ];

        if (!$dryRun) {
            if ($install) {
                // 双方言结构依次写入同一 staging；只有打包器完成双目标恢复验证后才会原子发布到 final。
                $this->ensureDirectory($basePath);
                // 目录创建后再次解析真实路径，避免校验与首次写盘之间被替换成指向外部的软链。
                $this->assertInstallStagingPath($basePath);
                $existingMeta = is_file($metaPath) ? $this->readMetaFile($metaPath) : [];
                if ($existingMeta !== []) {
                    $this->assertInstallStagingMeta($existingMeta, $basePath, $schemaTables, $config, $databasePrefix);
                }

                $this->writeSchemaFile($schemaPath, $schema);
                $writeData = $this->shouldWriteInstallData($dataPath);
                if ($writeData) {
                    $dataReport = $this->writeDataFile($dataPath, $dataTables);
                } elseif (!is_file($dataPath) || filesize($dataPath) <= 0) {
                    throw new \RuntimeException('Release install staging data is missing before secondary schema capture.');
                } else {
                    $dataReport = [
                        'rows' => (int)($existingMeta['data_rows'] ?? 0),
                        'skipped_tables' => array_values((array)($existingMeta['skipped_tables'] ?? [])),
                    ];
                    $dataTables = $this->metaTables($existingMeta, 'data_tables');
                }

                $schemas = is_array($existingMeta['schema'] ?? null) ? $existingMeta['schema'] : [];
                $schemas[$driver] = [
                    'driver' => $driver,
                    'file' => basename($schemaPath),
                    'sha256' => hash_file('sha256', $schemaPath),
                ];
                ksort($schemas);
                $this->writeMetaFile($metaPath, [
                    'format_version' => self::INSTALL_FORMAT_VERSION,
                    'kind' => 'install',
                    'with_data' => false,
                    'database_prefix' => $databasePrefix,
                    'created_at' => (string)($existingMeta['created_at'] ?? date(DATE_ATOM)),
                    'backup_id' => null,
                    'schema' => $schemas,
                    'data' => [
                        'file' => self::DATA_FILENAME,
                        'sha256' => hash_file('sha256', $dataPath),
                    ],
                    'schema_tables' => $schemaTables,
                    'data_tables' => $dataTables,
                    'backup_tables' => $config['backup_tables'],
                    'ignore_tables' => $config['ignore_tables'],
                    'data_rows' => (int)$dataReport['rows'],
                    'skipped_tables' => array_values((array)$dataReport['skipped_tables']),
                ]);
            } else {
                // 运行备份继续使用单方言 database.schema.gz，保持既有备份与恢复协议兼容。
                $this->writeSchemaFile($schemaPath, $schema);
                $dataReport = $this->writeDataFile($dataPath, $dataTables);
                $this->writeMetaFile($metaPath, [
                    'schema' => 1,
                    'kind' => 'backup',
                    'with_data' => $withData,
                    'created_at' => date(DATE_ATOM),
                    'backup_id' => $backupId,
                    'schema_tables' => $schemaTables,
                    'data_tables' => $dataTables,
                    'backup_tables' => $config['backup_tables'],
                    'ignore_tables' => $config['ignore_tables'],
                    'data_rows' => (int)$dataReport['rows'],
                ]);
            }
            if (!$install && is_string($backupId)) {
                $this->writeLatestBackupId($backupId);
            }
        }

        return [
            'kind' => $install ? 'install' : 'backup',
            'install' => $install,
            'with_data' => $withData,
            'dry_run' => $dryRun,
            'backup_id' => $backupId,
            'backup_path' => $basePath,
            'schema_path' => $schemaPath,
            'data_path' => $dataPath,
            'meta_path' => $metaPath,
            'backup_tables' => $config['backup_tables'],
            'ignore_tables' => $config['ignore_tables'],
            'schema_tables' => count($schemaTables),
            'data_tables' => $dataTables,
            'data_rows' => (int)$dataReport['rows'],
            'skipped_tables' => array_values((array)$dataReport['skipped_tables']),
        ];
    }

    /**
     * 从安装包或运行备份恢复数据库结构与数据。
     *
     * @return array<string,mixed>
     */
    public function restore(bool $install = false, bool $withData = false, bool $force = false, bool $dryRun = false): array
    {
        if ($install && $withData) {
            throw new \InvalidArgumentException('Release install restore cannot be combined with --with-data.');
        }

        $config = $this->releaseConfig();
        $sourcePath = $install ? $this->installSourcePath() : $this->latestBackupPath();
        $dataPath = $sourcePath . '/' . self::DATA_FILENAME;
        $metaPath = $sourcePath . '/' . self::META_FILENAME;
        if ($install && !is_file($metaPath) && is_file($sourcePath . '/' . self::SCHEMA_FILENAME)) {
            throw new \RuntimeException('Legacy single-schema release install package is not supported; rebuild format v2 with SQLite and MySQL schemas.');
        }
        $meta = $this->readMetaFile($metaPath);
        $this->assertRestoreMeta($meta, $install, $withData, $sourcePath);
        $schemaPath = $install
            ? $this->installSchemaPath($sourcePath, $meta, $this->databaseDriver())
            : $sourcePath . '/' . self::SCHEMA_FILENAME;

        $connection = $this->makeConnection();
        $platform = $connection->getDatabasePlatform();
        $current = $connection->createSchemaManager()->introspectSchema();
        $target = $this->readSchemaFile($schemaPath);
        $this->normalizeSchemaForComparison($current);
        $this->normalizeSchemaForComparison($target);
        $diff = (new Comparator($platform))->compareSchemas($current, $target);
        $sql = $this->splitSchemaSql($diff, $platform);
        $safeSql = $sql['safe'];
        $destructiveSql = $sql['destructive'];
        // 业务修复必须在旧结构上完成只读预检，避免结构已更新后才发现历史数据无法可靠迁移。
        $dataRepairPreview = $install ? make(ReleaseDataRepairService::class)->preview() : [
            'required' => false,
            'ready' => true,
            'items' => [],
            'blocking' => [],
        ];
        if (!(bool)$dataRepairPreview['ready']) {
            $problem = (array)($dataRepairPreview['blocking'][0] ?? []);
            throw new \RuntimeException(sprintf(
                'Release data repair precheck failed [%s]: %s',
                (string)($problem['code'] ?? 'unknown'),
                (string)($problem['message'] ?? '存在无法自动修复的历史数据')
            ));
        }
        if (!$dryRun && !$force && $destructiveSql !== []) {
            throw new \RuntimeException('Release restore would execute destructive SQL. Re-run with --force only after backing up the target database.');
        }

        $executedSql = [...$safeSql, ...$destructiveSql];
        $dataTables = $withData ? $this->metaTables($meta, 'data_tables') : $config['backup_tables'];
        $dataRows = 0;
        $skippedTables = [];
        $sync = [];
        $tenantRepair = [];
        $preUpgradeBackup = null;
        $dataRepairs = ['items' => []];
        $backupRequired = $install && $this->schemaTableNames($current) !== [];

        if (!$dryRun) {
            if ($backupRequired) {
                // 正式升级旧库前固定生成全量运行备份；备份失败时尚未执行结构或业务数据写入。
                $preUpgradeBackup = $this->backup(true, false, false);
            }
            foreach ($executedSql as $statement) {
                $connection->executeStatement($statement);
            }

            $restoreReport = $this->replaceTablesFromDataFile($dataPath, $dataTables);
            $dataRows = (int)$restoreReport['rows'];
            $skippedTables = array_values((array)$restoreReport['skipped_tables']);
            $sync = $withData ? [] : $this->syncSystemBootstrap(false);
            $tenantRepair = (array)($sync['tenant_repair'] ?? $this->repairTenantData(false));
            if ($install) {
                $dataRepairs = make(ReleaseDataRepairService::class)->repair();
            }
        }

        return [
            'kind' => $install ? 'install' : 'backup',
            'install' => $install,
            'with_data' => $withData,
            'force' => $force,
            'dry_run' => $dryRun,
            'backup_id' => $meta['backup_id'] ?? null,
            'backup_path' => $sourcePath,
            'schema_path' => $schemaPath,
            'data_path' => $dataPath,
            'meta_path' => $metaPath,
            'backup_tables' => $config['backup_tables'],
            'ignore_tables' => $config['ignore_tables'],
            'data_tables' => $dataTables,
            'safe_sql' => $safeSql,
            'destructive_sql' => $destructiveSql,
            'executed_sql' => $dryRun ? [] : $executedSql,
            'data_rows' => $dataRows,
            'skipped_tables' => $skippedTables,
            'sync' => $sync,
            'tenant_repair' => $tenantRepair,
            'backup_required' => $backupRequired,
            'pre_upgrade_backup' => $preUpgradeBackup,
            'data_repair_preview' => $dataRepairPreview,
            'data_repairs' => $dataRepairs,
            'meta' => $meta,
        ];
    }

    /**
     * @return array{backup_tables:array<int,string>,ignore_tables:array<int,string>}
     */
    public function releaseConfig(): array
    {
        $ignoreTables = self::normalizeTables((array)config('release.ignore_tables', []));
        $backupTables = self::effectiveTables(
            self::normalizeTables((array)config('release.backup_tables', [])),
            $ignoreTables
        );

        return [
            'backup_tables' => $backupTables,
            'ignore_tables' => $ignoreTables,
        ];
    }

    /**
     * @param array<int|string,mixed> $tables
     * @return array<int,string>
     */
    public static function normalizeTables(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $table = strtolower(trim((string)$table));
            if ($table === '' || !preg_match('/^[a-z0-9_]+$/', $table)) {
                continue;
            }
            $result[$table] = $table;
        }

        return array_values($result);
    }

    /**
     * @param array<int,string> $backupTables
     * @param array<int,string> $ignoreTables
     * @return array<int,string>
     */
    public static function effectiveTables(array $backupTables, array $ignoreTables): array
    {
        return array_values(array_diff($backupTables, $ignoreTables));
    }

    private function makeConnection(): Connection
    {
        $config = config('databases.default');
        $driver = (string)($config['driver'] ?? '');
        if ($driver === 'sqlite') {
            // DBAL 的 SQLite 文件库使用 path 参数；若沿用 MySQL 的 dbname 会导致快照连接到空库，结构表数变成 0。
            $config['driver'] = 'pdo_sqlite';
            $database = (string)($config['database'] ?? ':memory:');
            if ($database === ':memory:') {
                $config['memory'] = true;
            } else {
                $config['path'] = $database;
            }
            unset($config['database'], $config['dbname'], $config['username'], $config['password'], $config['host'], $config['port']);
        } else {
            $config['user'] = $config['username'] ?? '';
            $config['dbname'] = $config['database'] ?? '';
            if (in_array($driver, ['mysql', 'oci'], true)) {
                $config['driver'] = 'pdo_' . $driver;
            }
        }

        return DriverManager::getConnection($config);
    }

    /**
     * 返回当前连接方言；安装包只支持正式兼容目标 SQLite 与 MySQL，运行备份仍可沿用现有连接。
     */
    private function databaseDriver(): string
    {
        $driver = strtolower(trim((string)config('databases.default.driver', '')));
        return str_starts_with($driver, 'pdo_') ? substr($driver, 4) : $driver;
    }

    /**
     * 安装结构包含物理表名前缀；构建与恢复必须使用同一规范值，避免 schema 与必要数据写入不同表集合。
     */
    private function databasePrefix(): string
    {
        $rawPrefix = (string)config('databases.default.prefix', '');
        $prefix = trim($rawPrefix);
        if ($rawPrefix !== $prefix) {
            throw new \RuntimeException('Database prefix must not contain surrounding whitespace.');
        }
        if ($prefix !== '' && preg_match('/^[a-zA-Z0-9_]+$/D', $prefix) !== 1) {
            throw new \RuntimeException('Database prefix may only contain letters, numbers and underscores.');
        }

        return $prefix;
    }

    /**
     * DBAL 结构清单使用物理表名，运行备份数据查询使用会追加前缀的 Hyperf Builder，因此这里统一转换为逻辑表名。
     * 若同一库混入不受当前前缀管理的表，不能宣称生成了“全量备份”，必须在升级写库前明确失败。
     *
     * @param string[] $schemaTables
     * @return string[]
     */
    private function runtimeFullDataTables(array $schemaTables, string $databasePrefix): array
    {
        if ($databasePrefix === '') {
            return self::normalizeTables($schemaTables);
        }

        $prefix = strtolower($databasePrefix);
        $tables = [];
        $outside = [];
        foreach ($schemaTables as $physicalTable) {
            if (!str_starts_with($physicalTable, $prefix) || strlen($physicalTable) === strlen($prefix)) {
                $outside[] = $physicalTable;
                continue;
            }
            $tables[] = substr($physicalTable, strlen($prefix));
        }
        if ($outside !== []) {
            throw new \RuntimeException(sprintf(
                'Release full backup cannot safely capture tables outside DB_PREFIX (%s): %s',
                $databasePrefix,
                implode(', ', $outside)
            ));
        }

        return self::normalizeTables($tables);
    }

    private function installSchemaFilename(string $driver): string
    {
        return match ($driver) {
            'mysql' => self::MYSQL_SCHEMA_FILENAME,
            'sqlite' => self::SQLITE_SCHEMA_FILENAME,
            default => throw new \RuntimeException("Release install format v2 does not support database driver: {$driver}"),
        };
    }

    /**
     * 安装结构只能写入打包器创建的 staging，禁止低层命令直接覆盖可发布 final。
     */
    private function installStagingPath(): string
    {
        $path = trim((string)(getenv('RELEASE_INSTALL_STAGING_DIR') ?: ''));
        if ($path === '') {
            throw new \RuntimeException('Release install schema capture requires RELEASE_INSTALL_STAGING_DIR; run composer release:backup.');
        }

        return $this->assertInstallStagingPath($path);
    }

    /**
     * 源码构建验证可读取 staging；正式 Phar/SFX 永远只读取包内 final 安装包。
     */
    private function installSourcePath(): string
    {
        $path = trim((string)(getenv('RELEASE_INSTALL_STAGING_DIR') ?: ''));
        if ($path !== '' && !System::isPharMode()) {
            return $this->assertInstallStagingPath($path);
        }

        return syspath(self::INSTALL_DIR);
    }

    private function assertInstallStagingPath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        $controlledParent = rtrim(str_replace('\\', '/', syspath('storage/extra')), '/');
        $stagingName = basename($normalized);
        if (
            dirname($normalized) !== $controlledParent
            || preg_match('/^release\.staging-[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $stagingName) !== 1
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $normalized) === 1
        ) {
            throw new \RuntimeException("Release install staging path is outside the controlled build directory: {$path}");
        }

        // staging 必须是 storage/extra 的直属真实目录；拒绝自身软链及经软链跳转到外部的既有路径。
        $controlledRealPath = realpath($controlledParent);
        $parentRealPath = realpath(dirname($normalized));
        if (
            $controlledRealPath === false
            || $parentRealPath === false
            || str_replace('\\', '/', $controlledRealPath) !== $controlledParent
            || str_replace('\\', '/', $parentRealPath) !== str_replace('\\', '/', $controlledRealPath)
            || is_link($normalized)
        ) {
            throw new \RuntimeException("Release install staging path is outside the controlled build directory: {$path}");
        }
        if (file_exists($normalized)) {
            $stagingRealPath = realpath($normalized);
            if (
                $stagingRealPath === false
                || str_replace('\\', '/', dirname($stagingRealPath)) !== str_replace('\\', '/', $controlledRealPath)
            ) {
                throw new \RuntimeException("Release install staging path is outside the controlled build directory: {$path}");
            }
        }

        return $normalized;
    }

    private function shouldWriteInstallData(string $dataPath): bool
    {
        $value = getenv('RELEASE_INSTALL_WRITE_DATA');
        if ($value === false || trim((string)$value) === '') {
            return !is_file($dataPath);
        }
        if (!in_array((string)$value, ['0', '1'], true)) {
            throw new \RuntimeException('RELEASE_INSTALL_WRITE_DATA must be 0 or 1.');
        }

        return (string)$value === '1';
    }

    /**
     * @param string[] $schemaTables
     * @return string[]
     */
    private function installPollutionTables(array $schemaTables): array
    {
        $prefix = strtolower($this->databasePrefix());
        $pollution = [];
        foreach ($schemaTables as $physicalTable) {
            $logicalTable = $prefix !== '' && str_starts_with($physicalTable, $prefix)
                ? substr($physicalTable, strlen($prefix))
                : $physicalTable;
            if (preg_match('/^(?:backup|bak)_/', $logicalTable) === 1) {
                $pollution[$logicalTable] = $logicalTable;
            }
        }

        return array_values($pollution);
    }

    /**
     * 第二方言写入前必须确认 staging 来自同一 fresh 结构和同一发布配置，不能拼接不同构建批次。
     *
     * @param array<string,mixed> $meta
     * @param string[] $schemaTables
     * @param array{backup_tables:array<int,string>,ignore_tables:array<int,string>} $config
     */
    private function assertInstallStagingMeta(
        array $meta,
        string $basePath,
        array $schemaTables,
        array $config,
        string $databasePrefix
    ): void
    {
        if ((int)($meta['format_version'] ?? 0) !== self::INSTALL_FORMAT_VERSION || ($meta['kind'] ?? null) !== 'install') {
            throw new \RuntimeException("Release install staging metadata is invalid: {$basePath}");
        }
        if (!is_string($meta['database_prefix'] ?? null) || $meta['database_prefix'] !== $databasePrefix) {
            throw new \RuntimeException('SQLite and MySQL release database prefixes differ.');
        }

        $expectedTables = self::normalizeTables($schemaTables);
        $existingTables = $this->metaTables($meta, 'schema_tables');
        sort($expectedTables);
        sort($existingTables);
        if ($expectedTables !== $existingTables) {
            throw new \RuntimeException('SQLite and MySQL fresh schemas contain different table sets.');
        }
        if (
            $this->metaTables($meta, 'backup_tables') !== $config['backup_tables']
            || $this->metaTables($meta, 'ignore_tables') !== $config['ignore_tables']
        ) {
            throw new \RuntimeException('SQLite and MySQL release backup table configuration differs.');
        }
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function installSchemaPath(string $sourcePath, array $meta, string $driver): string
    {
        $schema = is_array($meta['schema'] ?? null) ? $meta['schema'] : [];
        $entry = is_array($schema[$driver] ?? null) ? $schema[$driver] : [];
        $file = (string)($entry['file'] ?? '');
        if ($file !== $this->installSchemaFilename($driver) || (string)($entry['driver'] ?? '') !== $driver) {
            throw new \RuntimeException("Release install package does not contain a valid {$driver} schema mapping.");
        }

        return $sourcePath . '/' . $file;
    }

    /**
     * SQLite 把 DECIMAL 反射为无 precision/scale 的 NUMERIC；Doctrine 比较时要求这两个值存在。
     * 仅补反射缺失值，MySQL 等能提供真实精度的平台保持原样。
     */
    private function normalizeSchemaForComparison(Schema $schema): void
    {
        foreach ($schema->getTables() as $table) {
            foreach ($table->getColumns() as $column) {
                if (!$column->getType() instanceof DecimalType) {
                    continue;
                }
                if ($column->getPrecision() === null) {
                    $column->setPrecision(10);
                }
                if ($column->getScale() === null) {
                    $column->setScale(0);
                }
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function schemaTableNames(Schema $schema): array
    {
        $tables = [];
        foreach ($schema->getTables() as $table) {
            $name = strtolower($table->getShortestName($schema->getName()));
            if ($name !== '') {
                $tables[$name] = $name;
            }
        }

        return array_values($tables);
    }

    private function writeSchemaFile(string $path, Schema $schema): void
    {
        $this->ensureDirectory(dirname($path));
        $payload = serialize($schema);
        $encoded = gzencode($payload, 9);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to gzip release schema.');
        }
        file_put_contents($path, $encoded);
    }

    private function readSchemaFile(string $path): Schema
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Release schema file not found: %s', $path));
        }

        $content = gzdecode((string)file_get_contents($path));
        if (!is_string($content) || $content === '') {
            throw new \RuntimeException(sprintf('Release schema file is invalid: %s', $path));
        }

        $schema = unserialize($content, ['allowed_classes' => true]);
        if (!$schema instanceof Schema) {
            throw new \RuntimeException(sprintf('Release schema payload is invalid: %s', $path));
        }

        return $schema;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function writeMetaFile(string $path, array $meta): void
    {
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
    }

    /**
     * @return array<string,mixed>
     */
    private function readMetaFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Release metadata file not found: %s', $path));
        }

        $content = file_get_contents($path);
        $meta = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($meta)) {
            throw new \RuntimeException(sprintf('Release metadata file is invalid: %s', $path));
        }

        return $meta;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function assertRestoreMeta(array $meta, bool $install, bool $withData, string $sourcePath): void
    {
        $expected = $install ? 'install' : 'backup';
        if (($meta['kind'] ?? null) !== $expected) {
            throw new \RuntimeException(sprintf('Release %s package metadata mismatch: %s', $expected, $sourcePath));
        }
        if ($install && ($meta['with_data'] ?? null) !== false) {
            throw new \RuntimeException('Release install package metadata must declare with_data=false.');
        }
        if ($install) {
            if ((int)($meta['format_version'] ?? 0) !== self::INSTALL_FORMAT_VERSION) {
                throw new \RuntimeException('Legacy single-schema release install package is not supported; rebuild format v2 with SQLite and MySQL schemas.');
            }
            if (is_file($sourcePath . '/' . self::SCHEMA_FILENAME)) {
                throw new \RuntimeException('Release install format v2 must not contain legacy database.schema.gz.');
            }
            $packagePrefix = $meta['database_prefix'] ?? null;
            $targetPrefix = $this->databasePrefix();
            if (!is_string($packagePrefix) || $packagePrefix !== $targetPrefix) {
                $packageText = is_string($packagePrefix) && $packagePrefix !== '' ? $packagePrefix : '<empty or missing>';
                $targetText = $targetPrefix !== '' ? $targetPrefix : '<empty>';
                throw new \RuntimeException(
                    "Release install database prefix mismatch: package={$packageText}, target={$targetText}. Configure DB_PREFIX consistently."
                );
            }

            $schemas = is_array($meta['schema'] ?? null) ? $meta['schema'] : [];
            foreach (['mysql', 'sqlite'] as $driver) {
                $entry = is_array($schemas[$driver] ?? null) ? $schemas[$driver] : [];
                $filename = $this->installSchemaFilename($driver);
                $path = $sourcePath . '/' . $filename;
                $expectedHash = strtolower(trim((string)($entry['sha256'] ?? '')));
                if (
                    (string)($entry['driver'] ?? '') !== $driver
                    || (string)($entry['file'] ?? '') !== $filename
                    || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)
                    || !is_file($path)
                    || !hash_equals($expectedHash, (string)hash_file('sha256', $path))
                ) {
                    throw new \RuntimeException("Release install {$driver} schema mapping or hash is invalid: {$sourcePath}");
                }
            }

            $data = is_array($meta['data'] ?? null) ? $meta['data'] : [];
            $dataPath = $sourcePath . '/' . self::DATA_FILENAME;
            $expectedDataHash = strtolower(trim((string)($data['sha256'] ?? '')));
            if (
                (string)($data['file'] ?? '') !== self::DATA_FILENAME
                || !preg_match('/^[a-f0-9]{64}$/', $expectedDataHash)
                || !is_file($dataPath)
                || !hash_equals($expectedDataHash, (string)hash_file('sha256', $dataPath))
            ) {
                throw new \RuntimeException("Release install shared data mapping or hash is invalid: {$sourcePath}");
            }
        }
        if ($withData && empty($meta['with_data'])) {
            throw new \RuntimeException('Release backup was not created with --with-data.');
        }
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<int,string>
     */
    private function metaTables(array $meta, string $key): array
    {
        return self::normalizeTables(is_array($meta[$key] ?? null) ? $meta[$key] : []);
    }

    /**
     * @param array<int,string> $tables
     * @return array{rows:int,skipped_tables:array<int,string>}
     */
    private function writeDataFile(string $path, array $tables): array
    {
        $this->ensureDirectory(dirname($path));
        $handle = gzopen($path, 'wb9');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to write release data file: %s', $path));
        }

        $rows = 0;
        $skippedTables = [];
        $schema = Db::getSchemaBuilder();
        try {
            foreach ($tables as $table) {
                if (!$schema->hasTable($table)) {
                    $skippedTables[] = $table;
                    continue;
                }

                $columns = $schema->getColumnListing($table);
                if ($columns === []) {
                    $skippedTables[] = $table;
                    continue;
                }

                $orderBy = in_array('id', $columns, true) ? 'id' : $columns[0];
                Db::table($table)
                    ->orderBy($orderBy)
                    ->chunk(1000, function ($items) use ($handle, $table, &$rows): void {
                        foreach ($items as $item) {
                            gzwrite($handle, json_encode([
                                'table' => $table,
                                'data' => (array)$item,
                            ], JSON_UNESCAPED_UNICODE) . "\n");
                            ++$rows;
                        }
                    });
            }
        } finally {
            gzclose($handle);
        }

        return [
            'rows' => $rows,
            'skipped_tables' => array_values(array_unique($skippedTables)),
        ];
    }

    /**
     * @param array<int,string> $tables
     * @return array{rows:int,skipped_tables:array<int,string>}
     */
    private function replaceTablesFromDataFile(string $path, array $tables): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Release data file not found: %s', $path));
        }

        $schema = Db::getSchemaBuilder();
        $skippedTables = [];
        foreach ($tables as $table) {
            if (!$schema->hasTable($table)) {
                $skippedTables[] = $table;
            }
        }

        $allowed = array_flip(array_diff($tables, $skippedTables));
        $rows = 0;
        $batch = [];
        $this->setForeignKeyChecks(false);
        try {
            foreach (array_keys($allowed) as $table) {
                Db::table((string)$table)->truncate();
            }

            $this->forEachDataLine($path, function (string $line) use (&$batch, &$rows, $allowed): void {
                $line = trim($line);
                if ($line === '') {
                    return;
                }

                $record = json_decode($line, true);
                $table = strtolower((string)($record['table'] ?? ''));
                $data = $record['data'] ?? null;
                if (!isset($allowed[$table]) || !is_array($data) || $data === []) {
                    return;
                }

                $batch[$table][] = $data;
                if (count($batch[$table]) >= 1000) {
                    $this->flushInsertBatch($table, $batch[$table]);
                    $rows += 1000;
                }
            });

            foreach ($batch as $table => $items) {
                $count = count($items);
                if ($count > 0) {
                    $this->flushInsertBatch((string)$table, $items);
                    $rows += $count;
                }
            }
        } finally {
            $this->setForeignKeyChecks(true);
        }

        return [
            'rows' => $rows,
            'skipped_tables' => array_values(array_unique($skippedTables)),
        ];
    }

    /**
     * 逐行读取 gzip 数据快照。
     *
     * Phar 内的 gzip 文件不能用 `gzopen()` 直接取得文件描述符，因此安装包读取走内存解压；
     * 运行备份仍用流式读取，避免 `--with-data` 大备份占用过多内存。
     *
     * @param callable(string):void $consumer
     */
    private function forEachDataLine(string $path, callable $consumer): void
    {
        if (str_starts_with($path, 'phar://')) {
            $encoded = file_get_contents($path);
            $decoded = is_string($encoded) ? gzdecode($encoded) : false;
            if (!is_string($decoded)) {
                throw new \RuntimeException(sprintf('Release data file is invalid: %s', $path));
            }

            foreach (explode("\n", $decoded) as $line) {
                // 快照写入固定使用 "\n" 作为记录分隔符；不要用 \R，避免把 JSON 字段里的 Unicode 行分隔符误切成两条记录。
                $consumer(rtrim($line, "\r"));
            }
            return;
        }

        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to read release data file: %s', $path));
        }

        try {
            while (!gzeof($handle)) {
                $consumer((string)gzgets($handle));
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function flushInsertBatch(string $table, array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        Db::table($table)->insert($rows);
        $rows = [];
    }

    private function setForeignKeyChecks(bool $enabled): void
    {
        try {
            Db::statement('SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0'));
        } catch (\Throwable) {
        }

        try {
            Db::statement('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'));
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{safe:array<int,string>,destructive:array<int,string>}
     */
    private function splitSchemaSql(SchemaDiff $diff, AbstractPlatform $platform): array
    {
        $safe = [];
        $destructive = [];

        if (method_exists($platform, 'supportsSchemas') && $platform->supportsSchemas()) {
            foreach ($diff->getCreatedSchemas() as $schema) {
                $safe[] = $platform->getCreateSchemaSQL($schema);
            }
            foreach ($diff->getDroppedSchemas() as $schema) {
                $destructive[] = $platform->getDropSchemaSQL($schema);
            }
        }

        $safe = array_merge($safe, $platform->getCreateTablesSQL($diff->getCreatedTables()));
        $destructive = array_merge($destructive, $platform->getDropTablesSQL($diff->getDroppedTables()));

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $sql = $platform->getAlterTableSQL($tableDiff);
            if ($this->isSafeTableDiff($tableDiff)) {
                $safe = array_merge($safe, $sql);
            } else {
                $destructive = array_merge($destructive, $sql);
            }
        }

        return [
            'safe' => array_values($safe),
            'destructive' => array_values($destructive),
        ];
    }

    private function isSafeTableDiff(TableDiff $diff): bool
    {
        if (
            $diff->getDroppedColumns() !== []
            || $diff->getDroppedIndexes() !== []
            || $diff->getRenamedIndexes() !== []
            || $diff->getDroppedForeignKeys() !== []
        ) {
            return false;
        }

        foreach ($diff->getChangedColumns() as $columnDiff) {
            if (!$this->isSafeColumnDiff($columnDiff)) {
                return false;
            }
        }

        return true;
    }

    private function isSafeColumnDiff(ColumnDiff $diff): bool
    {
        if (
            $diff->hasNameChanged()
            || $diff->hasTypeChanged()
            || $diff->hasUnsignedChanged()
            || $diff->hasAutoIncrementChanged()
            || $diff->hasFixedChanged()
        ) {
            return false;
        }

        if ($diff->hasNotNullChanged() && (!$diff->getOldColumn()->getNotnull() || $diff->getNewColumn()->getNotnull())) {
            return false;
        }

        if ($diff->hasLengthChanged() && !$this->isNumericIncrease($diff->getOldColumn()->getLength(), $diff->getNewColumn()->getLength())) {
            return false;
        }

        if ($diff->hasPrecisionChanged() && !$this->isNumericIncrease($diff->getOldColumn()->getPrecision(), $diff->getNewColumn()->getPrecision())) {
            return false;
        }

        if ($diff->hasScaleChanged() && !$this->isNumericIncrease($diff->getOldColumn()->getScale(), $diff->getNewColumn()->getScale())) {
            return false;
        }

        return true;
    }

    private function isNumericIncrease(?int $old, ?int $new): bool
    {
        if ($old === $new) {
            return true;
        }

        if ($old === null || $new === null) {
            return false;
        }

        return $new >= $old;
    }

    /**
     * @return array<string,mixed>
     */
    private function syncSystemBootstrap(bool $dryRun): array
    {
        if (class_exists(SystemBootstrapService::class)) {
            return make(SystemBootstrapService::class)->syncWithReport($dryRun);
        }

        $result = [];

        if (class_exists(MenuSeedSyncService::class)) {
            $result['menu'] = make(MenuSeedSyncService::class)->syncWithReport($dryRun);
        }

        if (class_exists(AuthRegistryService::class)) {
            $result['auth'] = make(AuthRegistryService::class)->syncWithReport(true, true, $dryRun);
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function repairTenantData(bool $dryRun): array
    {
        return class_exists(TenantRepairService::class) ? make(TenantRepairService::class)->repair($dryRun) : [];
    }

    private function nextBackupId(): string
    {
        $base = runpath(self::BACKUP_DIR);
        $id = date('YmdHis');
        $candidate = $id;
        $index = 1;
        while (is_dir($base . '/' . $candidate)) {
            $candidate = $id . '-' . $index;
            ++$index;
        }

        return $candidate;
    }

    private function latestBackupPath(): string
    {
        $base = runpath(self::BACKUP_DIR);
        if (!is_dir($base)) {
            throw new \RuntimeException(sprintf('Release backup directory not found: %s', $base));
        }

        $latest = $base . '/latest';
        if (is_dir($latest)) {
            return $latest;
        }
        if (is_file($latest)) {
            $id = trim((string)file_get_contents($latest));
            if ($id !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $id) && is_dir($base . '/' . $id)) {
                return $base . '/' . $id;
            }
        }

        $directories = [];
        foreach (scandir($base) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === 'latest') {
                continue;
            }
            if (is_dir($base . '/' . $name)) {
                $directories[] = $name;
            }
        }
        rsort($directories, SORT_NATURAL);
        if ($directories === []) {
            throw new \RuntimeException(sprintf('Release backup package not found under: %s', $base));
        }

        return $base . '/' . $directories[0];
    }

    private function writeLatestBackupId(string $backupId): void
    {
        $path = runpath(self::BACKUP_DIR . '/latest');
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, $backupId . PHP_EOL, LOCK_EX);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isDir() && !$fileInfo->isLink()) {
                rmdir($fileInfo->getPathname());
                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($directory);
    }
}
