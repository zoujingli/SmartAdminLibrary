<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Exception\Handler;

use Hyperf\Context\ApplicationContext;
use Hyperf\ExceptionHandler\Annotation\ExceptionHandler as RegisterHandler;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Exception\NotFoundHttpException;
use Library\Events\OperateLogRecorder;
use Library\Exception\BaseResponseException;
use Library\Exception\NotFoundResponseException;
use Library\Helper\RequestHelper;
use Library\Logger\RequestLogRecorder;
use Psr\Http\Message\ResponseInterface;

/**
 * 响应异常处理类.
 */
#[RegisterHandler(server: 'http', priority: 9)]
final class ResponseExceptionHandler extends ExceptionHandler
{
    public function handle(\Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->stopPropagation();
        if ($throwable instanceof NotFoundHttpException) {
            $throwable = new NotFoundResponseException('页面不存在');
        }

        if (!$throwable instanceof BaseResponseException) {
            return $response;
        }

        // 500 同时承载业务失败和系统异常；只有包装底层异常时才输出堆栈，避免校验失败刷 ERROR 日志。
        if ($throwable->getPrevious() !== null) {
            _trace($throwable->getPrevious());
        }

        // final 控制器上 AOP 可能未生效，在此按路由反射 #[Logger] 保证操作日志落库
        try {
            $container = ApplicationContext::getContainer();
            $request = RequestHelper::getRequest();
            $resolved = $request !== null ? OperateLogRecorder::resolveRouteLogger($request) : null;
            if ($request !== null && $resolved !== null) {
                [$annotation, $fallbackName] = $resolved;
                $responseData = OperateLogRecorder::formatResponseData($throwable->toArray());
                $container->get(OperateLogRecorder::class)->dispatch(
                    $annotation,
                    $request,
                    $fallbackName,
                    (string)$throwable->getCode(),
                    $responseData,
                );
            }
        } catch (\Throwable) {
            // 不因审计失败影响正常响应
        }

        $response = $throwable->withResponse($response);

        // 标准响应走 THROW 机制时会跳过 LogsMiddleware 的正常返回路径，这里补齐全局 onResponse。
        try {
            $request = RequestHelper::getRequest();
            if ($request !== null) {
                ApplicationContext::getContainer()
                    ->get(RequestLogRecorder::class)
                    ->logResponse($request, $response, null, null, $throwable);
            }
        } catch (\Throwable) {
            // 不因全局请求日志失败影响接口响应
        }

        return $response;
    }

    public function isValid(\Throwable $throwable): bool
    {
        return $throwable instanceof BaseResponseException || $throwable instanceof NotFoundHttpException;
    }
}
