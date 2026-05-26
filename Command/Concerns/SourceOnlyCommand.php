<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Command\Concerns;

use Library\Constants\System;

/**
 * 源码期命令开关。
 *
 * 文档检查、构建、插件管理和快照生成等命令只允许在源码或 CI 环境运行；Phar/SFX 发布包中返回 false，
 * 让 Symfony Console 在注册阶段直接跳过，避免生产二进制 list 和显式执行暴露开发入口。
 */
trait SourceOnlyCommand
{
    public function isEnabled(): bool
    {
        return !System::isPharMode();
    }
}
