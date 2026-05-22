<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Library\Command\Concerns\SourceOnlyCommand;

#[Command(name: 'xadmin:build:struct', description: '工具-刷新数据库的结构索引')]
final class DbReIndexStruct extends HyperfCommand
{
    use SourceOnlyCommand;

    #[Inject]
    protected StdoutLoggerInterface $logger;

    /**
     * 命令执行入口
     * 遍历数据库表并重命名索引.
     */
    public function handle(): void
    {
        $count = 0;
        foreach (Db::select('SHOW TABLES') as $table) {
            $indexes = [];
            $tableName = current((array)$table);
            foreach (Db::select(sprintf('SHOW INDEX FROM `%s`', $tableName)) as $index) {
                if ($index->Key_name === 'PRIMARY') {
                    continue;
                }
                $indexes[$index->Key_name]['non_unique'] = (int)$index->Non_unique;
                $indexes[$index->Key_name]['columns'][(int)$index->Seq_in_index] = $index->Column_name;
            }

            $existingNames = array_fill_keys(array_keys($indexes), true);
            foreach ($indexes as $name => $data) {
                $columns = $data['columns'] ?? [];
                ksort($columns);
                $columnList = array_values($columns);
                $newName = $this->genIndexName($tableName, $columnList, (int)$data['non_unique']);
                if ($name === $newName) {
                    continue;
                }
                if (isset($existingNames[$newName])) {
                    continue;
                }
                Db::statement(sprintf('ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`', $tableName, $name, $newName));
                $existingNames[$newName] = true;
                ++$count;
            }
        }
        $this->logger->info("✅ 完成 {$count} 个索引重命名");
    }

    /**
     * 生成索引名称.
     * @param string $table 表名
     * @return string 生成的索引名称
     */
    private function genIndexName(string $table, array $columns, int $nonUnique): string
    {
        $abbr = implode('', array_map(fn ($word) => $word[0], explode('_', $table)));
        $prefix = $nonUnique === 1 ? 'idx_' : 'uni_';
        $tableHash = substr(md5($table), -4);
        $firstColumn = $columns[0] ?? 'col';

        $isSingleColumn = count($columns) <= 1;
        $columnsKey = implode(',', $columns);
        $columnsHash = $isSingleColumn ? null : substr(md5($columnsKey), 0, 8);

        $baseName = "{$prefix}{$abbr}_{$tableHash}_{$firstColumn}";
        $candidate = $isSingleColumn ? $baseName : "{$baseName}_{$columnsHash}";

        if (strlen($candidate) <= 64) {
            return $candidate;
        }

        $hash = substr(md5($table . '|' . $columnsKey . '|' . $nonUnique), 0, 16);
        return "{$prefix}{$abbr}_{$tableHash}_{$hash}";
    }
}
