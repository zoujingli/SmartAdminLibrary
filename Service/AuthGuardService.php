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
use Library\Events\Annotation\Auth;
use Library\Exception\NotAllowResponseException;
use Library\Exception\UnauthorizedResponseException;
use Library\Interfaces\NodeNameResolverInterface;
use Library\Interfaces\UserLoginInterface;
use Library\Interfaces\UserModelInterface;
use Library\Support\AuthUserSnapshot;
use System\Model\SystemUser;

use function Hyperf\Translation\__;

/**
 * 路由鉴权执行器。
 *
 * AOP 与中间件兜底共用同一套校验逻辑，避免 final 控制器未织入切面时绕过 Auth 注解。
 */
final class AuthGuardService
{
    private const CONTEXT_AUTH_CHECKED_PREFIX = '__library.auth.checked.';

    public function __construct(
        private readonly UserLoginInterface $userService,
        private readonly NodeNameResolverInterface $nodeNameResolver,
    ) {}

    /**
     * @throws NotAllowResponseException
     * @throws UnauthorizedResponseException
     */
    public function guard(Auth $annotation, string $controllerMethod): void
    {
        $auth = (clone $annotation)->with($controllerMethod);
        $permission = $auth->node ?: Auth::parseNode($controllerMethod) ?: $controllerMethod;
        $userModel = $auth->userModel;
        $contextKey = self::CONTEXT_AUTH_CHECKED_PREFIX . md5($permission . '|' . $auth->type . '|' . $userModel);

        if (Context::get($contextKey, false) === true) {
            return;
        }

        // 鉴权入口只接受明确用户模型类名；System RBAC 与插件账号体系不能通过默认 SystemUser 混合放行。
        $currentUser = $this->userService->getUser(null, $userModel);
        if ($currentUser !== null) {
            AuthUserSnapshot::remember($userModel, $currentUser);
        }

        if ($this->canBypassAsPlatformSuper($currentUser, $userModel)) {
            Context::set($contextKey, true);
            return;
        }

        $this->checkPermission($permission, $auth->type, $userModel, $currentUser, $auth->name);
        Context::set($contextKey, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function authenticatedUserRow(string $userModel): array
    {
        return AuthUserSnapshot::get($userModel);
    }

    /**
     * @throws NotAllowResponseException
     * @throws UnauthorizedResponseException
     */
    private function checkPermission(string $node, string $type, string $userModel, ?UserModelInterface $currentUser, ?string $fallbackName): void
    {
        $nodeName = $this->nodeNameResolver->findNameByNode($node);
        $fallbackName = trim((string)$fallbackName);
        if ($nodeName === '未命名菜单') {
            $nodeName = $fallbackName !== '' ? sprintf('%s(%s)', $fallbackName, $node) : $node;
        }

        $unauthorizedMessage = (string)__('library.未登录授权');
        if ($unauthorizedMessage === 'library.未登录授权') {
            // 翻译组件未命中时不能把语言 Key 泄漏到前端提示或接口日志。
            $unauthorizedMessage = '未登录授权';
        }

        if ($type === Auth::LOGIN && !$currentUser) {
            throw new UnauthorizedResponseException(sprintf('%s -> [ %s ]', $unauthorizedMessage, $nodeName));
        }

        if (in_array($type, [Auth::CHECK, 'auth'], true) && !$currentUser) {
            throw new UnauthorizedResponseException(sprintf('%s -> [ %s ]', $unauthorizedMessage, $nodeName));
        }

        if (in_array($type, [Auth::CHECK, 'auth'], true) && !$this->userService->checkAuth($node, $userModel)) {
            throw new NotAllowResponseException(sprintf('%s -> [ %s ]', __('library.无权限访问'), $nodeName));
        }
    }

    /**
     * 超级管理员短路也必须匹配当前注解用户模型，避免 SystemUser 与插件账号跨体系放行。
     */
    private function canBypassAsPlatformSuper(?UserModelInterface $user, string $userModel): bool
    {
        // 只有 System 平台超级管理员能在通用鉴权层短路；Project/Asset/Points 等插件账号的管理员语义由自身 hasPermission() 处理。
        return $user instanceof SystemUser
            && $user->isSuper()
            && (class_exists($userModel) || interface_exists($userModel))
            && is_a($user::class, $userModel, true);
    }
}
