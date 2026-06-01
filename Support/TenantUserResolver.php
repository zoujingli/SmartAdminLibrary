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

use Library\Constants\DataField;
use Library\CoreModel;
use Library\Interfaces\UserModelInterface;

/**
 * 登录用户租户解析器。
 *
 * CoreModel 的 toArray() 可能触发角色、权限等依赖租户上下文的附加查询；认证链路必须直接读取原始属性。
 */
final class TenantUserResolver
{
    public static function tenantId(UserModelInterface $user): int
    {
        if ($user instanceof CoreModel) {
            return (int)($user->getAttribute(DataField::TENANT) ?? TenantContext::UNSET_TENANT_ID);
        }

        return (int)($user->toArray()[DataField::TENANT] ?? TenantContext::UNSET_TENANT_ID);
    }
}
