<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Middleware;

use Library\Service\AuthGuardService;
use Library\Support\RouteAnnotationResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 路由 Auth 注解兜底中间件。
 *
 * final 控制器可能绕过 AOP 织入，进入控制器前按路由反射再执行一次鉴权；已由 AOP 校验的请求会通过上下文标记去重。
 */
final class AuthRouteGuardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthGuardService $guard,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $resolved = RouteAnnotationResolver::resolveAuth($request);
        if ($resolved !== null) {
            [$auth, $controllerMethod] = $resolved;
            $this->guard->guard($auth, $controllerMethod);
        }

        return $handler->handle($request);
    }
}
