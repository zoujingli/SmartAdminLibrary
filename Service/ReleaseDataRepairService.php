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

use Library\Interfaces\ReleaseDataRepairInterface;

use function Hyperf\Config\config;
use function Hyperf\Support\make;

/**
 * 按稳定代码执行插件发布数据修复。
 *
 * Library 只读取 Provider 合并后的类名清单，不引用任何业务插件，保证公开导出与私有插件解耦。
 */
final class ReleaseDataRepairService
{
    /**
     * @param null|array<string,class-string> $registered 测试或独立运行时可显式传入；生产默认读取 Provider 合并配置。
     */
    public function __construct(private ?array $registered = null) {}

    /**
     * @return array{required:bool,ready:bool,items:list<array<string,mixed>>,blocking:list<array<string,mixed>>}
     */
    public function preview(): array
    {
        $required = false;
        $items = [];
        $blocking = [];
        foreach ($this->repairers() as $code => $repairer) {
            $report = $repairer->preview();
            $itemBlocking = array_values((array)($report['blocking'] ?? []));
            $item = [
                'code' => $code,
                'required' => (bool)($report['required'] ?? false),
                'ready' => $itemBlocking === [],
                'summary' => (array)($report['summary'] ?? []),
                'items' => array_values((array)($report['items'] ?? [])),
                'blocking' => $itemBlocking,
            ];
            $required = $required || $item['required'];
            foreach ($itemBlocking as $problem) {
                $blocking[] = ['code' => $code, ...((array)$problem)];
            }
            $items[] = $item;
        }

        return [
            'required' => $required,
            'ready' => $blocking === [],
            'items' => $items,
            'blocking' => $blocking,
        ];
    }

    /**
     * @return array{items:list<array{code:string,report:array<string,mixed>}>}
     */
    public function repair(): array
    {
        $items = [];
        foreach ($this->repairers() as $code => $repairer) {
            $items[] = ['code' => $code, 'report' => $repairer->repair()];
        }

        return ['items' => $items];
    }

    /**
     * @return array<string,ReleaseDataRepairInterface>
     */
    private function repairers(): array
    {
        $registered = $this->registered ?? (array)config('xadmin.release_data_repairs', []);
        ksort($registered, SORT_STRING);
        $result = [];
        foreach ($registered as $code => $class) {
            $code = trim((string)$code);
            $class = trim((string)$class);
            if ($code === '' || preg_match('/^[a-z0-9_.-]+$/D', $code) !== 1 || $class === '') {
                throw new \RuntimeException('发布数据修复注册项格式无效');
            }
            $repairer = make($class);
            if (!$repairer instanceof ReleaseDataRepairInterface) {
                throw new \RuntimeException(sprintf('发布数据修复器 %s 未实现 %s', $class, ReleaseDataRepairInterface::class));
            }
            if ($repairer->code() !== $code) {
                throw new \RuntimeException(sprintf('发布数据修复器代码不一致：注册 %s，实际 %s', $code, $repairer->code()));
            }
            $result[$code] = $repairer;
        }

        return $result;
    }
}
