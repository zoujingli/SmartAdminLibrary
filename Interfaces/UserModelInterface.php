<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Interfaces;

use Library\CoreModel;

/**
 * 登录用户模型接口
 * 约定认证用户模型对外暴露的最小能力集合。
 *
 * @mixin CoreModel
 */
interface UserModelInterface
{
    public function getId(): int;

    public function getName(): string;

    public function isSuper(): bool;

    /**
     * @return array<string>
     */
    public function getPermissions(): array;

    public function hasPermission(string $permission): bool;

    public function toArray(): array;
}
