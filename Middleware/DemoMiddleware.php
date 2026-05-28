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

use Hyperf\Contract\ConfigInterface;
use Library\Exception\ErrorResponseException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 演示环境写入保护中间件。
 *
 * 仅在 APP_ENV=demo 时拦截关键资料的修改、删除、恢复和禁用类请求；
 * 普通环境完全透传，避免影响真实部署和本地开发。
 */
final class DemoMiddleware implements MiddlewareInterface
{
    private const DENY_MESSAGE = '演示环境禁止修改关键数据';

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const SYSTEM_SAFE_POST_PATHS = [
        '/system/auth/login',
        '/system/auth/refresh',
        '/system/auth/profile',
        '/system/auth/logout',
    ];

    private const PROJECT_AUTH_SAFE_POST_PATHS = [
        '/project/account/auth/login',
        '/project/account/auth/dingtalk-login',
        '/project/account/auth/refresh',
        '/project/account/auth/profile',
        '/project/account/auth/logout',
    ];

    public function __construct(
        private readonly ConfigInterface $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (!$this->isDemoMode() || !in_array($method, self::WRITE_METHODS, true)) {
            return $handler->handle($request);
        }

        $path = $this->normalizePath($request->getUri()->getPath());
        if ($this->shouldBlock($method, $path)) {
            throw new ErrorResponseException(self::DENY_MESSAGE);
        }

        return $handler->handle($request);
    }

    private function isDemoMode(): bool
    {
        return strtolower((string)$this->config->get('app_env', 'dev')) === 'demo';
    }

    private function shouldBlock(string $method, string $path): bool
    {
        return match (true) {
            str_starts_with($path, '/system/') => $this->shouldBlockSystem($method, $path),
            str_starts_with($path, '/project/') => $this->shouldBlockProject($method, $path),
            str_starts_with($path, '/smart/') => $this->shouldBlockSmart($method, $path),
            str_starts_with($path, '/wechat-client/') => !str_starts_with($path, '/wechat-client/api/'),
            str_starts_with($path, '/wechat-service/') => !str_starts_with($path, '/wechat-service/api/'),
            default => false,
        };
    }

    private function shouldBlockSystem(string $method, string $path): bool
    {
        // System 后台演示默认只读，只保留登录、刷新、退出和 profile 读取这类不会修改业务资料的 POST。
        return $method !== 'POST' || !in_array($path, self::SYSTEM_SAFE_POST_PATHS, true);
    }

    private function shouldBlockProject(string $method, string $path): bool
    {
        if (str_starts_with($path, '/project/account/auth/')) {
            return $method !== 'POST' || !in_array($path, self::PROJECT_AUTH_SAFE_POST_PATHS, true);
        }

        if (str_starts_with($path, '/project/account/') || str_starts_with($path, '/project/dingtalk/')) {
            return true;
        }

        // Project 演示允许任务、测试、Bug 等流程体验，但删除、恢复、账号配置和启停类操作必须保护。
        if ($method === 'DELETE' || preg_match('#/(?:real-delete|delete|recovery)(?:/|$)#', $path) === 1) {
            return true;
        }

        return preg_match('#^/project/(?:product|feature|version)/status(?:/|$)#', $path) === 1;
    }

    private function shouldBlockSmart(string $method, string $path): bool
    {
        // 智能通道配置、恢复、删除和开关会影响全局 AI 通道；测试调用不在此处拦截。
        return $path === '/smart/config'
            || str_starts_with($path, '/smart/config/')
            || ($method !== 'GET' && $path === '/smart/pool/status');
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim((string)preg_replace('#/+#', '/', $path), '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
