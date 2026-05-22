<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Exception;

use Library\Constants\System;

/**
 * 页面丢失响应异常.
 */
final class NotFoundResponseException extends BaseResponseException
{
    public function __construct(
        mixed $message = '操作不存在',
        mixed $data = null,
        mixed $code = System::NOT_FOUND,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $data, $code, System::NOT_FOUND, $previous);
    }
}
