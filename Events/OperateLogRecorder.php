<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Events;

use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Library\Events\Annotation\Auth;
use Library\Events\Annotation\Logger;
use Library\Events\Event\Logger as LoggerEvent;
use Library\Exception\Handler\ResponseExceptionHandler;
use Library\Helper\RequestHelper;
use Library\Interfaces\UserModelInterface;
use Library\Service\AuthGuardService;
use Library\Support\AuthUserSnapshot;
use Library\Support\ModelChangeLog;
use Library\Support\RouteAnnotationResolver;
use Library\Support\SensitiveDataFilter;
use Library\Support\TenantContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use System\Model\SystemUser;

/**
 * 操作日志采集与派发（供 LoggerAspect、ResponseExceptionHandler 共用）.
 *
 * 说明：控制器多为 final，Hyperf AOP 可能未织入切面；业务异常统一经 {@see ResponseExceptionHandler}
 * 转 JSON，在此处按路由解析 #[Logger] 可保证落库。
 */
final class OperateLogRecorder
{
    private const CONTEXT_OPERATE_LOG_SENT = '__library.operate_log.sent';

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @return null|array{0: Logger, 1: string}
     */
    public static function resolveRouteLogger(ServerRequestInterface $request): ?array
    {
        return RouteAnnotationResolver::resolveLogger($request);
    }

    public static function truncateBody(string $content): string
    {
        if (strlen($content) > 10000) {
            return mb_substr($content, 0, 10000) . '...';
        }

        return $content;
    }

    /**
     * 响应日志统一落库前先脱敏再截断，保证操作日志详情能稳定回看标准响应摘要。
     *
     * @param array<int, string> $excludeFields
     */
    public static function formatResponseData(mixed $data, array $excludeFields = []): string
    {
        if (is_array($data)) {
            $data = SensitiveDataFilter::apply($data, $excludeFields, 10000);
            $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return self::truncateBody(is_string($content) ? $content : '');
        }

        if ($data instanceof \Stringable || is_scalar($data) || $data === null) {
            return self::truncateBody(SensitiveDataFilter::maskText((string)$data));
        }

        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::truncateBody(is_string($content) ? SensitiveDataFilter::maskText($content) : '');
    }

    /**
     * @param null|string $responseData 已脱敏并截断的响应文本
     */
    public function dispatch(
        Logger $annotation,
        ServerRequestInterface $request,
        string $fallbackOperationName,
        string $responseCode,
        ?string $responseData,
    ): void {
        // 同一请求内只记一条（防止异常链路与其它包装重复派发）
        if (Context::get(self::CONTEXT_OPERATE_LOG_SENT, false)) {
            return;
        }

        // final 控制器异常链路也统一读取代理头，保证操作日志 IP 与请求日志一致。
        $ip = RequestHelper::getClientIp($request);
        $userAgent = $request->getHeader('user-agent')[0] ?? '';
        $claims = self::resolveLoginClaimsFromRequest($request);
        $userModel = self::resolveLogUserModel($request, $claims);
        $user = self::resolveAuthenticatedUserRow($userModel, $claims);
        $username = self::resolveLogUsername($request, $user);
        $tenantId = (int)($user['tenant_id'] ?? TenantContext::get());

        $logData = [
            'tenant_id' => $tenantId,
            'name' => $annotation->name !== '' ? $annotation->name : $fallbackOperationName,
            'remark' => $annotation->remark,
            'username' => $username,
            'method' => $request->getMethod(),
            'router' => self::requestPath($request),
            'ip' => $ip,
            'ip_location' => RequestHelper::getIpLocationSimple($ip),
            'os' => RequestHelper::parseOS($userAgent),
            'browser' => RequestHelper::parseBrowser($userAgent),
            'response_code' => $responseCode,
            'response_data' => $responseData,
            'created_by' => $user['id'] ?? 0,
            'updated_by' => $user['id'] ?? 0,
        ];

        if ($annotation->recordRequest) {
            $requestData = self::filterSensitiveData(self::requestData($request), $annotation->excludeFields);
            $logData['request_data'] = $requestData;
        }

        // 变更分段只作为写入链路的临时载荷，最终拆入 system_logs_change，不再混入操作日志行。
        $changeData = $annotation->recordChange ? ModelChangeLog::peek() : null;
        if ($changeData !== null) {
            $logData['change_payload'] = $changeData;
            $logData['remark'] = self::truncateRemark((string)$changeData['summary']);
        }

        $this->eventDispatcher->dispatch(new LoggerEvent($logData));

        // 只有监听器成功处理后才标记已记录并清理变更缓存；否则后续兜底链路可带着原始明细继续重试。
        Context::set(self::CONTEXT_OPERATE_LOG_SENT, true);
        ModelChangeLog::clear();
    }

    /**
     * 只有请求真实携带登录 Token 时才恢复用户；开放接口已按可信 App/站点建立租户上下文，不能被空登录态清掉。
     *
     * @return array<string, mixed>
     */
    private static function resolveAuthenticatedUserRow(string $userModel, array $claims): array
    {
        $cached = AuthGuardService::authenticatedUserRow($userModel);
        if ($cached !== []) {
            return $cached;
        }

        if ($claims === []) {
            return [];
        }

        $user = user($userModel);
        if (!$user instanceof UserModelInterface) {
            return [];
        }

        return AuthUserSnapshot::fromUser($user);
    }

    /**
     * 操作日志必须按路由 Auth 注解或 Token claims 恢复真实登录模型，避免插件账号被默认 SystemUser 解析为空。
     */
    private static function resolveLogUserModel(ServerRequestInterface $request, array $claims): string
    {
        $resolved = RouteAnnotationResolver::resolveAuth($request);
        if ($resolved !== null) {
            /** @var Auth $auth */
            [$auth] = $resolved;
            $userModel = trim($auth->userModel);
            if ($userModel !== '') {
                return self::resolveConcreteUserModel($userModel, $claims);
            }
        }

        $class = (string)($claims['class'] ?? '');
        if ($class !== '' && is_subclass_of($class, UserModelInterface::class)) {
            return $class;
        }

        return SystemUser::class;
    }

    private static function resolveConcreteUserModel(string $userModel, array $claims): string
    {
        if ($userModel !== UserModelInterface::class) {
            return $userModel;
        }

        $class = (string)($claims['class'] ?? '');

        return $class !== '' && is_subclass_of($class, UserModelInterface::class) ? $class : $userModel;
    }

    /**
     * 开放接口 JWT 只有应用 claims，不代表后台或插件登录用户；操作日志恢复用户前必须先确认是登录 Token。
     *
     * @return array<string, mixed>
     */
    private static function resolveLoginClaimsFromRequest(ServerRequestInterface $request): array
    {
        $rawToken = trim($request->getHeaderLine('Authorization'));
        if ($rawToken === '') {
            $rawToken = trim($request->getHeaderLine('token'));
        }
        $rawToken = preg_replace('/^Bearer\s+/i', '', trim($rawToken), 1) ?: '';
        if ($rawToken === '') {
            return [];
        }

        $claims = auth_claims($rawToken);
        $class = (string)($claims['class'] ?? '');

        return (int)($claims['uid'] ?? 0) > 0
            && $class !== ''
            && is_subclass_of($class, UserModelInterface::class)
            ? $claims
            : [];
    }

    /**
     * 登录等未建立会话时 {@see user()} 为空，应从请求体取提交的账号名.
     *
     * @param array<string, mixed> $userRow
     */
    private static function resolveLogUsername(ServerRequestInterface $request, array $userRow): string
    {
        $fromSession = trim((string)($userRow['username'] ?? ''));
        if ($fromSession !== '') {
            return $fromSession;
        }

        $fromRequest = $request instanceof RequestInterface
            ? trim((string)$request->input('username', ''))
            : trim((string)(self::requestData($request)['username'] ?? ''));
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        return 'guest';
    }

    private static function requestPath(ServerRequestInterface $request): string
    {
        return $request instanceof RequestInterface ? $request->getPathInfo() : $request->getUri()->getPath();
    }

    /**
     * 兼容 Hyperf 请求代理与原生 PSR 请求：异常链路通过 RequestHelper 可能拿到底层 PSR 请求。
     *
     * @return array<string, mixed>
     */
    private static function requestData(ServerRequestInterface $request): array
    {
        if ($request instanceof RequestInterface) {
            return $request->all();
        }

        $body = $request->getParsedBody();
        if (!is_array($body) && str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            try {
                $json = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $body = is_array($json) ? $json : [];
            } catch (\Throwable) {
                $body = [];
            }
        }

        return $request->getQueryParams() + (is_array($body) ? $body : []);
    }

    /**
     * @param array<int, string> $excludeFields
     */
    private static function filterSensitiveData(array $data, array $excludeFields = []): array
    {
        return SensitiveDataFilter::apply($data, $excludeFields, 10000);
    }

    private static function truncateRemark(string $remark): string
    {
        return mb_strlen($remark) > 200 ? mb_substr($remark, 0, 197) . '...' : $remark;
    }
}
