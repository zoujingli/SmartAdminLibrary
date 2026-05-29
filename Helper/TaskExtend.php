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
use Hyperf\Contract\ConfigInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Redis\Redis;
use Hyperf\Redis\RedisFactory;
use Library\Exception\ErrorResponseException;
use Library\Support\TenantContext;

use function Hyperf\Coroutine\defer;

/**
 * 通用后台异步任务扩展。
 *
 * 任务以租户 + 名称加锁，重复投递返回同一 task_id；状态、进度和日志仅保存短 TTL，适合前端短时轮询。
 */
final class TaskExtend
{
    public const STATUS_RUNNING = 'lock';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'fail';

    public const STATUS_UNKNOWN = 'unknown';

    private const TTL = 3600;

    private const LOG_LIMIT = 50;

    private Redis $redis;

    public function __construct(
        private RedisFactory $redisFactory,
        private StdoutLoggerInterface $logs,
        private ConfigInterface $config,
    ) {
        $this->redis = $this->redisFactory->get('default');
    }

    /**
     * @param \Closure(TaskStdout,string):void $callback
     */
    public function dispatch(string $name, \Closure $callback, int $locktime = 300): string
    {
        $name = $this->normalizeName($name);
        $tenantId = TenantContext::get();
        $type = sprintf('tenant:%d:%s', $tenantId, $name);
        $lockKey = $this->key("system:task:lock:{$type}");
        $taskId = str_replace('.', '', uniqid("{$type}:", true));

        try {
            $locked = (bool)$this->redis->set($lockKey, $taskId, ['NX', 'EX' => max(1, $locktime)]);
        } catch (\Throwable $throwable) {
            throw new ErrorResponseException('异步任务服务不可用：' . $throwable->getMessage());
        }
        if (!$locked) {
            $currentTaskId = (string)($this->redis->get($lockKey) ?: '');
            if ($currentTaskId !== '') {
                return $currentTaskId;
            }
            throw new ErrorResponseException('任务已在执行，请稍后再试');
        }

        $this->writeMeta($taskId, $tenantId, $name);
        // 投递成功后立即写入运行态，避免前端首轮轮询早于协程启动时误判 unknown。
        $this->redis->setex($this->statusKey($taskId), self::TTL, self::STATUS_RUNNING);
        Coroutine::create(function () use ($callback, $taskId, $lockKey): void {
            $logger = new TaskStdout(
                $this->redis,
                $this->logs,
                $this->logsKey($taskId),
                $this->progressKey($taskId),
                self::TTL,
                self::LOG_LIMIT
            );
            $statusKey = $this->statusKey($taskId);
            defer(function () use ($lockKey, $taskId): void {
                if ((string)$this->redis->get($lockKey) === $taskId) {
                    $this->redis->del($lockKey);
                }
            });

            try {
                $this->redis->setex($statusKey, self::TTL, self::STATUS_RUNNING);
                $callback($logger, $taskId);
                $this->redis->setex($statusKey, self::TTL, self::STATUS_DONE);
            } catch (\Throwable $throwable) {
                $logger->error('任务异常：' . $throwable->getMessage());
                $this->redis->setex($statusKey, self::TTL, self::STATUS_FAILED);
                _trace($throwable);
            }
        });

        return $taskId;
    }

    /**
     * @return array{stat:string,progress:null|array<string,mixed>,logs:array<int,string>}
     */
    public function status(string $taskId, int $limit = self::LOG_LIMIT): array
    {
        $taskId = trim($taskId);
        $limit = max(1, min(self::LOG_LIMIT, $limit));
        if ($taskId === '' || !$this->canRead($taskId)) {
            return ['stat' => self::STATUS_UNKNOWN, 'progress' => null, 'logs' => []];
        }

        $progress = json_decode((string)($this->redis->get($this->progressKey($taskId)) ?: ''), true);
        $logs = $this->redis->lRange($this->logsKey($taskId), -$limit, -1);

        return [
            'stat' => (string)($this->redis->get($this->statusKey($taskId)) ?: self::STATUS_UNKNOWN),
            'progress' => is_array($progress) ? $progress : null,
            'logs' => array_values(array_map(static fn (mixed $line): string => (string)$line, is_array($logs) ? $logs : [])),
        ];
    }

    private function canRead(string $taskId): bool
    {
        $meta = json_decode((string)($this->redis->get($this->metaKey($taskId)) ?: ''), true);

        return is_array($meta) && (int)($meta['tenant_id'] ?? -1) === TenantContext::get();
    }

    private function writeMeta(string $taskId, int $tenantId, string $name): void
    {
        $this->redis->setex($this->metaKey($taskId), self::TTL, json_encode([
            'tenant_id' => $tenantId,
            'name' => $name,
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ErrorResponseException('任务名称不能为空');
        }

        return preg_replace('/[^A-Za-z0-9:_-]+/', '-', $name) ?: 'task';
    }

    private function key(string $key): string
    {
        $prefix = trim((string)$this->config->get('cache.stores.redis.prefix', 'smartadmin:'), ':');

        return ($prefix === '' ? '' : $prefix . ':') . $key;
    }

    private function metaKey(string $taskId): string
    {
        return $this->key("system:task:meta:{$taskId}");
    }

    private function statusKey(string $taskId): string
    {
        return $this->key("system:task:status:{$taskId}");
    }

    private function logsKey(string $taskId): string
    {
        return $this->key("system:task:logs:{$taskId}");
    }

    private function progressKey(string $taskId): string
    {
        return $this->key("system:task:progress:{$taskId}");
    }
}
