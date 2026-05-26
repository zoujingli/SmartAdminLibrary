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

use function Hyperf\Support\env;

/**
 * 与 {@see config/autoload/cache.php} 使用同一套规则，保证 CACHE_DRIVER 解析一致.
 */
final class CacheDriverResolver
{
    private const STORE_KEYS = ['file', 'redis', 'memory', 'coroutine_memory'];

    public static function readRawFromEnv(): string
    {
        return strtolower((string)(getenv('CACHE_DRIVER') ?: ($_ENV['CACHE_DRIVER'] ?? $_SERVER['CACHE_DRIVER'] ?? env('CACHE_DRIVER', 'file'))));
    }

    public static function normalizeToStoreKey(string $raw): string
    {
        $key = match ($raw) {
            'filesystem', 'file-system', 'file_system' => 'file',
            'coroutine-memory', 'coroutine_memory' => 'coroutine_memory',
            'sqlite' => 'file',
            default => $raw,
        };

        return in_array($key, self::STORE_KEYS, true) ? $key : 'file';
    }

    public static function effectiveStoreKey(): string
    {
        return self::normalizeToStoreKey(self::readRawFromEnv());
    }
}
