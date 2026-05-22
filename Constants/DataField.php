<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Constants;

/**
 * 数据权限字段常量.
 */
final class DataField
{
    // 数据权限字段

    public const TENANT = 'tenant_id';

    public const DEPT_ID = 'dept_id';        // 部门字段

    public const USER_ID = 'user_id';        // 用户字段

    public const CREATED_BY = 'created_by';  // 创建者字段

    public const UPDATED_BY = 'updated_by';  // 更新者字段

    /**
     * 获取租户隔离字段。
     */
    public static function getTenant(): string
    {
        return self::TENANT;
    }

    /**
     * 获取默认用户字段.
     */
    public static function getDefault(): string
    {
        return self::CREATED_BY;
    }

    /**
     * 获取所有字段.
     */
    public static function getAll(): array
    {
        return [
            self::DEPT_ID => '部门字段',
            self::USER_ID => '用户字段',
            self::CREATED_BY => '创建者字段',
        ];
    }

    /**
     * 检查是否为有效字段.
     */
    public static function isValid(string $field): bool
    {
        return in_array($field, [self::CREATED_BY, self::DEPT_ID, self::USER_ID]);
    }
}
