<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Helper;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Redis\Redis;
use Psr\Log\LogLevel;

/**
 * 后台异步任务输出器。
 *
 * 任务内只通过该对象写日志和进度，前端统一轮询任务状态接口读取，避免业务服务各自维护进度结构。
 */
final class TaskStdout implements StdoutLoggerInterface
{
    public function __construct(
        private Redis $redis,
        private StdoutLoggerInterface $logs,
        private string $logKey,
        private string $progressKey,
        private int $ttl = 3600,
        private int $maxLogs = 50,
    ) {}

    /**
     * @param mixed $level
     */
    public function log($level, $message, array $context = []): void
    {
        $message = trim((string)$message);
        if ($message === '') {
            return;
        }

        $line = sprintf('[%s]%s', date('H:i:s'), $message);
        $this->redis->rPush($this->logKey, $line);
        $this->redis->lTrim($this->logKey, -$this->maxLogs, -1);
        $this->redis->expire($this->logKey, $this->ttl);
        $this->logs->log($level, $line, $context);
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * 写入标准进度对象：current/total/percent/message/updated_at。
     */
    public function progress(int $current, int $total, string $message = ''): void
    {
        $current = max(0, $current);
        $total = max(0, $total);
        $percent = $total > 0 ? min(100, max(0, (int)round(($current / $total) * 100))) : 0;
        $this->redis->setex($this->progressKey, $this->ttl, json_encode([
            'current' => $current,
            'total' => $total,
            'percent' => $percent,
            'message' => trim($message),
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
