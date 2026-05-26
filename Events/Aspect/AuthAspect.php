<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Events\Aspect;

use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Di\Exception\Exception;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Library\Events\Annotation\Auth;
use Library\Exception\NotAllowResponseException;
use Library\Exception\UnauthorizedResponseException;
use Library\Interfaces\NodeNameResolverInterface;
use Library\Interfaces\UserLoginInterface;
use Library\Interfaces\UserModelInterface;

use function Hyperf\Translation\__;

/**
 * 权限认证切面。
 */
#[Aspect]
final class AuthAspect extends AbstractAspect
{
    public array $annotations = [
        Auth::class,
    ];

    public function __construct(
        protected RequestInterface $request,
        protected UserLoginInterface $userService,
        protected NodeNameResolverInterface $nodeNameResolver,
    ) {}

    /**
     * @throws Exception
     * @throws NotAllowResponseException
     * @throws UnauthorizedResponseException
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        $annotation = $proceedingJoinPoint->getAnnotationMetadata()->method[Auth::class]
            ?? $proceedingJoinPoint->getAnnotationMetadata()->class[Auth::class]
            ?? null;

        if (!$annotation) {
            return $proceedingJoinPoint->process();
        }

        $controllerMethod = $this->request->getAttribute(Dispatched::class)?->handler?->callback;
        if (is_string($controllerMethod)) {
            $node = Auth::parseNode($controllerMethod);
        } elseif (is_array($controllerMethod)) {
            $node = Auth::parseNode(join('@', $controllerMethod));
        } else {
            return $proceedingJoinPoint->process();
        }

        $auth = (clone $annotation)->with($node);
        $permission = $auth->node ?: $node;
        $userModel = $auth->userModel;
        // 鉴权入口只接受明确用户模型类名；System RBAC 与 Project 前台用户体系不再通过空值或动态 Provider 混合放行。
        $currentUser = $this->userService->getUser(null, $userModel);
        if ($currentUser && $currentUser->isSuper() && $this->matchesUserModel($currentUser, $userModel)) {
            return $proceedingJoinPoint->process();
        }

        $this->checkPermission($permission, $auth->type, $userModel, $currentUser, $auth->name);

        return $proceedingJoinPoint->process();
    }

    /**
     * @throws NotAllowResponseException
     * @throws UnauthorizedResponseException
     */
    protected function checkPermission(string $node, string $type, string $userModel, ?UserModelInterface $currentUser = null, ?string $fallbackName = null): bool
    {
        $currentUser ??= $this->userService->getUser(null, $userModel);
        // 权限节点未同步到菜单表时，使用注解名称兜底，避免日志里出现无法定位的“未命名菜单”。
        $nodeName = $this->nodeNameResolver->findNameByNode($node);
        $fallbackName = trim((string)$fallbackName);
        if ($nodeName === '未命名菜单') {
            $nodeName = $fallbackName !== '' ? sprintf('%s(%s)', $fallbackName, $node) : $node;
        }
        $unauthorizedMessage = (string)__('library.未登录授权');
        if ($unauthorizedMessage === 'library.未登录授权') {
            // 认证失败是高频全局入口，翻译组件未命中时不能把语言 Key 泄漏到前端提示或接口日志。
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

        return true;
    }

    /**
     * 超级管理员短路也必须匹配当前注解用户模型，避免 SystemUser 与 ProjectAccount 跨体系放行。
     */
    private function matchesUserModel(UserModelInterface $user, string $userModel): bool
    {
        return (class_exists($userModel) || interface_exists($userModel)) && is_a($user::class, $userModel, true);
    }
}
