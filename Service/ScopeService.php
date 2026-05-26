<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Service;

use Hyperf\Context\Context;
use Hyperf\Database\Model\Builder;
use Library\Constants\DataField;
use Library\Constants\DataScope;
use Library\Constants\Status;
use Library\Interfaces\UserModelInterface;
use System\Model\SystemDept;
use System\Model\SystemUser;

/**
 * 数据权限服务
 * 实现基于角色的4级数据权限控制.
 */
final class ScopeService
{
    /**
     * 获取用户的数据权限范围
     * 超级管理员拥有全部权限，普通用户未配置角色时默认最小权限.
     */
    public function getUserScope(UserModelInterface $user): int
    {
        $cacheKey = $this->getContextKey('scope', $user);
        $cached = Context::get($cacheKey);
        if (is_int($cached)) {
            return $cached;
        }

        // 超级管理员拥有全部数据权限
        if ($user->isSuper()) {
            return $this->rememberContext($cacheKey, DataScope::ALL);
        }

        $userModel = $this->getUserModel($user);
        if (!$userModel) {
            return $this->rememberContext($cacheKey, DataScope::getDefault());
        }

        $scopes = [];
        foreach ($userModel->roles as $role) {
            if (!Status::isEnabled((int)($role->status ?? Status::DISABLED))) {
                continue;
            }

            $scope = (int)($role->scope ?? DataScope::getDefault());
            if (DataScope::isValid($scope)) {
                $scopes[] = $scope;
            }
        }

        return $this->rememberContext($cacheKey, DataScope::strictest($scopes));
    }

    /**
     * 获取用户可访问的用户ID列表.
     * @param UserModelInterface $user 用户对象
     * @return array 用户ID列表
     */
    public function getUserIds(UserModelInterface $user): array
    {
        return match ($scope = $this->getUserScope($user)) {
            DataScope::ALL => [],
            DataScope::SELF => [$user->getId()],
            default => $this->getDeptUserIds($user)
        };
    }

    /**
     * 应用数据权限到查询构建器.
     * @param Builder $query 查询构建器
     * @param UserModelInterface $user 用户对象
     * @param string $userField 用户字段名，默认为 'created_by'
     * @param null|string $deptField 部门字段名，可选
     */
    public function applyScope(Builder $query, UserModelInterface $user, string $userField = DataField::CREATED_BY, ?string $deptField = null): Builder
    {
        $scope = $this->getUserScope($user);
        if ($scope === DataScope::ALL) {
            return $query;
        }

        if ($scope === DataScope::SELF) {
            return $query->where($userField, $user->getId());
        }

        if ($deptField !== null && $deptField !== '') {
            $deptIds = $this->getAccessibleDeptIds($user);
            if ($deptIds !== []) {
                return $query->whereIn($deptField, $deptIds);
            }
        }

        $userIds = $this->getUserIds($user);

        return $userIds !== []
            ? $query->whereIn($userField, $userIds)
            : $query->where($userField, $user->getId());
    }

    /**
     * 检查用户是否有权限访问指定数据.
     * @param UserModelInterface $user 用户对象
     * @param int $dataUserId 数据创建者ID
     * @param null|int $dataDeptId 数据部门ID，可选
     */
    public function canAccessData(UserModelInterface $user, int $dataUserId, ?int $dataDeptId = null): bool
    {
        $scope = $this->getUserScope($user);
        if ($scope === DataScope::ALL) {
            return true;
        }

        if ($scope === DataScope::SELF) {
            return $dataUserId === $user->getId();
        }

        if ($dataDeptId !== null) {
            $deptIds = $this->getAccessibleDeptIds($user);
            if ($deptIds !== []) {
                return in_array($dataDeptId, $deptIds, true);
            }
        }

        return in_array($dataUserId, $this->getUserIds($user), true);
    }

    /**
     * 获取数据范围文本.
     * @param int $scope 数据范围
     */
    public function getScopeText(int $scope): string
    {
        return DataScope::getText($scope);
    }

    /**
     * 获取用户的数据权限信息.
     * @param UserModelInterface $user 用户对象
     */
    public function getUserScopeInfo(UserModelInterface $user): array
    {
        $scope = $this->getUserScope($user);
        return [
            'scope' => $scope,
            'is_super' => $user->isSuper(),
            'user_ids' => $this->getUserIds($user),
            'dept_ids' => $this->getAccessibleDeptIds($user),
            'scope_text' => $this->getScopeText($scope),
        ];
    }

    /**
     * 应用数据权限到查询构建器（使用宏）.
     * @param Builder $query 查询构建器
     * @param UserModelInterface $user 用户对象
     * @param string $userField 用户字段名，默认为 'created_by'
     * @param null|string $deptField 部门字段名，可选
     */
    public function applyScopeMacro(Builder $query, UserModelInterface $user, string $userField = DataField::CREATED_BY, ?string $deptField = null): Builder
    {
        return $this->applyScope($query, $user, $userField, $deptField);
    }

    /**
     * 获取用户可访问的部门ID列表.
     * @param UserModelInterface $user 用户对象
     * @return array 部门ID列表
     */
    public function getAccessibleDeptIds(UserModelInterface $user): array
    {
        $scope = $this->getUserScope($user);

        // 全部数据权限
        if ($scope === DataScope::ALL) {
            return [];
        }

        // 本人数据权限
        if ($scope === DataScope::SELF) {
            return [];
        }

        if (empty($userModel = $this->getUserModel($user))) {
            return [];
        }

        $directDeptIds = $this->getDirectDeptIds($userModel);
        if ($directDeptIds === []) {
            return [];
        }

        $cacheKey = $this->getContextKey('dept_ids', $user) . ':' . $scope . ':' . md5(implode(',', $directDeptIds));
        $cached = Context::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($scope === DataScope::DEPT) {
            return $this->rememberContext($cacheKey, $directDeptIds);
        }

        return $this->rememberContext($cacheKey, $this->getDeptAndChildIds($directDeptIds));
    }

    public function clearUserContext(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        Context::set(sprintf('__library.scope.%s.%d', 'scope', $userId), null);
        Context::set(sprintf('__library.scope.%s.%d', 'dept_ids', $userId), null);
    }

    /**
     * 获取部门相关用户ID列表.
     * @param UserModelInterface $user 用户对象
     * @return array 用户ID列表
     */
    private function getDeptUserIds(UserModelInterface $user): array
    {
        if (empty($userModel = $this->getUserModel($user))) {
            return [$user->getId()];
        }

        $deptIds = $this->getAccessibleDeptIds($user);
        if ($deptIds === []) {
            return [$user->getId()];
        }

        $cacheKey = $this->getContextKey('dept_user_ids', $user) . ':' . md5(implode(',', $deptIds));
        $cached = Context::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $userIds = SystemUser::whereHas('depts', fn ($query) => $query->whereIn('dept_id', $deptIds))
            ->pluck('id')
            ->toArray();

        return $this->rememberContext($cacheKey, array_values(array_unique(array_merge([$user->getId()], array_map('intval', $userIds)))));
    }

    /**
     * 获取用户模型对象
     * @param UserModelInterface $user 用户对象
     * @return null|mixed
     */
    private function getUserModel(UserModelInterface $user): ?UserModelInterface
    {
        if (method_exists($user, 'roles')) {
            return $user;
        }

        try {
            $currentUser = user();
        } catch (\Throwable) {
            return null;
        }

        return $currentUser instanceof UserModelInterface ? $currentUser : null;
    }

    /**
     * @return array<int, int>
     */
    private function getDirectDeptIds(UserModelInterface $userModel): array
    {
        $ids = [];
        foreach ($userModel->depts as $dept) {
            if (!Status::isEnabled((int)($dept->status ?? Status::DISABLED))) {
                continue;
            }

            $deptId = (int)($dept->id ?? 0);
            if ($deptId > 0) {
                $ids[] = $deptId;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, int> $deptIds
     * @return array<int, int>
     */
    private function getDeptAndChildIds(array $deptIds): array
    {
        if ($deptIds === []) {
            return [];
        }

        $query = SystemDept::query()
            ->where('status', Status::ENABLED)
            ->where(function (Builder $builder) use ($deptIds): void {
                $builder->whereIn('id', $deptIds);
                foreach ($deptIds as $deptId) {
                    $deptId = (int)$deptId;
                    $builder->orWhere('level', (string)$deptId)
                        ->orWhere('level', 'like', "{$deptId},%")
                        ->orWhere('level', 'like', "%,{$deptId},%")
                        ->orWhere('level', 'like', "%,{$deptId}");
                }
            });

        $ids = array_map('intval', $query->pluck('id')->toArray());
        sort($ids);

        return array_values(array_unique($ids));
    }

    private function getContextKey(string $type, UserModelInterface $user): string
    {
        return sprintf('__library.scope.%s.%d', $type, $user->getId());
    }

    private function rememberContext(string $key, mixed $value): mixed
    {
        Context::set($key, $value);

        return $value;
    }
}
