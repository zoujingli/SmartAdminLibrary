<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Events\Processor;

use Hyperf\Database\Model\Builder;
use Library\Constants\DataField;
use Library\Constants\DataScope;
use Library\Constants\System;
use Library\Interfaces\UserModelInterface;
use Library\Service\ScopeService;
use System\Model\SystemUser as SystemUserModel;

/**
 * 数据权限处理器
 * 统一处理所有数据权限相关逻辑，消除重复代码
 */
final class ScopeProcessor
{
    private static ?ScopeService $scopeService = null;

    /**
     * 获取用户数据权限范围.
     */
    public static function getUserScope(?UserModelInterface $user = null): int
    {
        if ($user === null && is_super_login()) {
            return DataScope::ALL;
        }

        if (empty($user = $user ?? user())) {
            return DataScope::SELF;
        }

        if ((int)$user->getId() === System::getSuperId()) {
            return DataScope::ALL;
        }

        return self::getScopeService()->getUserScope($user);
    }

    /**
     * 获取用户数据权限信息.
     */
    public static function getUserScopeInfo(?UserModelInterface $user = null): array
    {
        if ($user === null && is_super_login()) {
            return ['scope' => DataScope::ALL, 'user_ids' => [], 'dept_ids' => []];
        }

        if (empty($user = $user ?? user())) {
            return ['scope' => DataScope::SELF, 'user_ids' => [], 'dept_ids' => []];
        }

        if ((int)$user->getId() === System::getSuperId()) {
            return ['scope' => DataScope::ALL, 'user_ids' => [], 'dept_ids' => []];
        }

        return self::getScopeService()->getUserScopeInfo($user);
    }

    /**
     * 应用数据权限到查询构建器
     * 统一的数据权限应用逻辑.
     */
    public static function applyScope(
        Builder $query,
        ?UserModelInterface $user = null,
        string $userField = DataField::CREATED_BY,
        ?string $deptField = null
    ): Builder {
        if ($user === null && is_super_login()) {
            return $query;
        }

        $user = $user ?? user();
        if (!$user) {
            return self::denyAll($query);
        }

        $scope = self::getUserScope($user);

        // 全部数据权限，不添加任何条件
        if ($scope === DataScope::ALL) {
            return $query;
        }

        // 本人数据权限
        if ($scope === DataScope::SELF) {
            return $query->where($userField, $user->getId());
        }

        // 部门相关权限
        if ($deptField !== null && $deptField !== '') {
            $deptIds = self::getDeptIds($user);
            if ($deptIds !== []) {
                return $query->whereIn($deptField, $deptIds);
            }
        }

        $userIds = self::getUserIds($user);
        if (!empty($userIds)) {
            return $query->whereIn($userField, $userIds);
        }

        // 默认只能访问自己的数据
        return $query->where($userField, $user->getId());
    }

    /**
     * 检查用户是否有权限访问指定数据
     * 统一的数据访问权限检查逻辑.
     */
    public static function canAccessData(
        int $dataUserId,
        ?int $dataDeptId = null,
        ?UserModelInterface $user = null
    ): bool {
        if ($user === null && is_super_login()) {
            return true;
        }

        $user = $user ?? user();
        if (!$user) {
            return false;
        }

        $scope = self::getUserScope($user);

        // 全部数据权限
        if ($scope === DataScope::ALL) {
            return true;
        }

        // 本人数据权限
        if ($scope === DataScope::SELF) {
            return $dataUserId === $user->getId();
        }

        if ($dataDeptId !== null) {
            $deptIds = self::getDeptIds($user);
            if ($deptIds !== []) {
                return in_array($dataDeptId, $deptIds, true);
            }
        }

        // 部门相关权限
        return in_array($dataUserId, self::getUserIds($user), true);
    }

    /**
     * 获取用户可访问的用户ID列表.
     */
    public static function getUserIds(?UserModelInterface $user = null): array
    {
        if ($user === null && is_super_login()) {
            return [];
        }

        if (empty($user = $user ?? user())) {
            return [];
        }
        return self::getScopeService()->getUserIds($user);
    }

    /**
     * 获取用户可访问的部门ID列表.
     */
    public static function getDeptIds(?UserModelInterface $user = null): array
    {
        if ($user === null && is_super_login()) {
            return [];
        }

        if (empty($user = $user ?? user())) {
            return [];
        }

        return self::getScopeService()->getAccessibleDeptIds($user);
    }

    /**
     * 应用数据权限宏到查询构建器
     * 用于 Model 中的 userDataScope 宏.
     */
    public static function applyUserScopeMacro(Builder $query, ?int $userId = null): Builder
    {
        $user = $userId === null ? user() : self::resolveUserById($userId);
        if (!$user) {
            return self::denyAll($query);
        }

        return self::applyScope($query, $user, DataField::CREATED_BY, null);
    }

    public static function clearUserContext(int $userId): void
    {
        self::getScopeService()->clearUserContext($userId);
    }

    /**
     * 获取数据权限服务实例.
     */
    private static function getScopeService(): ScopeService
    {
        if (self::$scopeService === null) {
            self::$scopeService = make(ScopeService::class);
        }
        return self::$scopeService;
    }

    private static function denyAll(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }

    private static function resolveUserById(int $userId): ?UserModelInterface
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $currentUser = user();
        } catch (\Throwable) {
            $currentUser = null;
        }
        if ($currentUser && (int)$currentUser->getId() === $userId) {
            return $currentUser;
        }

        try {
            $user = SystemUserModel::query()->find($userId);
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof UserModelInterface ? $user : null;
    }
}
