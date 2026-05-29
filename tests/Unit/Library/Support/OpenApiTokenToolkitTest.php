<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library\Support;

use Library\Exception\NotAllowResponseException;
use Library\Exception\UnauthorizedResponseException;
use Library\Support\OpenApi\OpenApiSignature;
use Library\Support\OpenApi\OpenApiTokenToolkit;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class OpenApiTokenToolkitTest extends TestCase
{
    public function testTokenSignatureUsesSmartAdminHmacStandard(): void
    {
        $message = OpenApiSignature::buildTokenSignMessage('demo-app', 'nonce-1234567890', 1770000000);

        $this->assertSame('appid=demo-app&nonce=nonce-1234567890&timestamp=1770000000', $message);
        $this->assertSame(
            hash_hmac('sha256', $message, 'secret-key'),
            OpenApiSignature::tokenSign('demo-app', 'secret-key', 'nonce-1234567890', 1770000000)
        );
    }

    public function testSignaturePayloadAcceptsOnlyAppid(): void
    {
        $timestamp = time();
        $sign = str_repeat('a', 64);

        $this->assertSame([
            'appid' => 'demo_app',
            'timestamp' => $timestamp,
            'nonce' => 'nonce-1234567890',
            'sign' => $sign,
        ], OpenApiTokenToolkit::parseSignaturePayload([
            'appid' => ' DEMO_APP ',
            'timestamp' => (string)$timestamp,
            'nonce' => ' nonce-1234567890 ',
            'sign' => strtoupper($sign),
        ]));
    }

    public function testSignaturePayloadRequiresStandardAppidField(): void
    {
        $this->expectException(UnauthorizedResponseException::class);
        $this->expectExceptionMessage('开放接口认证参数缺失');

        OpenApiTokenToolkit::parseSignaturePayload([
            'app_id' => 'legacy_app',
            'timestamp' => (string)time(),
            'nonce' => 'nonce-abcdef123456',
            'sign' => str_repeat('a', 64),
        ]);
    }

    public function testSignaturePayloadRejectsInvalidTimestampNonceAndSign(): void
    {
        $this->expectException(UnauthorizedResponseException::class);
        $this->expectExceptionMessage('timestamp 超出允许范围');

        OpenApiTokenToolkit::parseSignaturePayload([
            'appid' => 'demo',
            'timestamp' => '1',
            'nonce' => 'nonce-1234567890',
            'sign' => str_repeat('a', 64),
        ]);
    }

    public function testAssertSignatureRejectsBadSign(): void
    {
        $this->expectException(UnauthorizedResponseException::class);
        $this->expectExceptionMessage('sign 校验失败');

        OpenApiTokenToolkit::assertSignature('demo', 'secret-key', 'nonce-1234567890', 1770000000, str_repeat('b', 64));
    }

    public function testAccessTokenTtlIsClampedToStandardRange(): void
    {
        $this->assertSame(300, OpenApiTokenToolkit::normalizeAccessTokenTtl(1));
        $this->assertSame(7200, OpenApiTokenToolkit::normalizeAccessTokenTtl(7200));
        $this->assertSame(86400, OpenApiTokenToolkit::normalizeAccessTokenTtl(1000000));
    }

    public function testIpWhitelistNormalizationAndMatching(): void
    {
        $rules = OpenApiTokenToolkit::normalizeIpWhitelist([' 203.0.113.10 ', '203.0.113.0/24', '203.0.113.10', '']);

        $this->assertSame(['203.0.113.10', '203.0.113.0/24'], $rules);
        $this->assertTrue(OpenApiTokenToolkit::isIpAllowed('203.0.113.10', $rules));
        $this->assertTrue(OpenApiTokenToolkit::isIpAllowed('203.0.113.88', $rules));
        $this->assertFalse(OpenApiTokenToolkit::isIpAllowed('198.51.100.1', $rules));
        $this->assertTrue(OpenApiTokenToolkit::isIpAllowed('198.51.100.1', ['*']));
    }

    public function testInvalidIpWhitelistRuleFailsClosed(): void
    {
        $this->expectException(NotAllowResponseException::class);
        $this->expectExceptionMessage('IP 白名单格式错误');

        OpenApiTokenToolkit::normalizeIpWhitelist(['203.0.113.0/33']);
    }

    public function testRateLimitUsesAtomicRedisCounterWithCacheFallback(): void
    {
        $root = dirname(__DIR__, 4);
        $source = '';
        foreach ([
            $root . '/plugin/Library/Support/OpenApi/OpenApiTokenToolkit.php',
            $root . '/Support/OpenApi/OpenApiTokenToolkit.php',
        ] as $sourceFile) {
            if (is_file($sourceFile)) {
                $source = (string)file_get_contents($sourceFile);
                break;
            }
        }

        $this->assertNotSame('', $source, 'OpenApiTokenToolkit source file must be readable.');
        $this->assertStringContainsString('->incr($key)', $source);
        $this->assertStringContainsString('->expire($key, 70)', $source);
        $this->assertStringContainsString('_cache($key)', $source);
    }
}
