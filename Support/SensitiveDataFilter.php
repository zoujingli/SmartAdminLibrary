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

/**
 * 统一过滤日志与审计链路中的敏感数据。
 */
final class SensitiveDataFilter
{
    private const SENSITIVE_FIELDS = [
        'password', 'pwd', 'passwd',
        'token', 'access_token', 'refresh_token',
        'secret', 'key', 'api_key',
        'authorization', 'auth',
        'access_secret', 'secret_id', 'secret_key', 'client_secret', 'app_secret', 'private_key',
        'credit_card', 'card_number',
        'ssn', 'social_security',
        'phone', 'mobile', 'telephone',
        'email', 'mail',
    ];

    /**
     * @param array<int|string, mixed> $data
     * @param array<int, string> $excludeFields
     * @return array<int|string, mixed>
     */
    public static function apply(array $data, array $excludeFields = [], int $maxStringLength = 1000): array
    {
        $rules = array_map(strtolower(...), array_merge(self::SENSITIVE_FIELDS, $excludeFields));

        return self::filter($data, $rules, '', $maxStringLength);
    }

    /**
     * @param array<int|string, mixed> $data
     * @param array<int, string> $rules
     * @return array<int|string, mixed>
     */
    private static function filter(array $data, array $rules, string $prefix, int $maxStringLength): array
    {
        foreach ($data as $key => $value) {
            $keyText = strtolower((string)$key);
            $path = $prefix === '' ? $keyText : "{$prefix}.{$keyText}";

            if (self::isSensitive($path, $keyText, $rules)) {
                $data[$key] = '***';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::filter($value, $rules, $path, $maxStringLength);
                continue;
            }

            if (is_string($value) && strlen($value) > $maxStringLength) {
                $data[$key] = mb_substr($value, 0, $maxStringLength) . '...';
            }
        }

        return $data;
    }

    /**
     * @param array<int, string> $rules
     */
    private static function isSensitive(string $path, string $key, array $rules): bool
    {
        return in_array($key, $rules, true) || in_array($path, $rules, true);
    }
}
