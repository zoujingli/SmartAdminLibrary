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
 * 状态常量.
 */
final class Status
{
    // 状态值
    public const ENABLED = 1;   // 启用

    public const DISABLED = 0;  // 禁用

    /**
     * 获取状态文本.
     */
    public static function getText(int $status): string
    {
        return match ($status) {
            self::ENABLED => '启用',
            self::DISABLED => '禁用',
            default => '未知',
        };
    }

    /**
     * 获取所有状态
     */
    public static function getAll(): array
    {
        return [
            self::ENABLED => '启用',
            self::DISABLED => '禁用',
        ];
    }

    /**
     * 检查是否启用.
     */
    public static function isEnabled(int $status): bool
    {
        return $status === self::ENABLED;
    }

    /**
     * 检查是否禁用.
     */
    public static function isDisabled(int $status): bool
    {
        return $status === self::DISABLED;
    }

    /**
     * 检查是否为有效状态
     */
    public static function isValid(int $status): bool
    {
        return array_key_exists($status, self::getAll());
    }
}
