<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Middleware;

use Library\Logger\RequestLogRecorder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求日志中间件。
 *
 * 负责请求进入与正常响应日志；标准异常响应由 ResponseExceptionHandler 补记返回日志。
 */
final class LogsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestLogRecorder $requestLogRecorder,
    ) {}

    /**
     * 处理请求。
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = $this->requestLogRecorder->begin($request);

        $response = $handler->handle($request);

        $this->requestLogRecorder->logResponse($request, $response, $context['start_time'], $context['request_id']);

        return $response;
    }
}
