<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Logger;

use Hyperf\Codec\Json;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Stream\SwooleFileStream;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Logger\LoggerFactory;
use Library\Auth\Token;
use Library\Exception\BaseResponseException;
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
    private const DEFAULT_BODY_MAX_BYTES = 10240;

    private const BODY_MAX_ITEMS = 10;

    private const BODY_MAX_STRING_BYTES = 500;

    private const BODY_OVERFLOW_TEXT = '太多内容了';

    private const BODY_SUPPRESSED_KEY = '...(>10KB)';

    private const BODY_SUPPRESSED_TEXT = '不输出日志';

    private const RAW_SENSITIVE_KEYS = 'password|pwd|passwd|token|access_token|refresh_token|secret|key|api_key|app_key|appkey|sign|signature|authorization|auth|access_secret|secret_id|secret_key|client_secret|app_secret|private_key|credit_card|card_number|ssn|social_security|phone|mobile|telephone|email|mail';

    private const CONTEXT_START_TIME = '__library.request_log.start_time';

    private const CONTEXT_REQUEST_ID = '__library.request_log.request_id';

    private const CONTEXT_REQUEST_LOGGED = '__library.request_log.request_logged';

    private const CONTEXT_RESPONSE_LOGGED = '__library.request_log.response_logged';

    private const CONTEXT_EXCEPTION_LOGGED = '__library.request_log.exception_logged';

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

        $this->logRequest($request);

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
        $realThrowable = self::resolveRealThrowable($throwable);
        if (Context::get(self::CONTEXT_RESPONSE_LOGGED, false)) {
            if ($realThrowable !== null) {
                $this->logException($realThrowable);
            }
            return;
        }
        Context::set(self::CONTEXT_RESPONSE_LOGGED, true);

        $starttime ??= Context::get(self::CONTEXT_START_TIME);
        $starttime = is_float($starttime) ? $starttime : microtime(true);
        $body = $this->getResponseBody($response);
        $throwableBody = self::getThrowableBody($throwable);
        $level = self::resolveResponseLevel($body, $response->getStatusCode(), $realThrowable, $throwableBody);

        $context = [
            'http_status' => $response->getStatusCode(),
            'duration' => round((microtime(true) - $starttime) * 1000, 2) . 'ms',
            'memory_usage' => FormatHelper::formatBytes(memory_get_usage(true)),
            'body' => $body,
        ];

        $this->logger->log($level, 'onResponse', $context);
        if ($realThrowable !== null) {
            $this->logException($realThrowable);
        }
    }

    /**
     * 请求日志链路之外的异常兜底，保持结构化格式，避免恢复旧 _trace 文本。
     */
    public static function fallbackLogException(\Throwable $throwable): void
    {
        if (Context::get(self::CONTEXT_EXCEPTION_LOGGED, false)) {
            return;
        }

        try {
            $logger = ApplicationContext::getContainer()->get(LoggerFactory::class)->get('log');
            $logger->error('exception', ['exception' => self::formatException($throwable, true)]);
            Context::set(self::CONTEXT_EXCEPTION_LOGGED, true);
        } catch (\Throwable) {
            try {
                $payload = json_encode(
                    ['exception' => self::formatException($throwable, true)],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                );
                error_log($payload === false ? '{"exception":{"message":"log encode failed"}}' : $payload);
            } catch (\Throwable) {
                // 最后一层兜底不能继续向外抛，避免异常处理器被日志链路反向打断。
            }
        }
    }

    /**
     * 记录请求进入日志。
     */
    private function logRequest(ServerRequestInterface $request): void
    {
        if (Context::get(self::CONTEXT_REQUEST_LOGGED, false)) {
            return;
        }
        Context::set(self::CONTEXT_REQUEST_LOGGED, true);

        $ip = RequestHelper::getClientIp($request);
        $context = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => self::previewJsonValue(SensitiveDataFilter::apply($request->getQueryParams(), [], self::DEFAULT_BODY_MAX_BYTES)),
            'client_ip' => self::formatClientIp($ip),
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
    private function getRequestBody(ServerRequestInterface $request): null|array|string
    {
        if (self::shouldSkipRequestBody($request)) {
            return null;
        }

        $preview = $this->readBody($request->getBody());

        return $preview === null ? null : $this->decodeBody($preview['body'], $preview['suppressed']);
    }

    /**
     * 获取响应体内容。
     */
    private function getResponseBody(ResponseInterface $response): null|array|string
    {
        $preview = $this->readBody($response->getBody());

        return $preview === null ? null : $this->decodeBody($preview['body'], $preview['suppressed']);
    }

    /**
     * 只读取日志上限外 1 字节，超过 10KB 直接用固定占位，避免日志链路全量加载大 body。
     *
     * @return null|array{body:string,suppressed:bool}
     */
    private function readBody(StreamInterface $stream): ?array
    {
        if ($stream instanceof SwooleFileStream) {
            return null;
        }

        try {
            if (!$stream->isReadable()) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        try {
            $seekable = $stream->isSeekable();
        } catch (\Throwable) {
            return null;
        }

        if (!$seekable) {
            if (!$stream instanceof SwooleStream) {
                return null;
            }

            return $this->readSwooleStreamBody($stream);
        }

        try {
            $pos = $stream->tell();
            $preview = $this->readSeekableBody($stream, self::DEFAULT_BODY_MAX_BYTES + 1);
            $body = $this->makeBodyPreview($preview);
        } catch (\Throwable) {
            return null;
        } finally {
            try {
                isset($pos) && $stream->seek($pos);
            } catch (\Throwable) {
                // 日志读取失败不能影响接口响应，保留原始异常链路即可。
            }
        }

        return $body;
    }

    /**
     * SwooleStream 是项目标准响应流，getContents 不移动内容；超过上限只输出固定占位。
     *
     * @return null|array{body:string,suppressed:bool}
     */
    private function readSwooleStreamBody(SwooleStream $stream): ?array
    {
        try {
            $size = $stream->getSize();
            if (is_int($size) && $size > self::DEFAULT_BODY_MAX_BYTES) {
                return ['body' => '', 'suppressed' => true];
            }

            return $this->makeBodyPreview($stream->getContents());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return string
     */
    private function readSeekableBody(StreamInterface $stream, int $limit): string
    {
        $body = '';
        $stream->rewind();
        while (!$stream->eof() && strlen($body) < $limit) {
            $chunk = $stream->read($limit - strlen($body));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return $body;
    }

    /**
     * 将读取到的响应/请求体规范化为固定大小预览。
     *
     * @return array{body:string,suppressed:bool}
     */
    private function makeBodyPreview(string $body): array
    {
        $suppressed = strlen($body) > self::DEFAULT_BODY_MAX_BYTES;

        return [
            'body' => $suppressed ? '' : $body,
            'suppressed' => $suppressed,
        ];
    }

    /**
     * 请求/响应体统一按长度限制和敏感字段脱敏后入日志。
     *
     * @return null|array<string, mixed>|string
     */
    private function decodeBody(string $body, bool $suppressed): null|array|string
    {
        if ($suppressed) {
            return [self::BODY_SUPPRESSED_KEY => self::BODY_SUPPRESSED_TEXT];
        }

        if ($body === '') {
            return null;
        }

        try {
            $decoded = Json::decode($body, true);
            $payload = is_array($decoded) ? $decoded : ['value' => $decoded];
            $payload = SensitiveDataFilter::apply($payload, [], self::DEFAULT_BODY_MAX_BYTES);

            return self::previewJsonValue($payload);
        } catch (\Throwable) {
            return self::maskRawText($body);
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
     * 上传、文件和音视频等二进制请求体不进入全局请求日志，避免泄露内容或放大内存。
     */
    private static function shouldSkipRequestBody(ServerRequestInterface $request): bool
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0] ?? ''));
        if ($contentType === '') {
            return false;
        }

        if (in_array($contentType, ['multipart/form-data', 'application/octet-stream', 'application/zip'], true)) {
            return true;
        }

        return str_starts_with($contentType, 'image/')
            || str_starts_with($contentType, 'audio/')
            || str_starts_with($contentType, 'video/');
    }

    /**
     * JSON 预览保持原结构，只在对象/数组/字符串值上做边界裁剪。
     */
    private static function previewJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_is_list($value) ? self::previewJsonList($value) : self::previewJsonObject($value);
        }

        if (is_string($value)) {
            return self::truncateString($value, self::BODY_MAX_STRING_BYTES);
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    private static function previewJsonList(array $items): array
    {
        $total = count($items);
        $preview = [];
        foreach (array_slice($items, 0, self::BODY_MAX_ITEMS) as $item) {
            $preview[] = self::previewJsonValue($item);
        }

        if ($total > self::BODY_MAX_ITEMS) {
            $preview[] = [self::overflowKey($total - self::BODY_MAX_ITEMS) => self::BODY_OVERFLOW_TEXT];
        }

        return $preview;
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    private static function previewJsonObject(array $items): array
    {
        $total = count($items);
        $preview = [];
        $index = 0;
        foreach ($items as $key => $value) {
            if ($index++ >= self::BODY_MAX_ITEMS) {
                break;
            }
            $preview[(string)$key] = self::previewJsonValue($value);
        }

        if ($total > self::BODY_MAX_ITEMS) {
            $preview[self::overflowKey($total - self::BODY_MAX_ITEMS)] = self::BODY_OVERFLOW_TEXT;
        }

        return $preview;
    }

    private static function overflowKey(int $count): string
    {
        return sprintf('...(%d)', $count);
    }

    /**
     * 解析请求里的轻量身份信息。
     * 这里只解析 JWT 声明，不在日志中间件里触发数据库鉴权查询。
     */
    private function resolveRequestUserSummary(ServerRequestInterface $request): ?array
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if ($authorization === '') {
            $authorization = trim($request->getHeaderLine('token'));
        }

        $rawToken = preg_replace('/^Bearer\s+/i', '', $authorization);
        if (!is_string($rawToken) || trim($rawToken) === '') {
            return null;
        }

        try {
            $claims = $this->token->getParserData($rawToken);

            return [
                'id' => (int)($claims['uid'] ?? 0),
                'user_model' => (string)($claims['class'] ?? ''),
                'authenticated' => true,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function formatClientIp(string $ip): string
    {
        return $ip . ' - ' . RequestHelper::getIpLocationSimple($ip);
    }

    /**
     * 统一响应异常是业务控制流，只有 previous 才代表真实底层异常。
     */
    private static function resolveRealThrowable(?\Throwable $throwable): ?\Throwable
    {
        if ($throwable === null) {
            return null;
        }

        if ($throwable instanceof BaseResponseException) {
            return $throwable->getPrevious();
        }

        return $throwable;
    }

    /**
     * 标准响应流不可读时，以响应异常自身的标准结构兜底。
     *
     * @return null|array<string, mixed>
     */
    private static function getThrowableBody(?\Throwable $throwable): ?array
    {
        return $throwable instanceof BaseResponseException ? $throwable->toArray() : null;
    }

    /**
     * 请求日志按业务码优先分级；无业务码时才按 HTTP 状态兜底。
     *
     * @param null|array<string, mixed>|string $body
     */
    private static function resolveResponseLevel(
        null|array|string $body,
        int $httpStatus,
        ?\Throwable $realThrowable = null,
        ?array $throwableBody = null,
    ): string {
        if ($realThrowable !== null) {
            return 'error';
        }

        $levelBody = self::hasBusinessCode($body) ? $body : $throwableBody;
        if (is_array($levelBody) && is_numeric($levelBody['code'] ?? null)) {
            return (int)$levelBody['code'] === 200 ? 'info' : 'error';
        }

        return $httpStatus === 200 ? 'info' : 'error';
    }

    /**
     * @param null|array<string, mixed>|string $body
     */
    private static function hasBusinessCode(null|array|string $body): bool
    {
        return is_array($body) && is_numeric($body['code'] ?? null);
    }

    private function logException(\Throwable $throwable): void
    {
        if (Context::get(self::CONTEXT_EXCEPTION_LOGGED, false)) {
            return;
        }

        $this->logger->error('exception', ['exception' => self::formatException($throwable, true)]);
        Context::set(self::CONTEXT_EXCEPTION_LOGGED, true);
    }

    /**
     * 结构化异常详情单独输出，onResponse 只保留响应摘要。
     *
     * @return array{class:string,code:int,message:string,file:string,line:int,trace?:array<int, string>}
     */
    private static function formatException(\Throwable $throwable, bool $withTrace = false): array
    {
        $data = [
            'class' => $throwable::class,
            'code' => $throwable->getCode(),
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ];

        if ($withTrace) {
            $data['trace'] = explode("\n", $throwable->getTraceAsString());
        }

        return $data;
    }
}
