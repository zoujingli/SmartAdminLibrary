<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Constants;

/**
 * 数据权限范围常量
 * 定义4级数据权限控制级别.
 */
final class DataScope
{
    /** @var int 全部数据权限 */
    public const ALL = 1;

    /** @var int 本部门数据权限 */
    public const DEPT = 2;

    /** @var int 本部门及以下数据权限 */
    public const CHILD = 3;

    /** @var int 仅本人数据权限 */
    public const SELF = 4;

    /**
     * 获取数据权限范围文本.
     */
    public static function getText(int $scope): string
    {
        return match ($scope) {
            self::ALL => '全部数据',
            self::DEPT => '本部门数据',
            self::CHILD => '本部门及以下数据',
            self::SELF => '本人数据',
            default => '未知',
        };
    }

    /**
     * 获取所有数据权限范围.
     */
    public static function getAll(): array
    {
        return [
            self::ALL => '全部数据',
            self::DEPT => '本部门数据',
            self::CHILD => '本部门及以下数据',
            self::SELF => '本人数据',
        ];
    }

    /**
     * 获取默认数据权限范围.
     */
    public static function getDefault(): int
    {
        return self::SELF;
    }

    /**
     * 检查是否为有效的数据权限范围.
     */
    public static function isValid(int $scope): bool
    {
        return array_key_exists($scope, self::getAll());
    }

    /**
     * 从多个范围中取最严格的数据范围。
     *
     * 当前枚举数值越大范围越窄：ALL < DEPT < CHILD < SELF。
     *
     * @param array<int, int> $scopes
     */
    public static function strictest(array $scopes): int
    {
        $valid = array_values(array_filter($scopes, static fn (int $scope): bool => self::isValid($scope)));

        return $valid === [] ? self::getDefault() : max($valid);
    }
}
