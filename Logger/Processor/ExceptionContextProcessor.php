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

use Library\Logger\ExceptionLogFormatter;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * 异常上下文摘要处理器。
 *
 * 对 context/extra 中的 Throwable 或异常数组递归压缩，防止 Monolog 默认规范化输出完整 trace。
 */
final class ExceptionContextProcessor implements ProcessorInterface
{
    /**
     * @param array<string, mixed>|LogRecord $record
     * @return array<string, mixed>|LogRecord
     */
    public function __invoke(array|LogRecord $record): array|LogRecord
    {
        if ($record instanceof LogRecord) {
            return $record->with(
                context: self::normalize($record->context),
                extra: self::normalize($record->extra),
            );
        }

        if (isset($record['context']) && is_array($record['context'])) {
            $record['context'] = self::normalize($record['context']);
        }

        if (isset($record['extra']) && is_array($record['extra'])) {
            $record['extra'] = self::normalize($record['extra']);
        }

        return $record;
    }

    /**
     * 递归处理日志上下文；只有明确的异常结构才会被改写，普通业务数组保持原样。
     *
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function normalize(array $context): array
    {
        foreach ($context as $key => $value) {
            $context[$key] = self::normalizeValue($key, $value);
        }

        return $context;
    }

    private static function normalizeValue(int|string $key, mixed $value): mixed
    {
        if ($value instanceof \Throwable) {
            return ExceptionLogFormatter::format($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (self::isExceptionArray($key, $value)) {
            return ExceptionLogFormatter::normalizeArray($value);
        }

        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = self::normalizeValue($childKey, $childValue);
        }

        return $value;
    }

    /**
     * 已结构化的异常数组仅在 exception 键或带 trace/previous 的异常形态下摘要化。
     *
     * @param array<mixed> $value
     */
    private static function isExceptionArray(int|string $key, array $value): bool
    {
        $hasExceptionShape = isset($value['class'], $value['message'], $value['file'], $value['line']);
        if (!$hasExceptionShape) {
            return false;
        }

        if ($key === 'exception') {
            return true;
        }

        return array_key_exists('trace', $value) || array_key_exists('previous', $value) || array_key_exists('traceAsString', $value);
    }
}
