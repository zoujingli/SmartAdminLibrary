<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Events\Event;

/**
 * 日志记录事件
 * 用于传递日志数据到监听器.
 */
class Logger
{
    public function __construct(
        protected array $requestInfo
    ) {}

    public function getRequestInfo(): array
    {
        return $this->requestInfo;
    }
}
