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

/**
 * 格式化工具类.
 */
final class FormatHelper
{
    /**
     * 格式化字节数.
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);

        if ($bytes === 0) {
            return '0 B';
        }

        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);

        $value = $bytes / (1024 ** $pow);

        return match (true) {
            $value >= 100 => round($value) . ' ' . $units[$pow],
            $value >= 10 => round($value, 1) . ' ' . $units[$pow],
            default => round($value, 2) . ' ' . $units[$pow],
        };
    }
}
