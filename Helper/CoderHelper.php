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
 * 工具类.
 */
final class CoderHelper
{
    /**
     * 生成时间编码
     */
    public static function genTimeCode(int $size = 12, string $prefix = ''): string
    {
        [$time, $size] = [strval(time()), max(10, $size)];
        $code = $prefix . (intval($time[0]) + intval($time[1])) . substr($time, 2) . rand(0, 9);
        while (strlen($code) < $size) {
            $code .= rand(0, 9);
        }
        return $code;
    }

    /**
     * 生成日期编码
     */
    public static function genDateCode(int $size = 14, string $prefix = ''): string
    {
        $code = sprintf('%s%s%02d%s', $prefix, date('ymd'), intval(date('H')) + intval(date('i')), date('s'));
        while (strlen($code) < max(14, $size)) {
            $code .= rand(0, 9);
        }
        return $code;
    }

    /**
     * 生成随机编码
     */
    public static function genRandCode(int $size = 10, int $type = 1, string $prefix = ''): string
    {
        $numbs = '0123456789';
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        $type === 1 && ($chars = $numbs);
        $type === 3 && ($chars = "{$numbs}{$chars}");
        $code = $prefix . $chars[rand(1, strlen($chars) - 1)];
        while (strlen($code) < $size) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $code;
    }

    /**
     * 数据加密.
     */
    public static function encrypt(mixed $data, string $skey): string
    {
        $iv = self::genRandCode(16, 3);
        $value = openssl_encrypt(json_encode([$data]), 'AES-256-CBC', $skey, 0, $iv);
        return self::ensafe64(json_encode(['iv' => $iv, 'value' => $value]));
    }

    /**
     * 数据解密.
     */
    public static function decrypt(string $data, string $skey): mixed
    {
        $attr = json_decode(self::desafe64($data), true);
        if (empty($attr) || empty($attr['value']) || empty($attr['iv'])) {
            throw new \RuntimeException('encrypt data is empty');
        }
        return json_decode(openssl_decrypt($attr['value'], 'AES-256-CBC', $skey, 0, $attr['iv']), true)[0] ?? null;
    }

    /**
     * Base64Url安全编码
     */
    public static function ensafe64(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }

    /**
     * Base64Url安全解码
     */
    public static function desafe64(string $text): string
    {
        return base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }

    /**
     * 生成UUID4格式的唯一标识符.
     */
    public static function uuid(): string
    {
        try {
            $data = random_bytes(16);
        } catch (\Throwable $e) {
            $data = openssl_random_pseudo_bytes(16);
        }

        // 设置版本 (4) 和变体 (10)
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80); // Variant 10

        $hex = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
