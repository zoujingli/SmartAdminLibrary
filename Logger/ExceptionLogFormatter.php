<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Logger;

/**
 * 异常日志摘要格式化器。
 *
 * 日志默认只保留定位异常所需的稳定字段，避免 trace、previous、args 等长内容刷屏或进入文件日志。
 */
final class ExceptionLogFormatter
{
    private const MESSAGE_MAX_BYTES = 1000;

    private const FILE_MAX_BYTES = 500;

    /**
     * 将 Throwable 转成短摘要，保留 class/code/message/file/line 五个定位字段。
     *
     * @return array{class:string,code:int|string,message:string,file:string,line:int}
     */
    public static function format(\Throwable $throwable): array
    {
        return [
            'class' => $throwable::class,
            'code' => $throwable->getCode(),
            'message' => self::truncate((string)$throwable->getMessage(), self::MESSAGE_MAX_BYTES),
            'file' => self::truncate((string)$throwable->getFile(), self::FILE_MAX_BYTES),
            'line' => $throwable->getLine(),
        ];
    }

    /**
     * 兼容已经数组化的异常上下文，删除堆栈链路并补齐固定字段顺序。
     *
     * @param array<string, mixed> $exception
     * @return array{class:string,code:mixed,message:string,file:string,line:int}
     */
    public static function normalizeArray(array $exception): array
    {
        return [
            'class' => is_string($exception['class'] ?? null) ? $exception['class'] : '',
            'code' => $exception['code'] ?? 0,
            'message' => self::truncate(is_scalar($exception['message'] ?? null) ? (string)$exception['message'] : '', self::MESSAGE_MAX_BYTES),
            'file' => self::truncate(is_string($exception['file'] ?? null) ? $exception['file'] : '', self::FILE_MAX_BYTES),
            'line' => is_numeric($exception['line'] ?? null) ? (int)$exception['line'] : 0,
        ];
    }

    /**
     * 日志字段按字节截断，避免异常消息或路径异常放大日志体积。
     */
    public static function truncate(string $value, int $maxBytes): string
    {
        return strlen($value) > $maxBytes ? mb_strcut($value, 0, $maxBytes) . '...' : $value;
    }

    /**
     * 转成 _trace 等纯文本出口使用的单行摘要。
     */
    public static function toLine(\Throwable $throwable): string
    {
        $exception = self::format($throwable);

        return sprintf(
            '%s(%s): %s at %s:%d',
            $exception['class'],
            (string)$exception['code'],
            $exception['message'],
            $exception['file'],
            $exception['line'],
        );
    }
}
