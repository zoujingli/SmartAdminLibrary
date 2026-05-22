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
 * 核心操作异常.
 */
final class CoreResponseException extends BaseResponseException
{
    public function __construct(
        mixed $message = '系统异常',
        mixed $data = null,
        mixed $code = System::ERROR,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $data, $code, System::ERROR, $previous);
    }
}
