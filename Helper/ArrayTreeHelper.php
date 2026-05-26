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

/**
 * 将扁平数组按父子关系组装成树形结构。
 */
final class ArrayTreeHelper
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function build(
        array $items,
        int|string $rootParentId = 0,
        string $idField = 'id',
        string $parentField = 'pid',
        string $childrenField = 'children'
    ): array {
        $grouped = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $grouped[self::normalizeKey($item[$parentField] ?? 0)][] = $item;
        }

        return self::buildBranch($grouped, self::normalizeKey($rootParentId), $idField, $childrenField);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $grouped
     * @return array<int, array<string, mixed>>
     */
    private static function buildBranch(
        array $grouped,
        string $parentKey,
        string $idField,
        string $childrenField
    ): array {
        $branch = [];

        foreach ($grouped[$parentKey] ?? [] as $item) {
            $children = self::buildBranch(
                $grouped,
                self::normalizeKey($item[$idField] ?? 0),
                $idField,
                $childrenField
            );

            if ($children !== []) {
                $item[$childrenField] = $children;
            }

            $branch[] = $item;
        }

        return $branch;
    }

    /**
     * 统一树节点索引键，避免 bool / int / string 混用导致命中失败。
     */
    private static function normalizeKey(mixed $value): string
    {
        return (string)(is_bool($value) ? (int)$value : $value);
    }
}
