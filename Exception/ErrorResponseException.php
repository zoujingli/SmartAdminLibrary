<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Exception;

use Library\Constants\System;

/**
 * 操作失败响应异常.
 */
final class ErrorResponseException extends BaseResponseException
{
    public function __construct(
        mixed $message = '操作失败',
        mixed $data = null,
        mixed $code = System::ERROR,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $data, $code, System::ERROR, $previous);
    }
}
