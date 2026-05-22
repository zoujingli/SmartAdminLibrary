<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
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

use function Hyperf\Config\config;
use function Hyperf\Support\make;

/**
 * 发布数据库结构与基线数据同步服务。
 */
final class ReleaseDatabaseService
{
    public const SCHEMA_FILE = 'runtime/release/database.schema.gz';

    public const DATA_FILE = 'runtime/release/database.data.gz';

    /**
     * @return array{
     *   schema_path:string,
     *   data_path:string,
     *   backup_tables:array<int,string>,
     *   ignore_tables:array<int,string>,
     *   schema_tables:int,
     *   data_rows:int,
     *   skipped_tables:array<int,string>,
     *   dry_run:bool
     * }
     */
    public function backup(bool $dryRun = false): array
    {
        $config = $this->releaseConfig();
        $connection = $this->makeConnection();
        $schema = $connection->createSchemaManager()->introspectSchema();
        $this->filterIgnoredTables($schema, $config['ignore_tables']);

        $schemaPath = runpath(self::SCHEMA_FILE);
        $dataPath = runpath(self::DATA_FILE);
        $dataReport = [
            'rows' => 0,
            'skipped_tables' => [],
        ];

        if (!$dryRun) {
            $this->writeSchemaFile($schemaPath, $schema);
            $dataReport = $this->writeDataFile($dataPath, $config['backup_tables']);
        }

        return [
            'schema_path' => $schemaPath,
            'data_path' => $dataPath,
            'backup_tables' => $config['backup_tables'],
            'ignore_tables' => $config['ignore_tables'],
            'schema_tables' => count($schema->getTables()),
            'data_rows' => (int)$dataReport['rows'],
            'skipped_tables' => array_values((array)$dataReport['skipped_tables']),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return array{
     *   force:bool,
     *   dry_run:bool,
     *   schema_path:string,
     *   data_path:string,
     *   runtime_backup_path:string,
     *   backup_tables:array<int,string>,
     *   ignore_tables:array<int,string>,
     *   safe_sql:array<int,string>,
     *   destructive_sql:array<int,string>,
     *   executed_sql:array<int,string>,
     *   data_rows:int,
     *   runtime_backup_rows:int,
     *   skipped_tables:array<int,string>,
     *   sync:array<string,mixed>
     * }
     */
    public function upgrade(bool $force = false, bool $dryRun = false): array
    {
        $config = $this->releaseConfig();
        $connection = $this->makeConnection();
        $platform = $connection->getDatabasePlatform();

        $current = $connection->createSchemaManager()->introspectSchema();
        $target = $this->readSchemaFile(runpath(self::SCHEMA_FILE));
        $this->filterIgnoredTables($current, $config['ignore_tables']);
        $this->filterIgnoredTables($target, $config['ignore_tables']);

        $diff = (new Comparator($platform))->compareSchemas($current, $target);
        $sql = $this->splitSchemaSql($diff, $platform);
        $safeSql = $sql['safe'];
        $destructiveSql = $sql['destructive'];
        $executedSql = $force ? [...$safeSql, ...$destructiveSql] : $safeSql;
        $runtimeBackupPath = $this->runtimeBackupDataPath();
        $dataRows = 0;
        $runtimeBackupRows = 0;
        $skippedTables = [];
        $sync = [
            'menu' => null,
            'auth' => null,
        ];

        if (!$dryRun) {
            $backupReport = $this->writeDataFile($runtimeBackupPath, $config['backup_tables']);
            $runtimeBackupRows = (int)$backupReport['rows'];
            $skippedTables = array_values((array)$backupReport['skipped_tables']);

            foreach ($executedSql as $statement) {
                $connection->executeStatement($statement);
            }

            $restoreReport = $this->replaceTablesFromDataFile(runpath(self::DATA_FILE), $config['backup_tables']);
            $dataRows = (int)$restoreReport['rows'];
            $skippedTables = array_values(array_unique([...$skippedTables, ...(array)$restoreReport['skipped_tables']]));
            $sync = $this->syncSystemRegistries();
        }

        return [
            'force' => $force,
            'dry_run' => $dryRun,
            'schema_path' => runpath(self::SCHEMA_FILE),
            'data_path' => runpath(self::DATA_FILE),
            'runtime_backup_path' => $runtimeBackupPath,
            'backup_tables' => $config['backup_tables'],
            'ignore_tables' => $config['ignore_tables'],
            'safe_sql' => $safeSql,
            'destructive_sql' => $destructiveSql,
            'executed_sql' => $executedSql,
            'data_rows' => $dataRows,
            'runtime_backup_rows' => $runtimeBackupRows,
            'skipped_tables' => $skippedTables,
            'sync' => $sync,
        ];
    }

    /**
     * @return array{
     *   dry_run:bool,
     *   backup_id:string,
     *   backup_path:string,
     *   backup_tables:array<int,string>,
     *   ignore_tables:array<int,string>,
     *   data_rows:int,
     *   skipped_tables:array<int,string>
     * }
     */
    public function restore(string $backupId, bool $dryRun = false): array
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $backupId)) {
            throw new \InvalidArgumentException('Invalid backup id.');
        }

        $config = $this->releaseConfig();
        $path = runpath('runtime/release/backups/' . $backupId . '/database.data.gz');
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Release backup data file not found: %s', $path));
        }

        $report = [
            'rows' => 0,
            'skipped_tables' => [],
        ];
        if (!$dryRun) {
            $report = $this->replaceTablesFromDataFile($path, $config['backup_tables']);
        }

        return [
            'dry_run' => $dryRun,
            'backup_id' => $backupId,
            'backup_path' => $path,
            'backup_tables' => $config['backup_tables'],
            'ignore_tables' => $config['ignore_tables'],
            'data_rows' => (int)$report['rows'],
            'skipped_tables' => array_values((array)$report['skipped_tables']),
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
        $config['user'] = $config['username'] ?? '';
        $config['dbname'] = $config['database'] ?? '';
        if (in_array($config['driver'] ?? '', ['mysql', 'sqlite', 'oci'], true)) {
            $config['driver'] = 'pdo_' . $config['driver'];
        }

        return DriverManager::getConnection($config);
    }

    /**
     * @param array<int,string> $ignoreTables
     */
    private function filterIgnoredTables(Schema $schema, array $ignoreTables): void
    {
        if ($ignoreTables === []) {
            return;
        }

        $ignored = array_flip($ignoreTables);
        foreach ($schema->getTables() as $table) {
            $name = strtolower($table->getShortestName($schema->getName()));
            if (isset($ignored[$name]) && $schema->hasTable($name)) {
                $schema->dropTable($name);
            }
        }
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
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to read release data file: %s', $path));
        }

        $rows = 0;
        $batch = [];
        $this->setForeignKeyChecks(false);
        try {
            foreach (array_keys($allowed) as $table) {
                Db::table((string)$table)->truncate();
            }

            while (!gzeof($handle)) {
                $line = trim((string)gzgets($handle));
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                $table = strtolower((string)($record['table'] ?? ''));
                $data = $record['data'] ?? null;
                if (!isset($allowed[$table]) || !is_array($data) || $data === []) {
                    continue;
                }

                $batch[$table][] = $data;
                if (count($batch[$table]) >= 1000) {
                    $this->flushInsertBatch($table, $batch[$table]);
                    $rows += 1000;
                }
            }

            foreach ($batch as $table => $items) {
                $count = count($items);
                if ($count > 0) {
                    $this->flushInsertBatch((string)$table, $items);
                    $rows += $count;
                }
            }
        } finally {
            $this->setForeignKeyChecks(true);
            gzclose($handle);
        }

        return [
            'rows' => $rows,
            'skipped_tables' => array_values(array_unique($skippedTables)),
        ];
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
    private function syncSystemRegistries(): array
    {
        $result = [
            'menu' => null,
            'auth' => null,
        ];

        if (class_exists(\System\Service\MenuSeedSyncService::class)) {
            $result['menu'] = make(\System\Service\MenuSeedSyncService::class)->syncWithReport(false);
        }

        if (class_exists(\System\Service\AuthRegistryService::class)) {
            $result['auth'] = make(\System\Service\AuthRegistryService::class)->syncWithReport(true, true, false);
        }

        return $result;
    }

    private function runtimeBackupDataPath(): string
    {
        return runpath('runtime/release/backups/' . date('YmdHis') . '/database.data.gz');
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
