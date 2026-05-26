<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library;

use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Library\Constants\System;
use Library\Exception\BaseResponseException;
use Library\Exception\ErrorResponseException;

/**
 * 控制器核心基类。
 *
 * 统一注入请求与响应对象，并提供标准成功、失败响应方法。
 */
abstract class CoreController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

    protected function success(string $message = '获取成功', mixed $data = null): never
    {
        $this->respond($message, $data);
    }

    protected function error(string $message = '操作失败', mixed $data = null): never
    {
        $this->respond($message, $data, System::ERROR);
    }

    protected function respond(
        string $message,
        mixed $data = null,
        int $code = System::SUCCESS,
        ?int $status = null,
    ): never {
        // Controller 层只抛出统一响应异常，具体 JSON 结构由 BaseResponseException 集中维护。
        throw new BaseResponseException($message, $this->normalizeResponseData($data), $code, $status);
    }

    protected function respondFound(mixed $data, string $successMessage = '获取成功', string $notFoundMessage = '数据不存在'): never
    {
        if (empty($data)) {
            throw new ErrorResponseException($notFoundMessage);
        }

        $this->success($successMessage, $data);
    }

    /**
     * @return array<int, mixed>
     */
    protected function idsOrFail(string $ids, string $emptyMessage = '目标为空'): array
    {
        if (trim($ids) === '') {
            $this->error($emptyMessage);
        }

        return str2arr($ids);
    }

    protected function deleteByIds(
        string $ids,
        callable $deleter,
        string $successMessage = '删除成功',
        string $emptyMessage = '目标为空',
        string $errorMessage = '删除失败'
    ): never {
        $idArray = $this->idsOrFail($ids, $emptyMessage);

        if ($deleter($idArray)) {
            $this->success($successMessage, $idArray);
        }

        $this->error($errorMessage, $idArray);
    }

    private function normalizeResponseData(mixed $data): mixed
    {
        if (is_object($data) && method_exists($data, 'toArray')) {
            return $data->toArray();
        }

        return $data;
    }
}
