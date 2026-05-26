<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Events\Processor;

use Hyperf\Di\Annotation\AnnotationCollector;
use Library\Events\Annotation\Auth;

/**
 * 权限注解处理器.
 */
final class AuthProcessor
{
    /**
     * 缓存权限列表.
     */
    private static ?array $authListCache = null;

    /**
     * 缓存树形权限.
     */
    private static ?array $authTreeCache = null;

    /**
     * 获取权限列表.
     * @throws \ReflectionException
     */
    public static function getAuthList(bool $force = false): array
    {
        if (!$force && self::$authListCache !== null) {
            return self::$authListCache;
        }

        $classAuthMethods = [];

        // 类级别注解
        /** @var Auth $auth */
        foreach (AnnotationCollector::getClassesByAnnotation(Auth::class) as $class => $auth) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (preg_match('#^[a-z]#i', $method->getName())) {
                    $node = "{$class}@{$method->getName()}";
                    $classAuthMethods[$node] = [
                        'class' => $class,
                        'method' => $method->getName(),
                        'access' => (clone $auth)->with($node)->toArray(),
                    ];
                }
            }
        }

        // 方法级别注解（覆盖类级别）
        foreach (AnnotationCollector::getMethodsByAnnotation(Auth::class) as $method) {
            $auth = $method['annotation'];
            $node = "{$method['class']}@{$method['method']}";
            $classAuthMethods[$node] = [
                'class' => $method['class'],
                'method' => $method['method'],
                'access' => $auth->with($node)->toArray(),
            ];
        }

        return self::$authListCache = array_values($classAuthMethods);
    }

    /**
     * 获取树形权限数据.
     * @throws \ReflectionException
     */
    public static function getAuthTree(bool $force = false): array
    {
        if (!$force && self::$authTreeCache !== null) {
            return self::$authTreeCache;
        }

        $tree = [];
        foreach (self::getAuthList($force) as $item) {
            $parent = '';
            $current = &$tree;
            $nodeStr = $item['access']['node'] ?? '';

            // 节点统一使用 . 分割（如 system.user.index）
            foreach (explode('.', (string)$nodeStr) as $part) {
                if ($part === '') {
                    continue;
                }
                $node = $parent ? $parent . '.' . $part : $part;
                $found = false;

                foreach ($current as &$sub) {
                    if ($sub['node'] === $node) {
                        $current = &$sub['subs'];
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $current[] = [
                        'node' => $node,
                        'name' => $item['access']['name'] ?? '',
                        'type' => $item['access']['type'] ?? Auth::CHECK,
                        'subs' => [],
                    ];
                    $current = &$current[count($current) - 1]['subs'];
                }

                $parent = $node;
            }
        }

        return self::$authTreeCache = $tree;
    }

    /**
     * 清理缓存.
     */
    public static function clearCache(): void
    {
        self::$authListCache = null;
        self::$authTreeCache = null;
    }
}
