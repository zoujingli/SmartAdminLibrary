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
use Hyperf\HttpServer\Router\Dispatched;
use Library\Events\Annotation\Auth;
use Library\Events\Annotation\Logger;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 路由注解解析器。
 *
 * final 控制器的 AOP 可能不稳定，鉴权与操作日志都需要从当前路由反射注解；统一解析可避免 Auth 与 Logger
 * 对 callback 格式、方法优先级和兜底名称的处理产生偏差。
 */
final class RouteAnnotationResolver
{
    private const CONTEXT_ROUTE_METHOD = '__library.route_annotation.method';

    private const CONTEXT_AUTH = '__library.route_annotation.auth';

    private const CONTEXT_LOGGER = '__library.route_annotation.logger';

    /**
     * @return null|array{class: class-string, method: string, controller: string, fallback: string}
     */
    public static function resolveControllerMethod(ServerRequestInterface $request): ?array
    {
        $cached = Context::get(self::CONTEXT_ROUTE_METHOD);
        if (is_array($cached)) {
            return $cached;
        }

        $dispatched = $request->getAttribute(Dispatched::class);
        if (!$dispatched instanceof Dispatched || !isset($dispatched->handler->callback)) {
            return null;
        }

        $parsed = self::parseCallback($dispatched->handler->callback);
        if ($parsed === null) {
            return null;
        }

        [$class, $method] = $parsed;
        $short = basename(str_replace('\\', '/', $class));
        $resolved = [
            'class' => $class,
            'method' => $method,
            'controller' => "{$class}@{$method}",
            'fallback' => "{$short}::{$method}",
        ];
        Context::set(self::CONTEXT_ROUTE_METHOD, $resolved);

        return $resolved;
    }

    /**
     * @return null|array{0: Auth, 1: string}
     */
    public static function resolveAuth(ServerRequestInterface $request): ?array
    {
        $cached = Context::get(self::CONTEXT_AUTH);
        if ($cached === false || is_array($cached)) {
            return $cached ?: null;
        }

        $route = self::resolveControllerMethod($request);
        if ($route === null) {
            Context::set(self::CONTEXT_AUTH, false);
            return null;
        }

        try {
            $methodRef = new \ReflectionMethod($route['class'], $route['method']);
            $classRef = new \ReflectionClass($route['class']);
        } catch (\ReflectionException) {
            Context::set(self::CONTEXT_AUTH, false);
            return null;
        }

        // Auth 支持类级别兜底，方法级注解优先，保持原有 AOP 语义。
        $methodAttrs = $methodRef->getAttributes(Auth::class);
        $classAttrs = $classRef->getAttributes(Auth::class);
        $attrs = $methodAttrs !== [] ? $methodAttrs : $classAttrs;
        if ($attrs === []) {
            Context::set(self::CONTEXT_AUTH, false);
            return null;
        }

        $resolved = [$attrs[0]->newInstance(), $route['controller']];
        Context::set(self::CONTEXT_AUTH, $resolved);

        return $resolved;
    }

    /**
     * @return null|array{0: Logger, 1: string}
     */
    public static function resolveLogger(ServerRequestInterface $request): ?array
    {
        $cached = Context::get(self::CONTEXT_LOGGER);
        if ($cached === false || is_array($cached)) {
            return $cached ?: null;
        }

        $route = self::resolveControllerMethod($request);
        if ($route === null) {
            Context::set(self::CONTEXT_LOGGER, false);
            return null;
        }

        try {
            $methodRef = new \ReflectionMethod($route['class'], $route['method']);
        } catch (\ReflectionException) {
            Context::set(self::CONTEXT_LOGGER, false);
            return null;
        }

        // Logger 只允许方法级注解，避免类级日志误覆盖多个写操作。
        $attrs = $methodRef->getAttributes(Logger::class);
        if ($attrs === []) {
            Context::set(self::CONTEXT_LOGGER, false);
            return null;
        }

        $resolved = [$attrs[0]->newInstance(), $route['fallback']];
        Context::set(self::CONTEXT_LOGGER, $resolved);

        return $resolved;
    }

    /**
     * @return null|array{0: class-string, 1: string}
     */
    private static function parseCallback(mixed $callback): ?array
    {
        $class = null;
        $method = null;
        if (is_string($callback)) {
            if (str_contains($callback, '@')) {
                [$class, $method] = explode('@', $callback, 2);
            } elseif (str_contains($callback, '::')) {
                [$class, $method] = explode('::', $callback, 2);
            }
        } elseif (is_array($callback) && count($callback) >= 2) {
            [$ctrl, $method] = $callback;
            $class = is_object($ctrl) ? $ctrl::class : (string)$ctrl;
            $method = (string)$method;
        }

        $class = trim((string)$class);
        $method = trim((string)$method);
        if ($class === '' || $method === '' || !class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        return [$class, $method];
    }
}
