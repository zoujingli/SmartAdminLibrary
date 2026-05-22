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
 * 未认证响应异常.
 */
final class UnauthorizedResponseException extends BaseResponseException
{
    public function __construct(
        mixed $message = '未登录授权',
        mixed $data = null,
        mixed $code = System::UNAUTHORIZED,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $data, $code, System::UNAUTHORIZED, $previous);
    }
}
