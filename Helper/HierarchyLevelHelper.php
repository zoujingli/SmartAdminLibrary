<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Helper;

use Hyperf\Database\Model\Model;

/**
 * 树形层级辅助工具。
 * 用于维护带有 `level` 祖先路径字段的模型。
 */
final class HierarchyLevelHelper
{
    /**
     * @param class-string<Model> $modelClass
     */
    public static function resolveLevel(string $modelClass, int $pid): string
    {
        if ($pid <= 0) {
            return '';
        }

        /** @var Model $parent */
        $parent = $modelClass::query()->findOrFail($pid);

        return (string)$parent->full_path;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public static function isDescendantOf(string $modelClass, int $nodeId, int $candidateParentId): bool
    {
        if ($candidateParentId <= 0) {
            return false;
        }

        /** @var Model $parent */
        $parent = $modelClass::query()->findOrFail($candidateParentId);

        return method_exists($parent, 'isChildOf') && $parent->isChildOf($nodeId);
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public static function refreshDescendantLevels(string $modelClass, int $id): void
    {
        /** @var Model $model */
        $model = $modelClass::query()->with('children')->findOrFail($id);
        self::refreshChildrenLevels($model);
    }

    /**
     * 递归刷新当前节点全部子孙的层级路径。
     */
    private static function refreshChildrenLevels(Model $model): void
    {
        foreach ($model->children as $child) {
            $child->level = (string)$model->full_path;
            $child->save();
            self::refreshChildrenLevels($child);
        }
    }
}
