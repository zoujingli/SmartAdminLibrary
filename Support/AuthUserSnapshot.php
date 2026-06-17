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
use Library\Interfaces\UserModelInterface;

/**
 * 登录用户最小快照。
 *
 * 鉴权和操作日志只共享审计必需字段，避免在登出、异常链路里再次 toArray() 触发关联查询或丢失插件账号租户。
 */
final class AuthUserSnapshot
{
    public const CONTEXT_USER_ROW_PREFIX = '__library.auth.user_row.';

    /**
     * @return array{id:int, username:string, tenant_id:int, customer_id?:int}
     */
    public static function fromUser(UserModelInterface $user): array
    {
        $row = [
            'id' => $user->getId(),
            'username' => $user->getName(),
            DataField::TENANT => TenantUserResolver::tenantId($user),
        ];
        if (method_exists($user, 'getAttribute')) {
            $customerId = (int)($user->getAttribute('customer_id') ?? 0);
            if ($customerId > 0) {
                // License 等插件账号需要把绑定客户写入业务审计日志；不存在该字段的系统账号保持原快照结构。
                $row['customer_id'] = $customerId;
            }
        }

        return $row;
    }

    /**
     * @param class-string|string $declaredModel
     */
    public static function remember(string $declaredModel, UserModelInterface $user): void
    {
        $row = self::fromUser($user);
        Context::set(self::key($declaredModel), $row);
        Context::set(self::key($user::class), $row);
    }

    /**
     * @param class-string|string $userModel
     * @return array<string, mixed>
     */
    public static function get(string $userModel): array
    {
        $row = Context::get(self::key($userModel));

        return is_array($row) ? $row : [];
    }

    private static function key(string $userModel): string
    {
        return self::CONTEXT_USER_ROW_PREFIX . ltrim($userModel, '\\');
    }
}
