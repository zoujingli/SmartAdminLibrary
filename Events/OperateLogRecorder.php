<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Events;

use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Library\Events\Annotation\Logger;
use Library\Events\Event\Logger as LoggerEvent;
use Library\Exception\Handler\ResponseExceptionHandler;
use Library\Helper\RequestHelper;
use Library\Support\ModelChangeLog;
use Library\Support\SensitiveDataFilter;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;

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
        $dispatched = $request->getAttribute(Dispatched::class);
        if (!$dispatched instanceof Dispatched || !isset($dispatched->handler->callback)) {
            return null;
        }

        $callback = $dispatched->handler->callback;
        $class = null;
        $method = null;

        if (is_string($callback)) {
            if (str_contains($callback, '@')) {
                [$class, $method] = explode('@', $callback, 2);
            } elseif (str_contains($callback, '::')) {
                [$class, $method] = explode('::', $callback, 2);
            }
        } elseif (is_array($callback) && count($callback) >= 2) {
            [$ctrl, $method] = $callback;
            $class = is_object($ctrl) ? $ctrl::class : (string)$ctrl;
            $method = (string)$method;
        }

        $class = $class !== null ? trim($class) : '';
        $method = $method !== null ? trim($method) : '';
        if ($class === '' || $method === '' || !class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }

        $attrs = $ref->getAttributes(Logger::class);
        if ($attrs === []) {
            return null;
        }

        $annotation = $attrs[0]->newInstance();
        $short = basename(str_replace('\\', '/', $class));

        return [$annotation, "{$short}::{$method}"];
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
     */
    public static function formatResponseData(mixed $data): string
    {
        if (is_array($data)) {
            $data = SensitiveDataFilter::apply($data, [], 10000);
            $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return self::truncateBody(is_string($content) ? $content : '');
        }

        if ($data instanceof \Stringable || is_scalar($data) || $data === null) {
            return self::truncateBody((string)$data);
        }

        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::truncateBody(is_string($content) ? $content : '');
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
        $user = user()?->toArray() ?? [];
        $username = self::resolveLogUsername($request, $user);

        $logData = [
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
