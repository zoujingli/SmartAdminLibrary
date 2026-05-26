<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Traits;

/**
 * 层级模型公共特征
 * 适用于使用 `level` 字段存储祖级路径的树形模型。
 */
trait ModelDeptTrait
{
    public function getFullPathAttribute(): string
    {
        $segments = $this->getLevelSegments();
        $segments[] = (string)$this->id;

        return implode(',', array_filter($segments, static fn (string $segment) => $segment !== ''));
    }

    public function getDepthAttribute(): int
    {
        return count($this->getLevelSegments());
    }

    public function isTopLevel(): bool
    {
        return (int)($this->pid ?? 0) === 0;
    }

    public function isChildOf(int $parentId): bool
    {
        return in_array($parentId, $this->getParentIds(), true);
    }

    public function hasChildren(): bool
    {
        return method_exists($this, 'children') && $this->children()->exists();
    }

    /**
     * @return int[]
     */
    public function getParentIds(): array
    {
        return array_map('intval', $this->getLevelSegments());
    }

    /**
     * @return int[]
     */
    public function getAllChildrenIds(bool $includeSelf = true): array
    {
        $ids = $includeSelf && isset($this->id) ? [(int)$this->id] : [];

        if (!method_exists($this, 'children')) {
            return $ids;
        }

        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->getAllChildrenIds());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return array<string>
     */
    private function getLevelSegments(): array
    {
        $level = trim((string)($this->level ?? ''), ',');
        if ($level === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $level), static fn (string $segment) => $segment !== ''));
    }
}
