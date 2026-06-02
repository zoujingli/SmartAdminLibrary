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

use Hyperf\Collection\Arr;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Stringable\Str;
use Psr\Log\LoggerInterface;

use function Hyperf\Support\env;

/**
 * 数据库查询执行监听器.
 */
#[Listener]
final class DbQueryListener implements ListenerInterface
{
    public const CONTEXT_SUPPRESS_SQL_LOG = '__library.db_query_listener.suppress_sql_log';

    /**
     * SQL日志记录器.
     */
    private LoggerInterface $logger;

    private bool $enabled;

    /**
     * 构造函数.
     */
    public function __construct(
        public readonly LoggerFactory $factory,
        private readonly ConfigInterface $config
    ) {
        $this->logger = $factory->get('sql');
        // 运行期优先读取已加载配置，避免 env() 在缓存模式下失效导致开关误判。
        $handlers = $this->config->get('logger.channels.sql.handlers', []);
        if (is_array($handlers)) {
            $this->enabled = in_array('stdout', $handlers, true);
            return;
        }

        $default = env('APP_ENV') === 'dev';
        $raw = env('APP_DEBUG_XSQL', $default);
        $normalized = is_string($raw) ? trim($raw, " \t\n\r\0\x0B\"'") : $raw;
        $this->enabled = is_bool($normalized)
            ? $normalized
            : (filter_var($normalized, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    /**
     * 获取监听的事件列表.
     */
    public function listen(): array
    {
        return [QueryExecuted::class];
    }

    /**
     * 处理数据库查询执行事件.
     */
    public function process(object $event): void
    {
        // SQL 调试开关关闭或命令需要机器可解析输出时，直接跳过查询日志采集，避免控制台出现 OnDbQuery。
        if (!$this->enabled || Context::get(self::CONTEXT_SUPPRESS_SQL_LOG, false) === true) {
            return;
        }

        /** @var QueryExecuted $event */
        if ($event instanceof QueryExecuted) {
            // 获取原始SQL语句
            $sql = $event->sql;
            // 只有当 bindings 是索引数组时才进行参数替换
            if (!Arr::isAssoc($event->bindings)) {
                foreach ($event->bindings as $value) {
                    $value = is_array($value) ? json_encode($value) : "'{$value}'";
                    $sql = Str::replaceFirst('?', "{$value}", $sql);
                }
            }

            // 过滤系统查询，避免记录无用的系统表查询
            if (
                stripos($sql, 'information_schema.columns') === false
                && stripos($sql, 'SHOW TABLE') === false
                && stripos($sql, 'SHOW INDEX') === false
            ) {
                $this->logger->info(sprintf('OnDbQuery [%sms] %s', $event->time, $sql));
            }
        }
    }
}
