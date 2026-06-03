<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Exception\Handler;

use Hyperf\Context\ApplicationContext;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Library\Constants\System;
use Library\Exception\BaseResponseException;
use Library\Exception\ErrorResponseException;
use Library\Helper\RequestHelper;
use Library\Logger\RequestLogRecorder;
use Psr\Http\Message\ResponseInterface;

/**
 * 全局异常处理器.
 */
final class AppExceptionHandler extends ExceptionHandler
{
    public function handle(\Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->stopPropagation();
        $response = (new ErrorResponseException($throwable->getMessage(), null, System::ERROR, $throwable))->withResponse($response);

        // 非业务异常不会再进入标准响应异常处理器，这里补齐全局请求日志的 onResponse。
        try {
            $request = RequestHelper::getRequest();
            if ($request !== null) {
                ApplicationContext::getContainer()
                    ->get(RequestLogRecorder::class)
                    ->logResponse($request, $response, null, null, $throwable);
            } else {
                RequestLogRecorder::fallbackLogException($throwable);
            }
        } catch (\Throwable) {
            RequestLogRecorder::fallbackLogException($throwable);
            // 不因全局请求日志失败影响兜底异常响应。
        }

        return $response;
    }

    public function isValid(\Throwable $throwable): bool
    {
        // BaseResponseException 交给 ResponseExceptionHandler 处理，避免被兜底处理器“误伤”
        return !$throwable instanceof BaseResponseException;
    }
}
