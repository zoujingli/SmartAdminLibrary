<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Support;

use Hyperf\Context\Context;
use Library\Constants\DataField;

/**
 * 租户上下文。
 */
final class TenantContext
{
    public const PLATFORM_TENANT_ID = 0;

    /**
     * 获取当前租户 ID。
     */
    public static function get(?int $default = null): int
    {
        return (int)Context::get(DataField::TENANT, $default ?? self::PLATFORM_TENANT_ID);
    }

    /**
     * 设置当前租户 ID。
     */
    public static function set(int $tenantId): int
    {
        Context::set(DataField::TENANT, $tenantId);

        return $tenantId;
    }

    /**
     * 当前协程中是否已写入租户上下文。
     */
    public static function has(): bool
    {
        return Context::has(DataField::TENANT);
    }

    /**
     * 清理租户上下文。
     */
    public static function clear(): void
    {
        Context::set(DataField::TENANT, self::PLATFORM_TENANT_ID);
    }

    /**
     * 是否为平台空间。
     */
    public static function isPlatform(): bool
    {
        return self::get() === self::PLATFORM_TENANT_ID;
    }
}
