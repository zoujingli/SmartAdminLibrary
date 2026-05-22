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
 * 标准响应异常类.
 */
final class SuccessResponseException extends BaseResponseException
{
    public function __construct(
        mixed $message = '操作成功',
        mixed $data = null,
        mixed $code = System::SUCCESS,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $data, $code, System::SUCCESS, $previous);
    }
}
