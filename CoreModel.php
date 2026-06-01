<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library;

use Hyperf\Database\Model\Model as HyperfModel;
use Hyperf\Stringable\StrCache;
use Library\Constants\DataField;
use Library\Constants\Status;
use Library\Constants\System;

use function Hyperf\Support\class_basename;

/**
 * 模型抽象基类。
 *
 * 统一提供表名推导、租户数据范围、JSON 字段辅助和公共字段约定。
 */
abstract class CoreModel extends HyperfModel
{
    // 兼容旧常量命名
    public const ENABLE = Status::ENABLED;

    public const DISABLE = Status::DISABLED;

    // 排序字段
    public const FIELD_SORT = 'sort';

    // 状态字段
    public const FIELD_STATUS = 'status';

    // 默认隐藏软删除元数据
    protected array $hidden = ['deleted_at'];

    // 模型变更日志规则；子类按需声明字段中文名、枚举映射和单位。
    protected array $logRules = [];

    /**
     * 当模型未显式声明表名时，根据类名自动推导。
     */
    public function getTable(): string
    {
        return $this->table ?? StrCache::snake(class_basename($this));
    }

    /**
     * 获取模型变更日志规则。
     */
    public function getLogRules(): array
    {
        return $this->logRules;
    }

    /**
     * 启动模型并注册公共查询范围。
     */
    protected function boot(): void
    {
        parent::boot();
        $this->applyTenantScope();
    }

    /**
     * 当模型包含租户字段时自动附加租户隔离范围。
     */
    protected function applyTenantScope(): static
    {
        if (in_array(DataField::TENANT, $this->getFillable(), true)) {
            static::addGlobalScope(DataField::TENANT, function ($query) {
                // 租户全局范围始终按当前 TenantContext 过滤；跨租户读取必须显式移除范围并补目标 tenant_id 条件。
                $tenantId = System::getTenantId();
                $tenantId > 0
                    ? $query->where(sprintf('%s.%s', $this->getTable(), DataField::TENANT), $tenantId)
                    : $query->whereRaw('1 = 0');
            });
        }

        return $this;
    }

    /**
     * 将数组或 JSON 字符串统一写入模型属性。
     */
    protected function _toJson(mixed $value, string $field = ''): string
    {
        $json = (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (is_string($value) ? $value : '')) ?: '';

        return $field === '' ? $json : $this->attributes[$field] = $json;
    }

    /**
     * 将 JSON 字符串或数组统一转为数组。
     *
     * @return array<int|string, mixed>
     */
    protected function _toArray(mixed $value): array
    {
        return (is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : [])) ?: [];
    }
}
