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
 * 阻止访问异常类.
 */
final class NotAllowResponseException extends BaseResponseException
{
    public function __construct(
        mixed $message = '无操作权限',
        mixed $data = null,
        mixed $code = System::NOT_ALLOW,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $data, $code, System::NOT_ALLOW, $previous);
    }
}
