<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Auth\Constant;

use Carbon\Carbon;

/**
 * 时间工具类（基于 Carbon）.
 */
final class JwtTimeUtil
{
    /**
     * 获取当前 UTC 时间.
     */
    public static function now(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * 根据时间戳（或 Carbon 实例）获取 UTC 时间.
     *
     * @param mixed $timestamp int|Carbon|string
     */
    public static function timestamp(mixed $timestamp): Carbon
    {
        if ($timestamp instanceof Carbon) {
            return $timestamp->copy()->timezone('UTC');
        }

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestampUTC((int)$timestamp)->timezone('UTC');
        }

        if (is_string($timestamp)) {
            return Carbon::parse($timestamp, 'UTC')->timezone('UTC');
        }

        throw new \InvalidArgumentException('Invalid timestamp type: ' . gettype($timestamp));
    }

    /**
     * 判断时间是否已过期.
     *
     * @param mixed $timestamp 时间戳/Carbon/字符串
     * @param int $leeway 容差（秒）
     */
    public static function isPast(mixed $timestamp, int $leeway = 0): bool
    {
        return self::applyLeeway($timestamp, $leeway, true)->isPast();
    }

    /**
     * 判断时间是否在未来.
     *
     * @param mixed $timestamp 时间戳/Carbon/字符串
     * @param int $leeway 容差（秒）
     */
    public static function isFuture(mixed $timestamp, int $leeway = 0): bool
    {
        return self::applyLeeway($timestamp, $leeway, false)->isFuture();
    }

    /**
     * 应用容差，返回调整后的 Carbon 实例.
     */
    private static function applyLeeway(mixed $timestamp, int $leeway, bool $add): Carbon
    {
        $time = self::timestamp($timestamp);
        return $add ? $time->addSeconds($leeway) : $time->subSeconds($leeway);
    }
}
