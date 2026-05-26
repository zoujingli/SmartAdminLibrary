<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Service;

use Hyperf\Context\Context;
use Lcobucci\JWT\Token as JwtToken;
use Library\Auth\Token;
use Library\Constants\DataField;
use Library\Constants\Status;
use Library\CoreModel;
use Library\CoreService;
use Library\Exception\ErrorResponseException;
use Library\Helper\RequestHelper;
use Library\Interfaces\UserLoginInterface;
use Library\Interfaces\UserModelInterface;
use Library\Support\TenantContext;
use System\Model\SystemTenant;
use System\Model\SystemUser;

final class LoginService extends CoreService implements UserLoginInterface
{
    public function __construct(
        protected Token $token
    ) {}

    public function login(UserModelInterface $user): JwtToken
    {
        $userModel = get_class($user);
        if (!$this->isTenantAvailable($user)) {
            // 登录签发 Token 前必须先阻断禁用或过期租户；否则 Project 前台会出现“登录成功但资料接口立即 401”的割裂体验。
            throw new ErrorResponseException('租户已被禁用或已过期');
        }

        $this->token->setScene($userModel);
        $this->applyTenantContext($user);

        return $this->token->create([
            'uid' => $user->getId(),
            'class' => get_class($user),
        ]);
    }

    public function getUser(?string $token = null, ?string $userModel = null): ?UserModelInterface
    {
        try {
            if (($token === null || $token === '') && RequestHelper::getRequest() === null) {
                TenantContext::clear();

                return null;
            }

            $token = $token ?: $this->token->getHeaderToken();
            if ($token === null || $token === '') {
                TenantContext::clear();
                return null;
            }

            $userModel ??= SystemUser::class;
            $contextKey = 'login_service_user_' . md5($userModel . '|' . $token);
            if (Context::has($contextKey)) {
                $cached = Context::get($contextKey);
                if ($cached instanceof UserModelInterface) {
                    $this->applyTenantContext($cached);

                    return $cached;
                }

                TenantContext::clear();
                return null;
            }

            $claims = $this->token->getParserData($token);
            $uid = (int)($claims['uid'] ?? 0);
            $class = (string)($claims['class'] ?? '');

            if ($uid <= 0 || $class === '') {
                TenantContext::clear();
                return null;
            }

            if (!$this->userModelMatches($class, $userModel)) {
                // 同一请求内可能先按 ProjectAccount 建立了租户上下文，再由通用模型范围或组件误用默认 SystemUser 读取当前用户。
                // 模型类型不匹配只表示“该用户模型未登录”，不能清空已经由真实登录态设置好的租户边界。
                return null;
            }

            if (!class_exists($class) || !is_subclass_of($class, UserModelInterface::class)) {
                TenantContext::clear();
                return null;
            }

            /** @var null|UserModelInterface $user */
            $query = $class::query();
            if (is_subclass_of($class, CoreModel::class)) {
                $query->withoutGlobalScope(DataField::TENANT);
            }
            $user = $query->find($uid);
            if ($user instanceof CoreModel && array_key_exists('status', $user->getAttributes()) && !Status::isEnabled((int)$user->status)) {
                Context::set($contextKey, null);
                TenantContext::clear();

                return null;
            }

            if ($user instanceof UserModelInterface && !$this->isTenantAvailable($user)) {
                Context::set($contextKey, null);
                TenantContext::clear();

                return null;
            }

            Context::set($contextKey, $user);
            if ($user instanceof UserModelInterface) {
                $this->applyTenantContext($user);
            } else {
                TenantContext::clear();
            }

            return $user;
        } catch (\Throwable $e) {
            error_log(sprintf(
                'LoginService::getUser error: %s file: %s line: %d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            TenantContext::clear();
            return null;
        }
    }

    public function isLogin(): bool
    {
        return $this->getUser() !== null;
    }

    public function logout(): bool
    {
        try {
            return $this->token->logout();
        } catch (\Throwable) {
            return false;
        }
    }

    public function checkAuth(string $node, string $userModel): bool
    {
        $user = $this->getUser(null, $userModel);
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($node);
        }

        return false;
    }

    /**
     * Token 中的 class 必须与注解声明的用户模型类一致，防止后台与前台账号跨体系复用 Token。
     */
    private function userModelMatches(string $class, string $userModel): bool
    {
        if ($class === $userModel) {
            return true;
        }

        // 少量共享登录态接口只关心“是否为合法登录用户”，可声明 UserModelInterface 作为模型边界；
        // 这里同时接受接口与类名，避免 ProjectAccount 这类前台账号被 SystemUser 默认值误判为未登录。
        return (class_exists($userModel) || interface_exists($userModel)) && is_a($class, $userModel, true);
    }

    private function applyTenantContext(UserModelInterface $user): void
    {
        $tenantId = $this->resolveTenantId($user);
        TenantContext::set($tenantId);
    }

    /**
     * 公共 user() 助手也需要校验租户状态，避免绕过 AuthAspect 时恢复禁用或过期租户上下文。
     */
    private function isTenantAvailable(UserModelInterface $user): bool
    {
        $tenantId = $this->resolveTenantId($user);
        if ($tenantId <= 0) {
            return true;
        }

        $tenant = SystemTenant::query()->find($tenantId);
        if (!$tenant || !Status::isEnabled((int)$tenant->status)) {
            return false;
        }

        $expiredAt = trim((string)($tenant->expired_at ?? ''));
        return $expiredAt === '' || strtotime($expiredAt) === false || strtotime($expiredAt) >= time();
    }

    private function resolveTenantId(UserModelInterface $user): int
    {
        if ($user instanceof CoreModel) {
            // ProjectAccount::toArray() 会补角色和权限展示信息；登录解析早于租户上下文建立，
            // 这里必须直接读取模型原始属性，避免角色关系按旧租户上下文被误缓存为空。
            return (int)($user->getAttribute(DataField::TENANT) ?? TenantContext::PLATFORM_TENANT_ID);
        }

        return (int)($user->toArray()[DataField::TENANT] ?? TenantContext::PLATFORM_TENANT_ID);
    }
}
