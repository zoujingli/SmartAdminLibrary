<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Events\Annotation;

/**
 * 日志记录注解
 * 用于标记需要记录日志的方法.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class Logger
{
    public function __construct(
        public string $name = '',        // 操作名称
        public string $code = '',        // 操作代码
        public string $remark = '',      // 备注信息
        public array $excludeFields = [], // 排除的字段
        public bool $recordRequest = true,   // 是否记录请求数据
        public bool $recordChange = true,    // 是否记录模型变更数据
    ) {}
}
