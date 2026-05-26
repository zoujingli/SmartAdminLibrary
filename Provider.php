<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library;

use Hyperf\Contract\ContainerInterface;
use Hyperf\Contract\TranslatorLoaderInterface;
use Hyperf\HttpServer\CoreMiddleware;
use Hyperf\Logger\LoggerFactory;
use Library\Events\OperateLogRecorder;
use Library\Interfaces\UserLoginInterface;
use Library\Middleware\CorsMiddleware;
use Library\Middleware\LocaleMiddleware;
use Library\Middleware\LogsMiddleware;
use Library\Middleware\SiteMiddleware;
use Library\Service\LoginService;
use Library\Service\ScopeService;
use Library\Translation\PluginFileLoaderFactory;
use Psr\Log\LoggerInterface;

/**
 * Library 插件服务提供者
 * 注册监听器、中间件和依赖注入；控制台命令统一由 Command 注解声明并通过扫描收集.
 */
final class Provider
{
    /**
     * 获取服务配置.
     */
    public function __invoke(): array
    {
        return [
            'listeners' => [],
            'dependencies' => [
                UserLoginInterface::class => LoginService::class,
                OperateLogRecorder::class => OperateLogRecorder::class,
                ScopeService::class => ScopeService::class,
                TranslatorLoaderInterface::class => PluginFileLoaderFactory::class,
                LoggerInterface::class => static fn (ContainerInterface $container) => $container->get(LoggerFactory::class)->get('log'),
                CoreMiddleware::class => SiteMiddleware::class,
            ],
            'middlewares' => [
                'http' => [
                    CorsMiddleware::class, // CORS 中间件
                    LocaleMiddleware::class, // 请求语言中间件
                    LogsMiddleware::class, // 请求日志中间件
                ],
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [__DIR__],
                    'collectors' => [],
                ],
            ],
        ];
    }
}
