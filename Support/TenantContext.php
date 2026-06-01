<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Support;

use Hyperf\Context\Context;
use Library\Constants\DataField;
use Library\Exception\ErrorResponseException;
use System\Model\SystemUser;

/**
 * 租户上下文。
 */
final class TenantContext
{
    public const UNSET_TENANT_ID = 0;

    public const DEFAULT_TENANT_ID = 1;

    /**
     * @deprecated tenant_id=0 仅表示运行期未建立租户上下文，不再表示平台租户。
     */
    public const PLATFORM_TENANT_ID = 0;

    private const EXPLICIT_TENANT_WRITE = 'tenant_id_explicit_write';

    /**
     * 获取当前租户 ID。
     */
    public static function get(?int $default = null): int
    {
        return (int)Context::get(DataField::TENANT, $default ?? self::UNSET_TENANT_ID);
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
     * 当前协程中是否已建立有效租户上下文。
     */
    public static function has(): bool
    {
        return self::get() > self::UNSET_TENANT_ID;
    }

    /**
     * 读取当前有效租户 ID；普通业务写入和查询前必须显式确认租户上下文，不能回退到 0。
     */
    public static function requireTenantId(string $message = '租户上下文无效'): int
    {
        $tenantId = self::get();
        if ($tenantId <= self::UNSET_TENANT_ID) {
            throw new ErrorResponseException($message);
        }

        return $tenantId;
    }

    /**
     * 清理租户上下文。
     */
    public static function clear(): void
    {
        Context::destroy(DataField::TENANT);
        Context::destroy(self::EXPLICIT_TENANT_WRITE);
    }

    /**
     * 当前账号是否具备平台运维能力。
     */
    public static function isPlatform(): bool
    {
        try {
            $user = user(SystemUser::class);
        } catch (\Throwable) {
            $user = null;
        }

        return $user !== null && $user->isSuper();
    }

    /**
     * 在无后台登录态的开放接口、回调或定时任务中，临时恢复已校验对象所属租户。
     */
    public static function withTenant(int $tenantId, callable $callback): mixed
    {
        if ($tenantId <= self::UNSET_TENANT_ID) {
            throw new \InvalidArgumentException('租户上下文必须大于 0');
        }

        $existed = self::has();
        $previous = self::get();
        self::set($tenantId);

        try {
            return $callback();
        } finally {
            if ($existed) {
                Context::set(DataField::TENANT, $previous);
            } else {
                Context::destroy(DataField::TENANT);
            }
        }
    }

    /**
     * 当前协程是否允许显式写入目标租户字段。
     */
    public static function canWriteExplicitTenant(): bool
    {
        return Context::get(self::EXPLICIT_TENANT_WRITE, false) === true;
    }

    /**
     * 在平台代维护、租户开通、开放回调等明确入口临时允许目标租户写入。
     */
    public static function withExplicitTenantWrite(callable $callback): mixed
    {
        $existed = Context::has(self::EXPLICIT_TENANT_WRITE);
        $previous = Context::get(self::EXPLICIT_TENANT_WRITE, false);
        Context::set(self::EXPLICIT_TENANT_WRITE, true);

        try {
            return $callback();
        } finally {
            if ($existed) {
                Context::set(self::EXPLICIT_TENANT_WRITE, $previous);
            } else {
                Context::destroy(self::EXPLICIT_TENANT_WRITE);
            }
        }
    }
}
