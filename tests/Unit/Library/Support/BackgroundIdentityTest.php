<?php

declare(strict_types=1);

namespace Tests\Unit\Library\Support;

use Hyperf\Context\Context;
use Library\CoreModel;
use Library\Exception\ErrorResponseException;
use Library\Interfaces\UserModelInterface;
use Library\Service\LoginService;
use Library\Support\BackgroundIdentity;
use Library\Support\TenantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/** @internal 独立 Library 包也必须验证后台身份不会放宽原 HTTP / 无身份边界。 */
#[CoversClass(BackgroundIdentity::class)]
final class BackgroundIdentityTest extends TestCase
{
    public function testCapturedIdentityContainsOnlyIdsAndAnInvalidatableFingerprint(): void
    {
        $user = new BackgroundTestAccount(['id' => 8, 'tenant_id' => 3, 'password' => 'private-password-hash']);
        $identity = BackgroundIdentity::capture($user);
        self::assertSame(['user_model', 'user_id', 'tenant_id', 'auth_fingerprint'], array_keys($identity));
        self::assertSame(8, $identity['user_id']);
        self::assertSame(3, $identity['tenant_id']);
        self::assertStringNotContainsString('private-password-hash', json_encode($identity, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('session-version-one', json_encode($identity, JSON_THROW_ON_ERROR));
        self::assertSame($identity, BackgroundIdentity::capture($user));
        $user->sessionVersion = 'session-version-two';
        self::assertNotSame($identity['auth_fingerprint'], BackgroundIdentity::capture($user)['auth_fingerprint']);
        $user->sessionVersion = 'session-version-one';
        $user->password = 'changed-password';
        self::assertNotSame($identity['auth_fingerprint'], BackgroundIdentity::capture($user)['auth_fingerprint']);
    }

    public function testHttpRequestCannotInstallBackgroundIdentity(): void
    {
        $hadRequest = Context::has(ServerRequestInterface::class);
        $request = Context::get(ServerRequestInterface::class);
        Context::set(ServerRequestInterface::class, $this->createStub(ServerRequestInterface::class));
        try {
            self::assertNull(BackgroundIdentity::user(BackgroundTestAccount::class));
            self::assertSame([], BackgroundIdentity::claims());
            $this->expectException(ErrorResponseException::class);
            $this->expectExceptionMessage('独立任务协程');
            BackgroundIdentity::run([], BackgroundTestAccount::class, 'test', static fn () => throw new \LogicException('不得进入'));
        } finally {
            $hadRequest ? Context::set(ServerRequestInterface::class, $request) : Context::destroy(ServerRequestInterface::class);
        }
    }

    public function testUntrustedModelIsRejectedWithoutChangingTenant(): void
    {
        $tenant = TenantContext::get();
        try {
            BackgroundIdentity::run(['user_model' => \stdClass::class], BackgroundTestAccount::class, 'test', static fn () => null);
            self::fail('不能从请求或队列指定未注册用户模型');
        } catch (ErrorResponseException $exception) {
            self::assertStringContainsString('账号类型无效', $exception->getMessage());
        }
        self::assertSame($tenant, TenantContext::get());
        self::assertSame([], BackgroundIdentity::claims());
    }

    public function testOrdinaryCliStillHasNoLoggedInUser(): void
    {
        $service = (new \ReflectionClass(LoginService::class))->newInstanceWithoutConstructor();
        self::assertNull($service->getUser(null, BackgroundTestAccount::class));
        self::assertNull(BackgroundIdentity::user(BackgroundTestAccount::class));
        self::assertSame([], BackgroundIdentity::claims());
    }
}

final class BackgroundTestAccount extends CoreModel implements UserModelInterface
{
    protected array $fillable = ['id', 'tenant_id', 'password'];
    public string $sessionVersion = 'session-version-one';
    public function loginClaims(): array { return ['session_version' => $this->sessionVersion]; }
    public function getId(): int { return (int)$this->id; }
    public function getName(): string { return 'fixture'; }
    public function isSuper(): bool { return false; }
    public function getPermissions(): array { return []; }
    public function hasPermission(string $permission): bool { return false; }
}
