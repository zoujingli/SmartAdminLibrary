<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Events\Aspect;

use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Di\Exception\Exception;
use Hyperf\HttpServer\Contract\RequestInterface;
use Library\Events\Annotation\Auth;
use Library\Exception\NotAllowResponseException;
use Library\Exception\UnauthorizedResponseException;
use Library\Service\AuthGuardService;
use Library\Support\RouteAnnotationResolver;

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
        protected AuthGuardService $guard,
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

        $route = RouteAnnotationResolver::resolveControllerMethod($this->request);
        $controllerMethod = $route['controller'] ?? "{$proceedingJoinPoint->className}@{$proceedingJoinPoint->methodName}";
        $this->guard->guard($annotation, $controllerMethod);

        return $proceedingJoinPoint->process();
    }
}
