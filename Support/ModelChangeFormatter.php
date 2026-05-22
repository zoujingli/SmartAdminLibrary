<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Support;

use DateTimeInterface;
use Library\CoreModel;

use function Hyperf\Support\class_basename;

final class ModelChangeFormatter
{
    private const DEFAULT_IGNORED_FIELDS = [
        'id',
        'tenant_id',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    private const MAX_VALUE_LENGTH = 120;

    private const MAX_STORED_ARRAY_ITEMS = 20;

    private const MAX_STORED_STRING_LENGTH = 500;

    /**
     * 根据模型日志规则构建单条记录的变更分段。
     *
     * @param array<string, mixed> $newValues
     * @param array<string, mixed> $oldValues
     * @return null|array<string, mixed>
     */
    public static function buildSegment(CoreModel $model, string $event, array $newValues, array $oldValues = []): ?array
    {
        $rules = $model->getLogRules();
        $fields = $rules['fields'] ?? [];
        if (!is_array($fields) || $fields === []) {
            return null;
        }

        $ignored = array_fill_keys(array_map(
            'strval',
            array_merge(self::DEFAULT_IGNORED_FIELDS, $model->getHidden(), (array)($rules['ignore'] ?? []))
        ), true);

        $changes = [];
        $texts = [];
        foreach ($newValues as $field => $newValue) {
            $field = (string)$field;
            if (isset($ignored[$field]) || !array_key_exists($field, $fields)) {
                continue;
            }

            $oldValue = $oldValues[$field] ?? null;
            if (self::sameValue($oldValue, $newValue)) {
                continue;
            }

            $rule = self::normalizeFieldRule($field, $fields[$field]);
            $oldText = self::formatValue($oldValue, $rule);
            $newText = self::formatValue($newValue, $rule);
            $label = (string)$rule['name'];

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'old' => self::normalizeStoredValue($oldValue),
                'new' => self::normalizeStoredValue($newValue),
                'old_text' => $oldText,
                'new_text' => $newText,
            ] + (isset($rule['unit']) ? ['unit' => (string)$rule['unit']] : []);

            $texts[] = sprintf('%s(%s)%s改为%s', $label, $field, $oldText, $newText);
        }

        return self::buildSegmentFromFields($model, $event, $changes, $texts, $rules);
    }

    /**
     * 构建手动变更分段，用于角色授权、用户关系同步等非模型字段变更。
     *
     * @param array<int, array<string, mixed>> $changes
     * @return null|array<string, mixed>
     */
    public static function buildManualSegment(CoreModel $model, string $event, array $changes): ?array
    {
        $fields = [];
        $texts = [];

        foreach ($changes as $change) {
            $field = trim((string)($change['field'] ?? ''));
            $label = trim((string)($change['label'] ?? $field));
            if ($field === '' || $label === '') {
                continue;
            }

            $oldValue = $change['old'] ?? null;
            $newValue = $change['new'] ?? null;
            if (self::sameValue($oldValue, $newValue)) {
                continue;
            }

            $rule = [
                'name' => $label,
                'unit' => isset($change['unit']) ? (string)$change['unit'] : '',
                'values' => is_array($change['values'] ?? null) ? $change['values'] : [],
            ];
            $oldText = self::formatValue($oldValue, $rule);
            $newText = self::formatValue($newValue, $rule);

            $fields[] = [
                'field' => $field,
                'label' => $label,
                'old' => self::normalizeStoredValue($oldValue),
                'new' => self::normalizeStoredValue($newValue),
                'old_text' => $oldText,
                'new_text' => $newText,
            ] + ($rule['unit'] !== '' ? ['unit' => $rule['unit']] : []);

            $texts[] = sprintf('%s(%s)%s改为%s', $label, $field, $oldText, $newText);
        }

        return self::buildSegmentFromFields($model, $event, $fields, $texts, $model->getLogRules());
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return null|array{summary:string,segments:array<int, array<string, mixed>>}
     */
    public static function buildPayload(array $segments): ?array
    {
        $segments = array_values(array_filter(
            $segments,
            static fn (mixed $segment): bool => is_array($segment) && !empty($segment['text'])
        ));

        if ($segments === []) {
            return null;
        }

        $summary = implode('；', array_map(static function (array $segment): string {
            $prefix = (string)($segment['model_name'] ?? $segment['model'] ?? '记录');
            $recordLabel = trim((string)($segment['record_label'] ?? ''));
            if ($recordLabel !== '') {
                $prefix .= "({$recordLabel})";
            } elseif (!empty($segment['record_id'])) {
                $prefix .= '#' . (string)$segment['record_id'];
            }

            return sprintf('%s：%s', $prefix, (string)$segment['text']);
        }, $segments));

        return [
            'summary' => $summary,
            'segments' => $segments,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function formatValue(mixed $value, array $rule = []): string
    {
        if ($value === null || $value === '') {
            return '空';
        }

        $mapped = self::mappedValue($value, $rule['values'] ?? []);
        if ($mapped !== null) {
            return sprintf('%s(%s)', $mapped, self::scalarText($value));
        }

        if (is_bool($value)) {
            return $value ? '是(true)' : '否(false)';
        }

        $text = self::rawText($value);
        $unit = trim((string)($rule['unit'] ?? ''));
        if ($unit !== '' && $text !== '空') {
            $text .= $unit;
        }

        return self::truncate($text);
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, string> $texts
     * @return null|array<string, mixed>
     */
    private static function buildSegmentFromFields(CoreModel $model, string $event, array $fields, array $texts, array $rules): ?array
    {
        if ($fields === [] || $texts === []) {
            return null;
        }

        return [
            'model' => class_basename($model::class),
            'table' => $model->getTable(),
            'model_name' => (string)($rules['name'] ?? class_basename($model::class)),
            'record_id' => $model->getKey(),
            'record_label' => self::recordLabel($model, (string)($rules['title'] ?? '')),
            'event' => $event,
            'text' => implode('，', $texts),
            'fields' => $fields,
        ];
    }

    /**
     * @return array{name:string,unit?:string,values?:array<int|string, mixed>}
     */
    private static function normalizeFieldRule(string $field, mixed $rule): array
    {
        if (is_string($rule)) {
            return ['name' => $rule];
        }

        if (is_array($rule)) {
            $name = trim((string)($rule['name'] ?? $field));

            return [
                'name' => $name !== '' ? $name : $field,
                'unit' => isset($rule['unit']) ? (string)$rule['unit'] : '',
                'values' => is_array($rule['values'] ?? null) ? $rule['values'] : [],
            ];
        }

        return ['name' => $field];
    }

    /**
     * @param array<int|string, mixed> $values
     */
    private static function mappedValue(mixed $value, mixed $values): ?string
    {
        if (!is_array($values) || !is_scalar($value)) {
            return null;
        }

        $actual = self::scalarText($value);
        foreach ($values as $key => $text) {
            if ((string)$key === $actual) {
                return (string)$text;
            }
        }

        return null;
    }

    private static function rawText(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            if ($value === []) {
                return '空';
            }

            $isList = array_is_list($value);
            $scalarList = $isList && array_reduce(
                $value,
                static fn (bool $carry, mixed $item): bool => $carry && (is_scalar($item) || $item === null),
                true
            );

            if ($scalarList) {
                return implode('、', array_map(static fn (mixed $item): string => self::scalarText($item), $value));
            }

            return json_encode(self::normalizeRawValue($value), JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        if (is_scalar($value)) {
            return self::scalarText($value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return json_encode(self::normalizeRawValue($value), JSON_UNESCAPED_UNICODE) ?: '未知';
    }

    private static function scalarText(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '空',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => (string)$value,
        };
    }

    private static function normalizeRawValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalizeRawValue($item), $value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE) ?: 'null', true);
    }

    private static function normalizeStoredValue(mixed $value): mixed
    {
        $value = self::normalizeRawValue($value);

        if (is_string($value)) {
            return self::truncate($value, self::MAX_STORED_STRING_LENGTH);
        }

        if (!is_array($value)) {
            return $value;
        }

        // 变更明细用于详情展示，原始数组只保留摘要，避免角色节点等大数组撑大日志行。
        return self::limitStoredArray($value);
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private static function limitStoredArray(array $value): array
    {
        $total = count($value);
        $items = [];
        $index = 0;

        foreach ($value as $key => $item) {
            if ($index >= self::MAX_STORED_ARRAY_ITEMS) {
                break;
            }

            $items[$key] = is_array($item) ? self::limitStoredArray($item) : (
                is_string($item) ? self::truncate($item, self::MAX_STORED_STRING_LENGTH) : $item
            );
            ++$index;
        }

        if ($total <= self::MAX_STORED_ARRAY_ITEMS) {
            return $items;
        }

        return [
            'items' => $items,
            'total' => $total,
            'truncated' => true,
        ];
    }

    private static function recordLabel(CoreModel $model, string $titleField): string
    {
        if ($titleField === '') {
            return '';
        }

        $value = $model->{$titleField} ?? null;
        return $value === null ? '' : self::truncate(self::rawText($value), 40);
    }

    private static function sameValue(mixed $oldValue, mixed $newValue): bool
    {
        return json_encode(self::normalizeRawValue($oldValue), JSON_UNESCAPED_UNICODE)
            === json_encode(self::normalizeRawValue($newValue), JSON_UNESCAPED_UNICODE);
    }

    private static function truncate(string $value, int $length = self::MAX_VALUE_LENGTH): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '...' : $value;
    }
}
