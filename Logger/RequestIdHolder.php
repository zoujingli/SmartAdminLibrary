<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Logger;

use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Library\Helper\CoderHelper;

/**
 * 请求 ID 持有者
 * 管理每个请求的唯一标识符，支持协程上下文.
 */
final class RequestIdHolder
{
    public const REQUEST_ID = 'log.request.id';

    /**
     * 获取请求 ID
     * 在协程上下文中会复用父协程的 ID.
     */
    public static function getId(): string
    {
        if (Coroutine::inCoroutine()) { // 在协程内
            // 本协程内获取
            $request_id = Context::get(self::REQUEST_ID);
            if (is_null($request_id)) {
                // 没有去父协程 获取
                $request_id = Context::get(self::REQUEST_ID, null, Coroutine::parentId());
                if (!is_null($request_id)) {
                    // 写入本协程，以便本协程或本协程下的子协程获取
                    Context::set(self::REQUEST_ID, $request_id);
                }
            }
            // 都没有，重新生成
            if (is_null($request_id)) {
                $request_id = self::getUniqueId();
            }
        } else {
            $request_id = self::getUniqueId();
        }
        return $request_id;
    }

    /**
     * 生成并设置唯一 ID.
     */
    private static function getUniqueId(): string
    {
        return Context::set(self::REQUEST_ID, CoderHelper::uuid());
    }
}
