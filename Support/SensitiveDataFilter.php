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
        'share_token', 'evaluate_token', 'complete_token',
        'secret', 'key', 'api_key', 'app_key', 'appkey', 'sign', 'signature',
        'authorization', 'auth',
        'access_secret', 'secret_id', 'secret_key', 'client_secret', 'app_secret', 'private_key',
        'credit_card', 'card_number',
        'ssn', 'social_security',
        'phone', 'mobile', 'telephone',
        'wechat',
        'email', 'mail',
        'share_url', 'evaluate_url',
    ];

    private const RAW_SENSITIVE_KEYS = 'password|pwd|passwd|token|access_token|refresh_token|share_token|evaluate_token|complete_token|ticket|secret|key|api_key|app_key|appkey|sign|signature|authorization|auth|access_secret|secret_id|secret_key|client_secret|app_secret|private_key|credit_card|card_number|ssn|social_security|phone|mobile|telephone|wechat|email|mail|share_url|evaluate_url';

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
     * 字符串值本身可能就是 JWT、一次性公共链接或带 token 的 URL，不能只依赖字段名脱敏。
     */
    public static function maskText(string $value): string
    {
        $keys = self::RAW_SENSITIVE_KEYS;
        $value = preg_replace('/("(?:' . $keys . ')"\s*:\s*)(\{[^{}]*\}|\[[^\[\]]*\]|"[^"]*"|[^,}\]\s]+)/i', '$1"***"', $value) ?? $value;
        $value = preg_replace('/("(?:' . $keys . ')"\s*:\s*)\{[^\r\n]*/i', '$1"***"', $value) ?? $value;
        $value = preg_replace('/((?:' . $keys . ')=)[^&\s]*/i', '$1***', $value) ?? $value;
        $value = preg_replace('~([?&](?:' . $keys . ')=)[^&#\s]+~i', '$1***', $value) ?? $value;
        $value = preg_replace('~(/customer/(?:share|evaluate)/)[a-f0-9]{32,128}\b~i', '$1[public-token]', $value) ?? $value;

        return preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', '***', $value) ?? $value;
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

            if (is_string($value)) {
                $value = self::maskText($value);
                $data[$key] = strlen($value) > $maxStringLength ? mb_substr($value, 0, $maxStringLength) . '...' : $value;
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
