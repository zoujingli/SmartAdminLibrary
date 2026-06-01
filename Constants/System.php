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

use Library\Support\TenantContext;

use function Hyperf\Support\env;

/**
 * 系统常量.
 */
final class System
{
    // 系统状态码
    public const ERROR = 500;      // 业务异常、数据不存在或系统内部错误

    public const SUCCESS = 200;    // 系统操作成功

    public const UNAUTHORIZED = 401; // Token 缺失、过期或无效

    public const NOT_ALLOW = 403;  // Token 有效但无操作权限

    public const NOT_FOUND = 404;  // 页面或 API 路由不存在

    /**
     * 获取应用名称.
     */
    public static function getName(): string
    {
        return env('APP_NAME') ?: 'SmartAdmin';
    }

    /**
     * 获取超管理用户ID.
     */
    public static function getSuperId(): int
    {
        return intval(env('APP_SUPER_USER') ?: 1);
    }

    /**
     * 检查运行环境.
     */
    public static function isPharMode(): bool
    {
        return \Phar::running(false) !== '';
    }

    /**
     * 获取当前租户 ID。
     */
    public static function getTenantId(): int
    {
        return TenantContext::get();
    }

    /**
     * 设置当前租户 ID。
     */
    public static function setTenantId(int $tenantId): int
    {
        return TenantContext::set($tenantId);
    }

    /**
     * 当前账号是否具备平台运维能力。
     */
    public static function isPlatformTenant(): bool
    {
        return TenantContext::isPlatform();
    }
}
