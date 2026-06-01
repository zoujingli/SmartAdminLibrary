<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library;

use FastRoute\Dispatcher as FastRouteDispatcher;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\Handler;
use Lcobucci\JWT\Token;
use Library\CoreModel;
use Library\Events\Annotation\Auth;
use Library\Events\Aspect\AuthAspect;
use Library\Exception\NotAllowResponseException;
use Library\Exception\UnauthorizedResponseException;
use Library\Interfaces\NodeNameResolverInterface;
use Library\Interfaces\UserLoginInterface;
use Library\Interfaces\UserModelInterface;
use Library\Middleware\AuthRouteGuardMiddleware;
use Library\Service\AuthGuardService;
use Library\Support\RouteAnnotationResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\ClearsLibraryAuthContext;

/**
 * @internal
 */
#[CoversClass(AuthGuardService::class)]
#[CoversClass(AuthRouteGuardMiddleware::class)]
#[CoversClass(AuthAspect::class)]
#[CoversClass(RouteAnnotationResolver::class)]
final class AuthGuardServiceTest extends TestCase
{
    use ClearsLibraryAuthContext;

    protected function tearDown(): void
    {
        $this->clearLibraryAuthContext();
        AnnotationCollector::clear(AuthGuardAspectFixture::class);
    }

    public function testRouteMiddlewareRejectsFinalControllerWhenAopDoesNotRun(): void
    {
        $middleware = new AuthRouteGuardMiddleware(new AuthGuardService(
            new AuthGuardFakeLogin(null),
            new AuthGuardFakeNodeResolver(),
        ));
        $request = $this->routeRequest(AuthGuardRouteFixture::class, 'profile');

        $this->expectException(UnauthorizedResponseException::class);

        $middleware->process($request, new AuthGuardFakeHandler());
    }

    public function testRouteMiddlewareAllowsAuthenticatedUserAndCachesUserRow(): void
    {
        $account = new AuthGuardTestUser(['id' => 9, 'tenant_id' => 7, 'username' => 'project-admin', 'status' => 1]);
        $login = new AuthGuardFakeLogin($account);
        $middleware = new AuthRouteGuardMiddleware(new AuthGuardService(
            $login,
            new AuthGuardFakeNodeResolver(),
        ));
        $request = $this->routeRequest(AuthGuardRouteFixture::class, 'profile');

        $response = $middleware->process($request, new AuthGuardFakeHandler());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([
            'id' => 9,
            'username' => 'project-admin',
            'tenant_id' => 7,
        ], AuthGuardService::authenticatedUserRow(AuthGuardTestUser::class));
        $this->assertSame(1, $login->getUserCalls);
    }

    public function testRouteMiddlewareAndAspectShareGuardDeduplication(): void
    {
        $account = new AuthGuardTestUser(['id' => 12, 'tenant_id' => 10, 'username' => 'dedupe', 'status' => 1]);
        $login = new AuthGuardFakeLogin($account);
        $guard = new AuthGuardService($login, new AuthGuardFakeNodeResolver());
        $request = $this->routeRequest(AuthGuardRouteFixture::class, 'profile');

        (new AuthRouteGuardMiddleware($guard))->process($request, new AuthGuardFakeHandler());
        (new AuthAspect($this->hyperfRequestFromPsr($request), $guard))->process($this->joinPoint(AuthGuardRouteFixture::class, 'profile'));

        $this->assertSame(1, $login->getUserCalls);
    }

    public function testAuthAspectDelegatesToSharedGuardService(): void
    {
        AnnotationCollector::collectMethod(
            AuthGuardAspectFixture::class,
            'index',
            Auth::class,
            new Auth(name: '测试账号列表', type: Auth::CHECK, code: 'test.account.index', userModel: AuthGuardTestUser::class)
        );
        $request = $this->hyperfRequestFromPsr($this->routeRequest(AuthGuardAspectFixture::class, 'index'));
        $joinPoint = $this->joinPoint(AuthGuardAspectFixture::class, 'index');
        $joinPoint->pipe = static fn (): array => ['ok' => true];

        $result = (new AuthAspect($request, new AuthGuardService(
            new AuthGuardFakeLogin(new AuthGuardTestUser(['id' => 10, 'tenant_id' => 8, 'username' => 'pm', 'status' => 1])),
            new AuthGuardFakeNodeResolver(),
        )))->process($joinPoint);

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(8, AuthGuardService::authenticatedUserRow(AuthGuardTestUser::class)['tenant_id'] ?? null);
    }

    public function testAuthAspectUsesJoinPointWhenRouteCannotBeResolved(): void
    {
        AnnotationCollector::collectMethod(
            AuthGuardAspectFixture::class,
            'index',
            Auth::class,
            new Auth(name: '测试账号列表', type: Auth::LOGIN, userModel: AuthGuardTestUser::class)
        );
        $joinPoint = $this->joinPoint(AuthGuardAspectFixture::class, 'index');
        $joinPoint->pipe = static fn (): array => ['ok' => true];

        $result = (new AuthAspect($this->hyperfRequestFromPsr(new ServerRequest('GET', '/missing')), new AuthGuardService(
            new AuthGuardFakeLogin(new AuthGuardTestUser(['id' => 13, 'tenant_id' => 11, 'username' => 'fallback', 'status' => 1])),
            new AuthGuardFakeNodeResolver(),
        )))->process($joinPoint);

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(11, AuthGuardService::authenticatedUserRow(AuthGuardTestUser::class)['tenant_id'] ?? null);
    }

    public function testRouteAnnotationResolverSupportsCallbackFormatsAndMethodOverride(): void
    {
        $cases = [
            AuthGuardRouteFixture::class . '@profile',
            AuthGuardRouteFixture::class . '::profile',
            [AuthGuardRouteFixture::class, 'profile'],
            [new AuthGuardRouteFixture(), 'profile'],
        ];

        foreach ($cases as $callback) {
            $this->clearLibraryAuthContext();

            $route = RouteAnnotationResolver::resolveControllerMethod($this->routeRequestWithCallback($callback));

            $this->assertSame(AuthGuardRouteFixture::class . '@profile', $route['controller'] ?? null);
            $this->assertSame('AuthGuardRouteFixture::profile', $route['fallback'] ?? null);
            $this->assertArrayNotHasKey('node', $route ?? []);
        }

        $this->clearLibraryAuthContext();
        $resolved = RouteAnnotationResolver::resolveAuth($this->routeRequest(AuthGuardClassAuthFixture::class, 'profile'));

        $this->assertSame('方法级资料', $resolved[0]->name ?? null);
        $this->assertSame(AuthGuardTestUser::class, $resolved[0]->userModel ?? null);
        $this->assertSame(AuthGuardClassAuthFixture::class . '@profile', $resolved[1] ?? null);
    }

    public function testRouteAnnotationResolverHandlesMissingRouteAndLoggerOnlyRoute(): void
    {
        $this->assertNull(RouteAnnotationResolver::resolveControllerMethod(new ServerRequest('GET', '/missing')));
        $this->assertNull(RouteAnnotationResolver::resolveAuth(new ServerRequest('GET', '/missing')));

        $request = $this->routeRequest(AuthGuardLoggerOnlyFixture::class, 'submit');

        $this->assertNull(RouteAnnotationResolver::resolveAuth($request));

        $logger = RouteAnnotationResolver::resolveLogger($request);
        $this->assertSame('Logger only', $logger[0]->name ?? null);
        $this->assertSame('AuthGuardLoggerOnlyFixture::submit', $logger[1] ?? null);
    }

    public function testSharedGuardRejectsMissingCheckPermission(): void
    {
        $guard = new AuthGuardService(
            new AuthGuardFakeLogin(new AuthGuardTestUser(['id' => 11, 'tenant_id' => 9, 'username' => 'limited', 'status' => 1]), false),
            new AuthGuardFakeNodeResolver(),
        );

        $this->expectException(NotAllowResponseException::class);

        $guard->guard(
            new Auth(name: '测试账号列表', type: Auth::CHECK, code: 'test.account.index', userModel: AuthGuardTestUser::class),
            AuthGuardAspectFixture::class . '@index'
        );
    }

    private function routeRequest(string $class, string $method): ServerRequestInterface
    {
        return $this->routeRequestWithCallback([$class, $method]);
    }

    private function routeRequestWithCallback(mixed $callback): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/test/account/profile'))->withAttribute(
            Dispatched::class,
            new Dispatched([
                FastRouteDispatcher::FOUND,
                new Handler($callback, '/test/account/profile'),
                [],
            ])
        );
    }

    private function hyperfRequestFromPsr(ServerRequestInterface $request): RequestInterface
    {
        $hyperfRequest = $this->createMock(RequestInterface::class);
        $hyperfRequest->method('getAttribute')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => $request->getAttribute($name, $default)
        );

        return $hyperfRequest;
    }

    private function joinPoint(string $class, string $method): ProceedingJoinPoint
    {
        $joinPoint = new ProceedingJoinPoint(
            static fn () => null,
            $class,
            $method,
            ['order' => [], 'keys' => []]
        );
        $joinPoint->pipe = static fn (): array => ['ok' => true];

        return $joinPoint;
    }

}

final class AuthGuardRouteFixture
{
    #[Auth(name: '测试账号资料', type: Auth::LOGIN, userModel: AuthGuardTestUser::class)]
    public function profile(): void {}
}

#[Auth(name: '类级资料', type: Auth::LOGIN)]
final class AuthGuardClassAuthFixture
{
    #[Auth(name: '方法级资料', type: Auth::LOGIN, userModel: AuthGuardTestUser::class)]
    public function profile(): void {}
}

final class AuthGuardLoggerOnlyFixture
{
    #[\Library\Events\Annotation\Logger(name: 'Logger only')]
    public function submit(): void {}
}

final class AuthGuardAspectFixture
{
    public function index(): void {}
}

final class AuthGuardFakeLogin implements UserLoginInterface
{
    public int $getUserCalls = 0;

    public function __construct(
        private ?UserModelInterface $user,
        private bool $allow = true,
    ) {}

    public function login(UserModelInterface $user): Token
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function logout(): bool
    {
        return true;
    }

    public function isLogin(): bool
    {
        return $this->user !== null;
    }

    public function getUser(?string $token = null, ?string $userModel = null): ?UserModelInterface
    {
        ++$this->getUserCalls;

        return $this->user !== null && ($userModel === null || is_a($this->user::class, $userModel, true))
            ? $this->user
            : null;
    }

    public function checkAuth(string $node, string $userModel): bool
    {
        return $this->allow;
    }
}

final class AuthGuardFakeNodeResolver implements NodeNameResolverInterface
{
    public function findNameByNode(string $node): string
    {
        return $node;
    }
}

final class AuthGuardFakeHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204);
    }
}

/**
 * Library 单包导出不能依赖业务插件用户模型，这里用最小用户模型验证鉴权缓存和权限分支。
 */
final class AuthGuardTestUser extends CoreModel implements UserModelInterface
{
    protected ?string $table = 'auth_guard_test_user';

    protected array $fillable = ['id', 'tenant_id', 'username', 'status'];

    public function getId(): int
    {
        return (int)($this->getAttribute('id') ?? 0);
    }

    public function getName(): string
    {
        return (string)($this->getAttribute('username') ?? '');
    }

    public function isSuper(): bool
    {
        return false;
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function hasPermission(string $permission): bool
    {
        return false;
    }
}
