<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Interfaces;

use Lcobucci\JWT\Token;

interface UserLoginInterface
{
    /**
     * 执行登录并返回访问令牌。
     */
    public function login(UserModelInterface $user): Token;

    /**
     * 执行登出。
     */
    public function logout(): bool;

    /**
     * 判断当前是否已登录。
     */
    public function isLogin(): bool;

    /**
     * 根据令牌和明确用户模型获取当前登录用户。
     */
    public function getUser(?string $token = null, ?string $userModel = null): ?UserModelInterface;

    /**
     * 校验用户是否拥有指定节点权限。
     */
    public function checkAuth(string $node, string $userModel): bool;
}
