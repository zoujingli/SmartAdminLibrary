<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Support;

use Hyperf\Context\Context;
use Library\CoreModel;

final class ModelChangeLog
{
    private const CONTEXT_KEY = '__library.model_change_log.segments';

    /**
     * @param array<string, mixed> $newValues
     * @param array<string, mixed> $oldValues
     */
    public static function recordModel(CoreModel $model, string $event, array $newValues, array $oldValues = []): void
    {
        $segment = ModelChangeFormatter::buildSegment($model, $event, $newValues, $oldValues);
        if ($segment !== null) {
            self::push($segment);
        }
    }

    /**
     * 手动记录非模型字段变更，例如授权节点、用户角色和公告接收人。
     *
     * @param array<int, array<string, mixed>> $changes
     */
    public static function recordFields(CoreModel $model, string $event, array $changes): void
    {
        $segment = ModelChangeFormatter::buildManualSegment($model, $event, $changes);
        if ($segment !== null) {
            self::push($segment);
        }
    }

    /**
     * @return null|array{summary:string,segments:array<int, array<string, mixed>>}
     */
    public static function peek(): ?array
    {
        // 操作日志可能经历 CoreController、AOP、异常处理器多级兜底；预览不清空，避免首次写库失败后丢失变更明细。
        return ModelChangeFormatter::buildPayload(self::segments());
    }

    /**
     * @return null|array{summary:string,segments:array<int, array<string, mixed>>}
     */
    public static function pull(): ?array
    {
        $payload = self::peek();
        self::clear();

        return $payload;
    }

    public static function clear(): void
    {
        Context::set(self::CONTEXT_KEY, []);
    }

    /**
     * @param array<string, mixed> $segment
     */
    private static function push(array $segment): void
    {
        $segments = self::segments();
        $segments[] = $segment;
        Context::set(self::CONTEXT_KEY, $segments);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function segments(): array
    {
        $segments = Context::get(self::CONTEXT_KEY, []);

        return is_array($segments) ? $segments : [];
    }
}
