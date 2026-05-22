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
 * 认证类型常量.
 */
final class AuthType
{
    // 认证类型
    public const AUTH = 'auth';    // 权限检查

    public const LOGIN = 'login';  // 登录检查

    /**
     * 获取认证类型文本.
     */
    public static function getText(string $type): string
    {
        return match ($type) {
            self::AUTH => '权限检查',
            self::LOGIN => '登录检查',
            default => '未知',
        };
    }

    /**
     * 获取所有认证类型.
     */
    public static function getAll(): array
    {
        return [
            self::AUTH => '权限检查',
            self::LOGIN => '登录检查',
        ];
    }

    /**
     * 检查是否为有效认证类型.
     */
    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::getAll());
    }
}
