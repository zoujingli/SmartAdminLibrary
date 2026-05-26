<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Middleware;

use Hyperf\Contract\TranslatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求语言中间件。
 *
 * 后端语言包使用 Hyperf 标准下划线 locale：zh_CN、zh_TW、en_US。
 * 前端和 HTTP 头使用浏览器标准横线 locale：zh-CN、zh-TW、en-US。
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    private const DEFAULT_LOCALE = 'zh_CN';

    /**
     * 请求可接受的语言别名。
     */
    private const LOCALE_ALIASES = [
        'zh' => 'zh_CN',
        'zh-cn' => 'zh_CN',
        'zh-hans' => 'zh_CN',
        'zh_cn' => 'zh_CN',
        'zh-tw' => 'zh_TW',
        'zh-hant' => 'zh_TW',
        'zh-hk' => 'zh_TW',
        'zh-mo' => 'zh_TW',
        'zh_tw' => 'zh_TW',
        'en' => 'en_US',
        'en-us' => 'en_US',
        'en_us' => 'en_US',
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->translator->setLocale($this->resolveLocale($request));

        return $handler->handle($request);
    }

    /**
     * 优先读取 lang 请求头，其次按 Accept-Language 的 q 值顺序匹配；无法识别时回落简体中文。
     */
    private function resolveLocale(ServerRequestInterface $request): string
    {
        $lang = trim($request->getHeaderLine('lang'));
        if ($lang !== '') {
            return $this->normalizeLocale($lang);
        }

        $acceptLanguage = trim($request->getHeaderLine('Accept-Language'));
        if ($acceptLanguage === '') {
            return self::DEFAULT_LOCALE;
        }

        foreach (explode(',', $acceptLanguage) as $part) {
            $locale = trim((string)preg_replace('/;q=[0-9.]+$/i', '', trim($part)));
            if ($locale === '') {
                continue;
            }

            $normalized = $this->normalizeLocale($locale);
            if ($normalized !== self::DEFAULT_LOCALE || str_starts_with(strtolower($locale), 'zh')) {
                return $normalized;
            }
        }

        return self::DEFAULT_LOCALE;
    }

    private function normalizeLocale(string $locale): string
    {
        $key = strtolower(str_replace('_', '-', trim($locale)));

        return self::LOCALE_ALIASES[$key] ?? self::DEFAULT_LOCALE;
    }
}
