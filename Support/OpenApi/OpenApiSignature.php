<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Support\OpenApi;

/**
 * 开放接口认证签名标准。
 *
 * Token 换取阶段只签 appid、nonce、timestamp 三个稳定字段，业务请求统一使用 Bearer token，
 * 避免不同插件自行扩展签名原文后造成第三方接入口径不一致。
 */
final class OpenApiSignature
{
    public static function buildTokenSignMessage(string $appid, string $nonce, int $timestamp): string
    {
        return sprintf('appid=%s&nonce=%s&timestamp=%d', trim($appid), trim($nonce), $timestamp);
    }

    public static function tokenSign(string $appid, string $appkey, string $nonce, int $timestamp): string
    {
        return hash('sha256', self::buildTokenSignMessage($appid, $nonce, $timestamp) . '&appkey=' . trim($appkey));
    }
}
