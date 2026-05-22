<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Logger;

use Hyperf\Codec\Json;
use Hyperf\Context\Context;
use Hyperf\Logger\LoggerFactory;
use Library\Auth\Token;
use Library\Helper\FormatHelper;
use Library\Helper\RequestHelper;
use Library\Support\SensitiveDataFilter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * 全局请求日志记录器。
 *
 * 请求进入、正常返回、THROW 异常返回都通过本类记录，避免标准响应异常绕过中间件后缺少 onResponse。
 */
final class RequestLogRecorder
{
    private const DEFAULT_BODY_MAX_BYTES = 2000;

    private const RAW_SENSITIVE_KEYS = 'password|pwd|passwd|token|access_token|refresh_token|secret|key|api_key|authorization|auth|access_secret|secret_id|secret_key|client_secret|app_secret|private_key|credit_card|card_number|ssn|social_security|phone|mobile|telephone|email|mail';

    private const CONTEXT_START_TIME = '__library.request_log.start_time';

    private const CONTEXT_REQUEST_ID = '__library.request_log.request_id';

    private const CONTEXT_REQUEST_LOGGED = '__library.request_log.request_logged';

    private const CONTEXT_RESPONSE_LOGGED = '__library.request_log.response_logged';

    private LoggerInterface $logger;

    public function __construct(
        LoggerFactory $logger,
        private readonly Token $token,
    ) {
        $this->logger = $logger->get('log');
    }

    /**
     * 初始化当前请求日志上下文，并记录 onRequest。
     *
     * @return array{start_time:float,request_id:string}
     */
    public function begin(ServerRequestInterface $request): array
    {
        $context = [
            'start_time' => microtime(true),
            'request_id' => RequestIdHolder::getId(),
        ];

        Context::set(self::CONTEXT_START_TIME, $context['start_time']);
        Context::set(self::CONTEXT_REQUEST_ID, $context['request_id']);

        $this->logRequest($request, $context['request_id']);

        return $context;
    }

    /**
     * 记录请求返回日志。
     *
     * 异常处理器也会调用本方法，因此用协程上下文防止同一请求重复写 onResponse。
     */
    public function logResponse(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?float $starttime = null,
        ?string $requestId = null,
        ?\Throwable $throwable = null,
    ): void {
        if (Context::get(self::CONTEXT_RESPONSE_LOGGED, false)) {
            return;
        }
        Context::set(self::CONTEXT_RESPONSE_LOGGED, true);

        $starttime ??= Context::get(self::CONTEXT_START_TIME);
        $starttime = is_float($starttime) ? $starttime : microtime(true);
        $requestId ??= Context::get(self::CONTEXT_REQUEST_ID);
        $requestId = is_string($requestId) && $requestId !== '' ? $requestId : RequestIdHolder::getId();
        $ip = RequestHelper::getClientIp($request);

        $context = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => $request->getUri()->getPath(),
            'ip' => $ip,
            'ip_location' => RequestHelper::getIpLocationSimple($ip),
            'status' => $response->getStatusCode(),
            'duration' => round((microtime(true) - $starttime) * 1000, 2) . 'ms',
            'size' => $response->getBody()->getSize()
                ?? (is_numeric($response->getHeaderLine('Content-Length')) ? (int)$response->getHeaderLine('Content-Length') : null),
            'memory_usage' => [
                'current' => FormatHelper::formatBytes(memory_get_usage(true)),
                'peak' => FormatHelper::formatBytes(memory_get_peak_usage(true)),
            ],
        ];

        if ($throwable !== null) {
            $context['exception'] = [
                'class' => $throwable::class,
                'code' => $throwable->getCode(),
                'message' => $throwable->getMessage(),
            ];
        }

        $context['body'] = $this->getResponseBody($response);

        $this->logger->info('onResponse', $context);
    }

    /**
     * 记录请求进入日志。
     */
    private function logRequest(ServerRequestInterface $request, string $requestId): void
    {
        if (Context::get(self::CONTEXT_REQUEST_LOGGED, false)) {
            return;
        }
        Context::set(self::CONTEXT_REQUEST_LOGGED, true);

        $ip = RequestHelper::getClientIp($request);
        $context = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => $request->getUri()->getPath(),
            'query' => SensitiveDataFilter::apply($request->getQueryParams(), [], self::DEFAULT_BODY_MAX_BYTES),
            'ip' => $ip,
            'ip_location' => RequestHelper::getIpLocationSimple($ip),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            // 避免在请求日志阶段通过 user() 触发数据库鉴权查询，导致 Swoole 协程阻塞。
            'user' => $this->resolveRequestUserSummary($request),
        ];

        $context['body'] = $this->getRequestBody($request);

        $this->logger->info('onRequest', $context);
    }

    /**
     * 获取请求体内容。
     */
    private function getRequestBody(ServerRequestInterface $request): ?array
    {
        $preview = $this->readBodyPreview($request->getBody());

        return $preview === null ? null : $this->decodeBody($preview['body'], $preview['truncated']);
    }

    /**
     * 获取响应体内容。
     */
    private function getResponseBody(ResponseInterface $response): ?array
    {
        $preview = $this->readBodyPreview($response->getBody());

        return $preview === null ? null : $this->decodeBody($preview['body'], $preview['truncated']);
    }

    /**
     * 只读取日志上限外 1 字节，避免大上传或大响应为了写日志被全量加载到内存。
     *
     * @return null|array{body:string,truncated:bool}
     */
    private function readBodyPreview(StreamInterface $stream): ?array
    {
        if (!$stream->isReadable() || !$stream->isSeekable()) {
            return null;
        }

        $pos = null;
        $limit = self::DEFAULT_BODY_MAX_BYTES + 1;
        $body = '';

        try {
            $pos = $stream->tell();
            $stream->rewind();
            while (!$stream->eof() && strlen($body) < $limit) {
                $chunk = $stream->read($limit - strlen($body));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
        } catch (\Throwable) {
            return null;
        } finally {
            if ($pos !== null) {
                try {
                    $stream->seek($pos);
                } catch (\Throwable) {
                    // 日志读取失败不能影响接口响应，保留原始异常链路即可。
                }
            }
        }

        $truncated = strlen($body) > self::DEFAULT_BODY_MAX_BYTES;
        if ($truncated) {
            $body = mb_strcut($body, 0, self::DEFAULT_BODY_MAX_BYTES);
        }

        return [
            'body' => $body,
            'truncated' => $truncated,
        ];
    }

    /**
     * 请求/响应体统一按长度限制和敏感字段脱敏后入日志。
     */
    private function decodeBody(string $body, bool $truncated): ?array
    {
        if ($body === '' && !$truncated) {
            return null;
        }

        $maxBytes = self::DEFAULT_BODY_MAX_BYTES;
        if ($truncated) {
            // 截断后的 JSON 很可能不完整，按原始文本兜底脱敏并追加省略号。
            return ['raw' => self::truncateString(self::maskRawText($body), $maxBytes, true)];
        }

        try {
            $decoded = Json::decode($body, true);
            $payload = is_array($decoded) ? $decoded : ['value' => $decoded];
            $payload = SensitiveDataFilter::apply($payload, [], $maxBytes);

            // 结构化 JSON 先脱敏，再按整体长度兜底截断，避免大数组或大对象撑爆日志。
            $encoded = Json::encode($payload);
            return strlen($encoded) > $maxBytes ? ['raw' => self::truncateString($encoded, $maxBytes)] : $payload;
        } catch (\Throwable) {
            return ['raw' => self::truncateString(self::maskRawText($body), $maxBytes)];
        }
    }

    /**
     * 日志文本超过限制时保留前缀并追加省略号。
     */
    private static function truncateString(string $value, int $maxBytes, bool $force = false): string
    {
        return $force || strlen($value) > $maxBytes ? mb_strcut($value, 0, $maxBytes) . '...' : $value;
    }

    /**
     * 原始文本无法结构化解析时，按常见 JSON 和表单格式做敏感字段兜底脱敏。
     */
    private static function maskRawText(string $value): string
    {
        $keys = self::RAW_SENSITIVE_KEYS;
        // JSON 可能因大 body 预览被截断而无法结构化解析；兜底规则需要同时覆盖字符串和简单对象值。
        $value = preg_replace('/("(?:' . $keys . ')"\s*:\s*)(\{[^{}]*\}|\[[^\[\]]*\]|"[^"]*"|[^,}\]\s]+)/i', '$1"***"', $value) ?? $value;
        $value = preg_replace('/("(?:' . $keys . ')"\s*:\s*)\{[^\r\n]*/i', '$1"***"', $value) ?? $value;
        $value = preg_replace('/((?:' . $keys . ')=)[^&\s]*/i', '$1***', $value) ?? $value;

        return preg_replace('/((?:authorization|cookie):\s*)[^\r\n]*/i', '$1***', $value) ?? $value;
    }

    /**
     * 解析请求里的轻量身份信息。
     * 这里只解析 JWT 声明，不在日志中间件里触发数据库鉴权查询。
     */
    private function resolveRequestUserSummary(ServerRequestInterface $request): ?array
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if ($authorization === '') {
            return null;
        }

        $rawToken = preg_replace('/^Bearer\s+/i', '', $authorization);
        if (!is_string($rawToken) || trim($rawToken) === '') {
            return ['authenticated' => true];
        }

        try {
            $claims = $this->token->getParserData($rawToken);

            return [
                'id' => (int)($claims['uid'] ?? 0),
                'user_model' => (string)($claims['class'] ?? ''),
                'authenticated' => true,
            ];
        } catch (\Throwable) {
            return ['authenticated' => true];
        }
    }

}
