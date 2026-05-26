<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Helper;

use Hyperf\Database\Schema\Blueprint;
use Hyperf\DbConnection\Db;

/**
 * 数据库结构辅助工具。
 * 统一封装迁移里高频出现的主键、审计字段与索引操作。
 */
final class SchemaHelper
{
    /**
     * 添加自增主键。
     */
    public static function addPrimaryId(Blueprint $table, string $comment = '主键ID'): void
    {
        $table->addColumn('bigInteger', 'id', ['autoIncrement' => true, 'unsigned' => true])->comment($comment ?: '主键ID');
    }

    /**
     * 添加无符号 bigint 字段。
     */
    public static function addUnsignedBigInteger(Blueprint $table, string $column, string $comment): void
    {
        $table->addColumn('bigInteger', $column, ['unsigned' => true])->comment($comment);
    }

    /**
     * 添加排序、状态、备注三组通用字段。
     */
    public static function addSortStatusRemarkColumns(Blueprint $table, int $remarkLength = 255): void
    {
        $table->addColumn('bigInteger', 'sort', [])->nullable()->default(0)->comment('排序权重');
        $table->addColumn('bigInteger', 'status', [])->nullable()->default(1)->comment('状态(1启用,0禁用)');
        $table->addColumn('string', 'remark', ['length' => $remarkLength])->nullable()->default('')->comment('备注');
    }

    /**
     * 添加创建/更新/删除审计字段。
     */
    public static function addAuditColumns(Blueprint $table, bool $withDeletedAt = true, bool $withUserDefaults = true): void
    {
        $createdBy = $table->addColumn('bigInteger', 'created_by', [])->nullable()->comment('创建者');
        $updatedBy = $table->addColumn('bigInteger', 'updated_by', [])->nullable()->comment('更新者');

        if ($withUserDefaults) {
            $createdBy->default(0);
            $updatedBy->default(0);
        }

        self::addTimestamps($table);

        if ($withDeletedAt) {
            $table->addColumn('timestamp', 'deleted_at', [])->nullable()->comment('删除时间');
        }
    }

    /**
     * @param array<string, string> $columns
     */
    public static function addPivotColumns(Blueprint $table, array $columns): void
    {
        foreach ($columns as $column => $comment) {
            self::addUnsignedBigInteger($table, $column, $comment);
        }

        $table->primary(array_keys($columns));
    }

    /**
     * 添加 created_at / updated_at 时间戳字段。
     */
    public static function addTimestamps(Blueprint $table): void
    {
        $table->addColumn('timestamp', 'created_at', [])->nullable()->comment('创建时间');
        $table->addColumn('timestamp', 'updated_at', [])->nullable()->comment('更新时间');
    }

    /**
     * 添加 dateTime 版本的审计字段。
     */
    public static function addAuditDateTimes(Blueprint $table, bool $withDeletedAt = true, bool $withUserDefaults = true): void
    {
        $createdBy = $table->addColumn('bigInteger', 'created_by', [])->nullable()->comment('创建者');
        $updatedBy = $table->addColumn('bigInteger', 'updated_by', [])->nullable()->comment('更新者');

        if ($withUserDefaults) {
            $createdBy->default(0);
            $updatedBy->default(0);
        }

        if ($withDeletedAt) {
            $table->addColumn('dateTime', 'deleted_at', [])->nullable()->comment('删除时间');
        }

        $table->addColumn('dateTime', 'created_at', [])->nullable()->comment('创建时间');
        $table->addColumn('dateTime', 'updated_at', [])->nullable()->comment('更新时间');
    }

    /**
     * 判断索引是否存在。
     */
    public static function hasIndex(string $table, string $index): bool
    {
        return Db::selectOne(sprintf('SHOW INDEX FROM `%s` WHERE Key_name = ?', $table), [$index]) !== null;
    }

    /**
     * @param array<int, string> $columns
     */
    public static function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if (self::hasIndex($table, $index)) {
            return;
        }

        Db::statement(sprintf(
            'ALTER TABLE `%s` ADD INDEX `%s` (%s)',
            $table,
            $index,
            self::quoteColumns($columns)
        ));
    }

    /**
     * @param array<int, string> $columns
     */
    public static function addUniqueIfMissing(string $table, string $index, array $columns): void
    {
        if (self::hasIndex($table, $index)) {
            return;
        }

        Db::statement(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE `%s` (%s)',
            $table,
            $index,
            self::quoteColumns($columns)
        ));
    }

    /**
     * 存在索引时再删除，避免重复执行迁移时报错。
     */
    public static function dropIndexIfExists(string $table, string $index): void
    {
        if (!self::hasIndex($table, $index)) {
            return;
        }

        Db::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
    }

    /**
     * @param array<int, string> $columns
     */
    public static function quoteColumns(array $columns): string
    {
        return implode(', ', array_map(static fn (string $column) => sprintf('`%s`', $column), $columns));
    }
}
