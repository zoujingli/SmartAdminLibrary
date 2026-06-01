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
    public const INSTALL_DIR = 'storage/extra/release';

    public const BACKUP_DIR = 'runtime/backup';

    public const SCHEMA_FILENAME = 'database.schema.gz';

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
        $schema = $connection->createSchemaManager()->introspectSchema();
        $schemaTables = $this->schemaTableNames($schema);
        $dataTables = $withData ? $schemaTables : $config['backup_tables'];
        $backupId = $install ? null : $this->nextBackupId();
        $basePath = $install ? syspath(self::INSTALL_DIR) : runpath(self::BACKUP_DIR . '/' . $backupId);
        $schemaPath = $basePath . '/' . self::SCHEMA_FILENAME;
        $dataPath = $basePath . '/' . self::DATA_FILENAME;
        $metaPath = $basePath . '/' . self::META_FILENAME;
        $dataReport = [
            'rows' => 0,
            'skipped_tables' => [],
        ];

        if (!$dryRun) {
            if ($install) {
                // 安装包目录是构建产物目录，每次生成前先清空，避免历史文件混入 Phar 审计和构建指纹。
                $this->removeDirectory($basePath);
            }
            // 安装包与运行备份都保留完整结构；是否写入全量数据只由 --with-data 控制。
            $this->writeSchemaFile($schemaPath, $schema);
            $dataReport = $this->writeDataFile($dataPath, $dataTables);
            $this->writeMetaFile($metaPath, [
                'schema' => 1,
                'kind' => $install ? 'install' : 'backup',
                'with_data' => $withData,
                'created_at' => date(DATE_ATOM),
                'backup_id' => $backupId,
                'schema_tables' => $schemaTables,
                'data_tables' => $dataTables,
                'backup_tables' => $config['backup_tables'],
                'ignore_tables' => $config['ignore_tables'],
                'data_rows' => (int)$dataReport['rows'],
            ]);
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
        $sourcePath = $install ? syspath(self::INSTALL_DIR) : $this->latestBackupPath();
        $schemaPath = $sourcePath . '/' . self::SCHEMA_FILENAME;
        $dataPath = $sourcePath . '/' . self::DATA_FILENAME;
        $metaPath = $sourcePath . '/' . self::META_FILENAME;
        $meta = $this->readMetaFile($metaPath);
        $this->assertRestoreMeta($meta, $install, $withData, $sourcePath);

        $connection = $this->makeConnection();
        $platform = $connection->getDatabasePlatform();
        $current = $connection->createSchemaManager()->introspectSchema();
        $target = $this->readSchemaFile($schemaPath);
        $diff = (new Comparator($platform))->compareSchemas($current, $target);
        $sql = $this->splitSchemaSql($diff, $platform);
        $safeSql = $sql['safe'];
        $destructiveSql = $sql['destructive'];
        if (!$dryRun && !$force && $destructiveSql !== []) {
            throw new \RuntimeException('Release restore would execute destructive SQL. Re-run with --force only after backing up the target database.');
        }

        $executedSql = [...$safeSql, ...$destructiveSql];
        $dataTables = $withData ? $this->metaTables($meta, 'data_tables') : $config['backup_tables'];
        $dataRows = 0;
        $skippedTables = [];
        $sync = [];
        $tenantRepair = [];

        if (!$dryRun) {
            foreach ($executedSql as $statement) {
                $connection->executeStatement($statement);
            }

            $restoreReport = $this->replaceTablesFromDataFile($dataPath, $dataTables);
            $dataRows = (int)$restoreReport['rows'];
            $skippedTables = array_values((array)$restoreReport['skipped_tables']);
            $sync = $withData ? [] : $this->syncSystemBootstrap(false);
            $tenantRepair = (array)($sync['tenant_repair'] ?? $this->repairTenantData(false));
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
        if ($install && !empty($meta['with_data'])) {
            throw new \RuntimeException('Release install package must not contain full runtime data.');
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
