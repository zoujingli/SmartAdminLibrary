<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Logger\Handler;

use Hyperf\Contract\StdoutLoggerInterface;
use Library\Constants\System;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * 控制台日志处理器
 * 将日志输出到控制台，支持彩色显示.
 */
final class StdoutLoggerHandler extends AbstractHandler
{
    /**
     * 构造函数.
     * @param mixed $level
     */
    public function __construct(
        private readonly StdoutLoggerInterface $stdout,
        $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * 处理日志记录.
     */
    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        // 构建带时间的消息内容
        $message = "[{$record->datetime->format('Y-m-d H:i:s')}] {$record->message}";

        // 添加上下文信息
        if (!empty($record->context)) {
            $message .= ' ' . json_encode($record->context, JSON_UNESCAPED_UNICODE);
        }

        // 添加额外信息
        if (!empty($record->extra)) {
            $message .= ' ' . json_encode($record->extra, JSON_UNESCAPED_UNICODE);
        }

        // 线上日志不输出文件后缀
        if (System::isPharMode()) {
            $message = str_replace(['.php', 'phar://'], '', $message);
        }

        // 使用 StdoutLogger 的 log 方法，让它自己处理日志级别
        $this->stdout->log(strtolower($record->level->name), $message);

        return $this->bubble === false;
    }
}
