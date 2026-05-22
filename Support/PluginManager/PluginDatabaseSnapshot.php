<?php

declare(strict_types=1);

namespace Library\Support\PluginManager;

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

/**
 * 插件自有表快照服务。
 *
 * 备份/恢复只处理 plugin.json 声明的 tables/table_prefixes 或 plugin.code 推导前缀命中的表，
 * 不读取全局 release backup_tables，避免插件操作误接管系统或其他插件数据。
 */
final class PluginDatabaseSnapshot
{
    /**
     * @return array{schema_path:string,data_path:string,tables:array<int,string>,rows:int,skipped_tables:array<int,string>}
     */
    public function backup(PluginMetadata $metadata, string $workDir): array
    {
        $this->ensureDirectory($workDir);
        $owned = $this->resolveOwnedTables($metadata);
        $connection = $this->makeConnection();
        $schema = $connection->createSchemaManager()->introspectSchema();
        $this->filterToTables($schema, $owned['tables']);

        $schemaPath = rtrim($workDir, '/\\') . '/database.schema.gz';
        $dataPath = rtrim($workDir, '/\\') . '/database.data.gz';
        $this->writeSchemaFile($schemaPath, $schema);
        $dataReport = $this->writeDataFile($dataPath, $owned['tables']);

        return [
            'schema_path' => $schemaPath,
            'data_path' => $dataPath,
            'tables' => $owned['tables'],
            'rows' => (int)$dataReport['rows'],
            'skipped_tables' => array_values(array_unique([...$owned['skipped_tables'], ...$dataReport['skipped_tables']])),
        ];
    }

    /**
     * @param array<int,string> $tables
     * @return array{tables:array<int,string>,safe_sql:array<int,string>,destructive_sql:array<int,string>,executed_sql:array<int,string>,rows:int,skipped_tables:array<int,string>}
     */
    public function restore(string $schemaPath, string $dataPath, array $tables, bool $force = false): array
    {
        $tables = $this->normalizeTables($tables);
        $connection = $this->makeConnection();
        $platform = $connection->getDatabasePlatform();
        $current = $connection->createSchemaManager()->introspectSchema();
        $target = $this->readSchemaFile($schemaPath);
        $targetTables = $tables !== [] ? $tables : $this->tableNames($target);
        $this->filterToTables($current, $targetTables);
        $this->filterToTables($target, $targetTables);

        $diff = (new Comparator($platform))->compareSchemas($current, $target);
        $sql = $this->splitSchemaSql($diff, $platform);
        $executedSql = $force ? [...$sql['safe'], ...$sql['destructive']] : $sql['safe'];
        foreach ($executedSql as $statement) {
            $connection->executeStatement($statement);
        }

        if (!$force && $sql['destructive'] !== []) {
            throw new \RuntimeException('插件恢复检测到破坏性结构变更，请确认后使用 --force。');
        }

        $dataReport = $this->replaceTablesFromDataFile($dataPath, $targetTables);

        return [
            'tables' => $targetTables,
            'safe_sql' => $sql['safe'],
            'destructive_sql' => $sql['destructive'],
            'executed_sql' => $executedSql,
            'rows' => (int)$dataReport['rows'],
            'skipped_tables' => $dataReport['skipped_tables'],
        ];
    }

    /**
     * @return array{tables:array<int,string>,skipped_tables:array<int,string>}
     */
    public function resolveOwnedTables(PluginMetadata $metadata): array
    {
        $schema = $this->makeConnection()->createSchemaManager()->introspectSchema();
        $existing = $this->tableNames($schema);
        $explicit = array_flip($metadata->tables);
        $tables = [];
        foreach ($existing as $table) {
            if (isset($explicit[$table]) || $this->matchesPrefixes($table, $metadata->tablePrefixes)) {
                $tables[$table] = $table;
            }
        }

        $skipped = [];
        foreach ($metadata->tables as $table) {
            if (!in_array($table, $existing, true)) {
                $skipped[] = $table;
            }
        }

        return [
            'tables' => array_values($tables),
            'skipped_tables' => $skipped,
        ];
    }

    /**
     * @param array<int,string> $tables
     * @return array{dropped:int,tables:array<int,string>}
     */
    public function dropTables(array $tables): array
    {
        $tables = $this->normalizeTables($tables);
        if ($tables === []) {
            return ['dropped' => 0, 'tables' => []];
        }

        $schema = Db::getSchemaBuilder();
        $dropped = [];
        $this->setForeignKeyChecks(false);
        try {
            foreach ($tables as $table) {
                if (!$schema->hasTable($table)) {
                    continue;
                }
                Db::statement('DROP TABLE `' . str_replace('`', '``', $table) . '`');
                $dropped[] = $table;
            }
        } finally {
            $this->setForeignKeyChecks(true);
        }

        return [
            'dropped' => count($dropped),
            'tables' => $dropped,
        ];
    }

    /**
     * @param array<int,string> $tables
     * @return array<int,string>
     */
    private function normalizeTables(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $table = strtolower(trim((string)$table));
            if ($table !== '' && preg_match('/^[a-z0-9_]+$/', $table) === 1) {
                $result[$table] = $table;
            }
        }

        return array_values($result);
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
     * @param array<int,string> $tables
     */
    private function filterToTables(Schema $schema, array $tables): void
    {
        $allowed = array_flip($tables);
        foreach ($schema->getTables() as $table) {
            $name = strtolower($table->getShortestName($schema->getName()));
            if (!isset($allowed[$name]) && $schema->hasTable($name)) {
                $schema->dropTable($name);
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function tableNames(Schema $schema): array
    {
        $tables = [];
        foreach ($schema->getTables() as $table) {
            $tables[] = strtolower($table->getShortestName($schema->getName()));
        }
        sort($tables);

        return $tables;
    }

    /**
     * @param array<int,string> $prefixes
     */
    private function matchesPrefixes(string $table, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function writeSchemaFile(string $path, Schema $schema): void
    {
        $this->ensureDirectory(dirname($path));
        $encoded = gzencode(serialize($schema), 9);
        if ($encoded === false) {
            throw new \RuntimeException('插件表结构快照压缩失败。');
        }
        file_put_contents($path, $encoded);
    }

    private function readSchemaFile(string $path): Schema
    {
        if (!is_file($path)) {
            throw new \RuntimeException('插件表结构快照不存在：' . $path);
        }
        $content = gzdecode((string)file_get_contents($path));
        if (!is_string($content) || $content === '') {
            throw new \RuntimeException('插件表结构快照无效：' . $path);
        }
        $schema = unserialize($content, ['allowed_classes' => true]);
        if (!$schema instanceof Schema) {
            throw new \RuntimeException('插件表结构快照内容无效：' . $path);
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
            throw new \RuntimeException('无法写入插件数据快照：' . $path);
        }

        $rows = 0;
        $skipped = [];
        $schema = Db::getSchemaBuilder();
        try {
            foreach ($tables as $table) {
                if (!$schema->hasTable($table)) {
                    $skipped[] = $table;
                    continue;
                }
                $columns = $schema->getColumnListing($table);
                if ($columns === []) {
                    $skipped[] = $table;
                    continue;
                }
                $orderBy = in_array('id', $columns, true) ? 'id' : $columns[0];
                Db::table($table)->orderBy($orderBy)->chunk(1000, function ($items) use ($handle, $table, &$rows): void {
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

        return ['rows' => $rows, 'skipped_tables' => array_values(array_unique($skipped))];
    }

    /**
     * @param array<int,string> $tables
     * @return array{rows:int,skipped_tables:array<int,string>}
     */
    private function replaceTablesFromDataFile(string $path, array $tables): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('插件数据快照不存在：' . $path);
        }

        $schema = Db::getSchemaBuilder();
        $skipped = [];
        foreach ($tables as $table) {
            if (!$schema->hasTable($table)) {
                $skipped[] = $table;
            }
        }
        $allowed = array_flip(array_diff($tables, $skipped));
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('无法读取插件数据快照：' . $path);
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

        return ['rows' => $rows, 'skipped_tables' => array_values(array_unique($skipped))];
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

        return ['safe' => array_values($safe), 'destructive' => array_values($destructive)];
    }

    private function isSafeTableDiff(TableDiff $diff): bool
    {
        if ($diff->getDroppedColumns() !== [] || $diff->getDroppedIndexes() !== [] || $diff->getRenamedIndexes() !== [] || $diff->getDroppedForeignKeys() !== []) {
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
        if ($diff->hasNameChanged() || $diff->hasTypeChanged() || $diff->hasUnsignedChanged() || $diff->hasAutoIncrementChanged() || $diff->hasFixedChanged()) {
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

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('无法创建目录：' . $path);
        }
    }
}
