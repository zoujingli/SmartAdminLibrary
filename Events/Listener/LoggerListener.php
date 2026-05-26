<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Events\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Library\Events\Event\Logger;
use Library\Interfaces\OperateLogWriterInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * 日志记录监听器
 * 处理 Logger 事件，将日志数据写入数据库.
 *
 * 请在应用 {@see config/autoload/listeners.php} 中注册本类，保证事件能写入 system_logs_action。
 */
class LoggerListener implements ListenerInterface
{
    protected array $ignoreRouter = [
        '/login',
        '/getInfo',
        '/system/captcha',
        '/system/logs/action/index',
    ];

    public function __construct(protected ContainerInterface $container) {}

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [Logger::class];
    }

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function process(object $event): void
    {
        if (!$event instanceof Logger) {
            return;
        }

        $requestInfo = $event->getRequestInfo();

        // 检查是否在忽略列表中
        if (!in_array($requestInfo['router'], $this->ignoreRouter)) {
            $writer = $this->container->get(OperateLogWriterInterface::class);

            // 处理请求数据
            if (isset($requestInfo['request_data'])) {
                if (is_array($requestInfo['request_data'])) {
                    $requestInfo['request_data'] = json_encode($requestInfo['request_data'], JSON_UNESCAPED_UNICODE);
                }
            }

            // 处理响应数据
            if (isset($requestInfo['response_data'])) {
                if (is_array($requestInfo['response_data'])) {
                    $requestInfo['response_data'] = json_encode($requestInfo['response_data'], JSON_UNESCAPED_UNICODE);
                }
            }

            $writer->write($requestInfo);
        }
    }
}
