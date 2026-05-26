<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Events\Aspect;

use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Contract\RequestInterface;
use Library\Events\Annotation\Logger;
use Library\Events\OperateLogRecorder;
use Library\Exception\BaseResponseException;
use Library\Exception\Handler\ResponseExceptionHandler;
use Psr\Http\Message\ResponseInterface;

/**
 * 操作日志切面.
 *
 * 这里负责覆盖 AOP 正常织入路径，{@see ResponseExceptionHandler} 负责 final 控制器、
 * 鉴权提前抛错等异常链路兜底；两边统一走 {@see OperateLogRecorder} 的协程去重标记，
 * 保证同一请求只写入一条操作日志。
 */
#[Aspect]
class LoggerAspect extends AbstractAspect
{
    public array $annotations = [
        Logger::class,
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly OperateLogRecorder $recorder,
    ) {}

    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        /** @var null|Logger $annotation */
        $annotation = $proceedingJoinPoint->getAnnotationMetadata()->method[Logger::class] ?? null;
        if (!$annotation instanceof Logger) {
            return $proceedingJoinPoint->process();
        }

        $fallbackName = $this->fallbackOperationName($proceedingJoinPoint);

        try {
            $result = $proceedingJoinPoint->process();
        } catch (BaseResponseException $exception) {
            // CoreController::success()/error() 使用异常承载标准响应，必须先采集再交回异常处理器渲染 JSON。
            $this->dispatchSafely(
                $annotation,
                $fallbackName,
                (string)$exception->getCode(),
                OperateLogRecorder::formatResponseData($exception->toArray()),
            );

            throw $exception;
        }

        // 兼容少量直接返回数组/Response 的接口，避免绕过标准响应异常时丢失请求、响应和变更日志。
        $this->dispatchSafely(
            $annotation,
            $fallbackName,
            $this->resolveResponseCode($result),
            $this->formatReturnValue($result),
        );

        return $result;
    }

    private function dispatchSafely(Logger $annotation, string $fallbackName, string $responseCode, ?string $responseData): void
    {
        try {
            $this->recorder->dispatch($annotation, $this->request, $fallbackName, $responseCode, $responseData);
        } catch (\Throwable) {
            // 日志写入是审计增强链路，异常处理器仍会兜底重试；这里不能覆盖原业务响应或直接返回结果。
        }
    }

    private function fallbackOperationName(ProceedingJoinPoint $proceedingJoinPoint): string
    {
        $short = basename(str_replace('\\', '/', $proceedingJoinPoint->className));

        return "{$short}::{$proceedingJoinPoint->methodName}";
    }

    private function resolveResponseCode(mixed $result): string
    {
        if (is_array($result) && isset($result['code']) && is_scalar($result['code'])) {
            return (string)$result['code'];
        }

        if ($result instanceof ResponseInterface) {
            return (string)$result->getStatusCode();
        }

        return '200';
    }

    private function formatReturnValue(mixed $result): string
    {
        if ($result instanceof ResponseInterface) {
            $body = $result->getBody();
            if (!$body->isReadable() || !$body->isSeekable()) {
                return '';
            }

            $position = null;
            try {
                $position = $body->tell();
                $body->rewind();
                $content = $body->getContents();
                $decoded = json_decode($content, true);

                return OperateLogRecorder::formatResponseData(
                    json_last_error() === JSON_ERROR_NONE ? $decoded : $content
                );
            } catch (\Throwable) {
                return '';
            } finally {
                if ($position !== null) {
                    try {
                        $body->seek($position);
                    } catch (\Throwable) {
                        // 日志读取失败不应改变原响应流位置或影响业务响应。
                    }
                }
            }
        }

        return OperateLogRecorder::formatResponseData($result);
    }
}
