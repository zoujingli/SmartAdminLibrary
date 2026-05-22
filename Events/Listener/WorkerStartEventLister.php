<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Events\Listener;

use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Library\Helper\RequestHelper;
use Psr\Log\LoggerInterface;

/**
 * 系统启动事件监听.
 * @class WorkerStartEventLister
 */
#[Listener]
final class WorkerStartEventLister implements ListenerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * 监听事件.
     */
    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
        ];
    }

    /**
     * Worker 启动阶段预热 IP2region。
     *
     * 预热失败只写告警，不阻断 Worker 启动；查询实例本身不携带请求态，可安全跨协程复用。
     */
    public function process(object $event): void
    {
        if (!$event instanceof BeforeWorkerStart) {
            return;
        }

        try {
            RequestHelper::warmupIp2Region();
        } catch (\Throwable $exception) {
            $this->logger->warning('IP2region 预热失败', [
                'pid' => getmypid(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'worker_id' => $event->workerId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
