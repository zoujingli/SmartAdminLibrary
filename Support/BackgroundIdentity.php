<?php

declare(strict_types=1);

namespace Library\Support;

use Hyperf\Context\Context;
use Library\Constants\DataField;
use Library\Constants\Status;
use Library\CoreModel;
use Library\Exception\ErrorResponseException;
use Library\Helper\RequestHelper;
use Library\Interfaces\UserModelInterface;
use System\Model\SystemTenant;

/**
 * 后台任务的受控身份作用域。只记录身份与失效指纹，不持久化 JWT、密码或会话声明。
 * 调用方必须从服务端注册表传入允许的用户模型，并为每次执行创建独立协程。
 */
final class BackgroundIdentity
{
    private const KEY = 'library.background_identity';

    /** @return array{user_model:string,user_id:int,tenant_id:int,auth_fingerprint:string} */
    public static function capture(UserModelInterface $user): array
    {
        $user instanceof CoreModel || throw new ErrorResponseException('后台任务不支持此账号类型');
        // 原始属性不触发角色/数据范围缓存；密码仅参与单向指纹，不离开当前进程。
        $claims = method_exists($user, 'loginClaims') ? $user->loginClaims() : [];
        $extra = $user->getAttribute('extra');
        $invalidBefore = is_array($extra) ? ($extra['session_invalid_before'] ?? '') : '';
        return [
            'user_model' => $user::class,
            'user_id' => $user->getId(),
            'tenant_id' => TenantUserResolver::tenantId($user),
            'auth_fingerprint' => hash('sha256', json_encode([
                $user->getAttribute('password'), $claims, $invalidBefore,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * 每次从数据库重新确认账号、租户、会话版本及操作权限，再建立协程身份。
     * HTTP 请求不能用此入口覆盖登录态；业务记录范围仍由原 Service/Mapper 检查。
     * @param array<string,mixed> $identity
     */
    public static function run(array $identity, string $allowedModel, string $permission, callable $callback): mixed
    {
        if (RequestHelper::getRequest() !== null || Context::has(self::KEY)) {
            throw new ErrorResponseException('后台身份只能在独立任务协程中恢复');
        }
        if (($identity['user_model'] ?? '') !== $allowedModel
            || !is_subclass_of($allowedModel, CoreModel::class)
            || !is_subclass_of($allowedModel, UserModelInterface::class)) {
            throw new ErrorResponseException('后台任务账号类型无效');
        }
        $tenantId = (int)($identity['tenant_id'] ?? 0);
        return TenantContext::withTenant($tenantId, static function () use ($identity, $allowedModel, $permission, $callback, $tenantId): mixed {
            // 仅身份重建跨过模型租户 scope，并立即用任务内的租户 ID 约束查询。
            $user = $allowedModel::query()->withoutGlobalScope(DataField::TENANT)
                ->where('tenant_id', $tenantId)->find((int)($identity['user_id'] ?? 0));
            $tenant = SystemTenant::query()->find($tenantId);
            if (!$user instanceof UserModelInterface || !Status::isEnabled((int)$user->getAttribute('status'))
                || !$tenant || !Status::isEnabled((int)$tenant->status)
                || (strtotime((string)$tenant->expired_at) !== false && strtotime((string)$tenant->expired_at) < time())
                || !hash_equals((string)($identity['auth_fingerprint'] ?? ''), self::capture($user)['auth_fingerprint'])) {
                throw new ErrorResponseException('任务账号、租户或会话已失效，请重新生成');
            }
            $claims = method_exists($user, 'loginClaims') ? (array)$user->loginClaims() : [];
            $claims = array_merge($claims, ['class' => $allowedModel, 'uid' => $user->getId(), 'iat' => time()]);
            Context::set(self::KEY, ['user' => $user, 'claims' => $claims]);
            try {
                if ((method_exists($user, 'isSessionTokenValid') && !$user->isSessionTokenValid($claims)) || !$user->hasPermission($permission)) {
                    throw new ErrorResponseException('任务操作权限已失效，请重新生成');
                }
                return $callback($user);
            } finally {
                Context::destroy(self::KEY);
            }
        });
    }

    public static function user(string $model): ?UserModelInterface
    {
        if (RequestHelper::getRequest() !== null) {
            return null;
        }
        $state = Context::get(self::KEY, []);
        $user = $state['user'] ?? null;
        return $user instanceof UserModelInterface && is_a($user, $model) ? $user : null;
    }

    /** @return array<string,mixed> */
    public static function claims(): array
    {
        return RequestHelper::getRequest() === null ? (Context::get(self::KEY, [])['claims'] ?? []) : [];
    }
}
