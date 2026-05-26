<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Logger\Processor;

use Hyperf\Coroutine\Coroutine;
use Library\Logger\RequestIdHolder;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * UUID 请求 ID 处理器
 * 为日志记录添加请求 ID 和协程 ID.
 */
final class RequestIdProcessor implements ProcessorInterface
{
    /**
     * 处理日志记录
     * 添加请求 ID 和协程 ID 到 extra 字段.
     */
    public function __invoke(array|LogRecord $record): array|LogRecord
    {
        $record['extra']['request_id'] = RequestIdHolder::getId();
        $record['extra']['coroutine_id'] = Coroutine::id();
        return $record;
    }
}
