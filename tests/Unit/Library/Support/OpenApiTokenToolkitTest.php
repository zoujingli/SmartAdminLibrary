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

use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Lcobucci\JWT\Token\RegisteredClaims;
use Library\Auth\Token;
use Library\Exception\NotAllowResponseException;
use Library\Exception\UnauthorizedResponseException;
use Library\Support\OpenApi\OpenApiSignature;
use Library\Support\OpenApi\OpenApiTokenToolkit;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @internal
 */
#[CoversNothing]
final class OpenApiTokenToolkitTest extends TestCase
{
    private ?ContainerInterface $originContainer = null;

    protected function tearDown(): void
    {
        Context::destroy('__library.jwt.scene');
        if ($this->originContainer instanceof ContainerInterface) {
            ApplicationContext::setContainer($this->originContainer);
            $this->originContainer = null;
        }
    }

    public function testTokenSignatureUsesSmartAdminHmacSha256Standard(): void
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

    public function testIssueTokensReturnsSerializedJwtStrings(): void
    {
        $this->replaceContainerForJwt();

        $tokens = OpenApiTokenToolkit::issueTokens(
            'website_open_access',
            'website_open_refresh',
            [
                'appid' => 'demo_app',
                'site_id' => 1,
                'tenant_id' => 1,
                'token_version' => 1,
            ],
            [
                'appid' => 'demo_app',
                'refresh_nonce' => 'refresh-nonce',
                'token_version' => 1,
            ],
            60
        );

        $this->assertIsString($tokens['access_token']);
        $this->assertIsString($tokens['refresh_token']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $tokens['access_token']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $tokens['refresh_token']);
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(300, $tokens['expires_in']);
        $this->assertSame(OpenApiTokenToolkit::REFRESH_TOKEN_TTL, $tokens['refresh_expires_in']);
    }

    public function testIssuedJwtStringsCanBeParsedBackToClaims(): void
    {
        $this->replaceContainerForJwt();

        $tokens = OpenApiTokenToolkit::issueTokens(
            'website_open_access',
            'website_open_refresh',
            [
                'appid' => 'demo_app',
                'site_id' => 1,
                'tenant_id' => 2,
                'token_type' => 'access',
                'token_version' => 3,
            ],
            [
                'appid' => 'demo_app',
                'site_id' => 1,
                'tenant_id' => 2,
                'token_type' => 'refresh',
                'token_version' => 3,
                'refresh_nonce' => 'refresh-nonce',
            ],
            7200
        );

        $request = $this->createStub(RequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $tokens['access_token']);

        $claims = OpenApiTokenToolkit::bearerClaims($request, 'website_open_access');
        $refreshClaims = OpenApiTokenToolkit::refreshClaims((string)$tokens['refresh_token'], 'website_open_refresh');

        $this->assertSame('access', $claims['token_type'] ?? null);
        $this->assertSame('demo_app', $claims['appid'] ?? null);
        $this->assertSame(2, $claims['tenant_id'] ?? null);
        $this->assertSame(7200, $this->ttlFromClaims($claims));
        $this->assertSame('refresh', $refreshClaims['token_type'] ?? null);
        $this->assertSame('refresh-nonce', $refreshClaims['refresh_nonce'] ?? null);
        $this->assertSame(OpenApiTokenToolkit::REFRESH_TOKEN_TTL, $this->ttlFromClaims($refreshClaims));
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

    private function replaceContainerForJwt(): void
    {
        $this->originContainer = ApplicationContext::getContainer();
        ApplicationContext::setContainer(new OpenApiTokenToolkitTestContainer(
            $this->originContainer,
            $this->createStub(CacheInterface::class),
            $this->makeJwtConfig()
        ));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function ttlFromClaims(array $claims): int
    {
        $issuedAt = $claims[RegisteredClaims::ISSUED_AT] ?? null;
        $expiresAt = $claims[RegisteredClaims::EXPIRATION_TIME] ?? null;

        $this->assertInstanceOf(\DateTimeImmutable::class, $issuedAt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $expiresAt);

        return $expiresAt->getTimestamp() - $issuedAt->getTimestamp();
    }

    private function makeJwtConfig(): ConfigInterface
    {
        return new class implements ConfigInterface {
            /**
             * @var array<string, mixed>
             */
            private array $items = [
                'jwt' => [
                    'alg' => 'HS256',
                    'secret' => 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
                    'supported_algs' => [
                        'HS256' => 'Lcobucci\JWT\Signer\Hmac\Sha256',
                    ],
                    'symmetry_algs' => ['HS256'],
                    'asymmetric_algs' => [],
                    'blacklist_enabled' => false,
                    'blacklist_prefix' => 'jwt_black',
                    'ttl' => 3600,
                    'type' => 'mpop',
                    'scene' => [
                        'default' => [],
                        'website_open_access' => [
                            'ttl' => 3600,
                        ],
                        'website_open_refresh' => [
                            'ttl' => OpenApiTokenToolkit::REFRESH_TOKEN_TTL,
                        ],
                    ],
                ],
            ];

            public function get(string $key, mixed $default = null): mixed
            {
                $current = $this->items;
                foreach (explode('.', $key) as $segment) {
                    if (!is_array($current) || !array_key_exists($segment, $current)) {
                        return $default;
                    }
                    $current = $current[$segment];
                }

                return $current;
            }

            public function has(string $keys): bool
            {
                $sentinel = new \stdClass();

                return $this->get($keys, $sentinel) !== $sentinel;
            }

            public function set(string $key, mixed $value): void
            {
                $segments = explode('.', $key);
                $current = &$this->items;
                foreach ($segments as $segment) {
                    if (!isset($current[$segment]) || !is_array($current[$segment])) {
                        $current[$segment] = [];
                    }
                    $current = &$current[$segment];
                }

                $current = $value;
            }
        };
    }
}

final class OpenApiTokenToolkitTestContainer implements ContainerInterface
{
    public function __construct(
        private readonly ContainerInterface $origin,
        private readonly CacheInterface $cache,
        private readonly ConfigInterface $config,
    ) {}

    public function make(string $id, array $parameters = []): mixed
    {
        if ($id === Token::class) {
            return new Token($this->cache, $this->config);
        }
        if (method_exists($this->origin, 'make')) {
            return $this->origin->make($id, $parameters);
        }

        return $this->get($id);
    }

    public function get(string $id): mixed
    {
        if ($id === ConfigInterface::class) {
            return $this->config;
        }
        if ($id === CacheInterface::class) {
            return $this->cache;
        }
        if ($this->origin->has($id)) {
            return $this->origin->get($id);
        }

        throw new class(sprintf('Service "%s" not found.', $id)) extends \RuntimeException implements NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return in_array($id, [ConfigInterface::class, CacheInterface::class, Token::class], true) || $this->origin->has($id);
    }
}
