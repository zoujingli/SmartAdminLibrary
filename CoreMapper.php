<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library;

use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Model;
use Library\Constants\DataField;
use Library\Constants\Status;
use Library\Constants\System;
use Library\Events\Processor\ScopeProcessor;

/**
 * 数据访问核心基类。
 *
 * 子类通过 `$model` 属性声明模型类，
 * 基类统一提供列表、分页、读写、启停、软删恢复等标准能力。
 *
 * @method array handleListExtra(array $params = [], bool $isScope = true)
 * @method array handleListItems(array $items, array $params = [])
 * @method Builder handleSearch(Builder $query, array $params)
 * @method Builder handleSubSearch(Builder $query, array $params)
 */
abstract class CoreMapper
{
    /**
     * 获取 Mapper 单例。
     */
    public static function once(): static
    {
        return _once(static::class);
    }

    /**
     * 获取不分页列表结果。
     */
    public function getDataList(?array $params, bool $isScope = true): array
    {
        $query = $this->makeQuery($params, $isScope);

        if (method_exists($this, 'handleListItems')) {
            $items = $this->handleListItems($query->get()->all(), $params ?? []);
        } else {
            $items = $query->get()->toArray();
        }

        $result = [
            'items' => $items,
            'pageInfo' => [
                'total' => count($items),
                'totalPage' => 1,
                'currentPage' => 1,
            ],
        ];

        if (method_exists($this, 'handleListExtra')) {
            $extra = $this->handleListExtra($params ?? [], $isScope);
            if ($extra !== null) {
                $result['extra'] = $extra;
            }
        }

        return $result;
    }

    /**
     * 获取分页列表结果。
     */
    public function getPageList(?array $params, bool $isScope = true, string $pageName = 'page'): array
    {
        $params ??= [];
        $pageSize = max(1, min(100, (int)($params['pageSize'] ?? $params['page_size'] ?? 15)));
        $currentPage = max(1, (int)($params[$pageName] ?? $params['page'] ?? 1));
        $query = $this->makeQuery($params, $isScope);
        $total = (int)$query->clone()->count();
        $items = $query->forPage($currentPage, $pageSize)->get()->all();

        if (method_exists($this, 'handleListItems')) {
            $items = $this->handleListItems($items, $params);
        }

        $result = [
            'items' => $items,
            'pageInfo' => [
                'total' => $total,
                'totalPage' => (int)ceil($total / $pageSize),
                'currentPage' => $currentPage,
            ],
        ];

        if (method_exists($this, 'handleListExtra')) {
            $extra = $this->handleListExtra($params, $isScope);
            if ($extra !== null) {
                $result['extra'] = $extra;
            }
        }

        return $result;
    }

    /**
     * 创建记录。
     */
    public function create(array $data): Model
    {
        $modelClass = $this->getModelClass();

        // 标准创建接口不接受请求体主键，避免外部指定 ID 造成覆盖、冲突或越权关联。
        return $modelClass::create($this->filterModelData($data, true));
    }

    /**
     * 更新记录。
     */
    public function update(mixed $id, array $data): bool
    {
        $model = $this->read($id);
        if (!$model) {
            return false;
        }

        $payload = $this->filterModelData($data, true);
        if ($payload === []) {
            return true;
        }

        return (bool)$model->update($payload);
    }

    /**
     * 读取单条记录。
     */
    public function read(mixed $id, array $column = ['*'], bool $isScope = true): ?Model
    {
        if ($id instanceof Model) {
            return $isScope && !$this->isModelAccessible($id) ? null : $id;
        }

        if (!is_numeric($id)) {
            return null;
        }

        return $this->makeOperationQuery([(int)$id], false, $isScope)->first($column);
    }

    /**
     * 读取包含软删除的数据，仍默认应用数据范围。
     */
    public function readWithTrashed(mixed $id, array $column = ['*'], bool $isScope = true): ?Model
    {
        if (!is_numeric($id)) {
            return null;
        }

        return $this->makeOperationQuery([(int)$id], true, $isScope)->first($column);
    }

    /**
     * 获取当前数据范围内可操作的模型集合。
     *
     * @param array<int|string, mixed> $ids
     * @return array<int, Model>
     */
    public function getOperationModels(array $ids, bool $withTrashed = false, bool $isScope = true): array
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return [];
        }

        return $this->makeOperationQuery($ids, $withTrashed, $isScope)->get()->all();
    }

    /**
     * 软删除一条或多条记录。
     */
    public function delete(array $ids): bool
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return true;
        }
        $models = $this->getOperationModels($ids);
        if (count($models) !== count($ids)) {
            return false;
        }

        foreach ($models as $model) {
            // Builder 批量删除不会触发单模型生命周期事件；标准 CRUD 逐条处理，保证观察者行为一致。
            $model->delete();
        }

        return true;
    }

    /**
     * 彻底删除一条或多条记录。
     */
    public function delreal(array $ids): bool
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return true;
        }
        $models = $this->getOperationModels($ids, true);
        if (count($models) !== count($ids)) {
            return false;
        }

        foreach ($models as $model) {
            // 彻底删除同样走模型实例，保留 forceDeleted 等生命周期事件语义。
            $model->forceDelete();
        }

        return true;
    }

    /**
     * 恢复一条或多条已软删除记录。
     */
    public function recovery(array $ids): bool
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return true;
        }
        $models = $this->getOperationModels($ids, true);
        if (count($models) !== count($ids)) {
            return false;
        }

        foreach ($models as $model) {
            if (!method_exists($model, 'restore')) {
                return false;
            }

            // 恢复同样走模型实例，保留 restored 生命周期事件语义。
            $model->restore();
        }

        return true;
    }

    /**
     * 启用一条或多条记录。
     */
    public function enable(array $ids, string $field = 'status'): bool
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return true;
        }
        $models = $this->getOperationModels($ids);
        if (count($models) !== count($ids)) {
            return false;
        }

        foreach ($models as $model) {
            // 批量 update 不触发 Updated 事件；逐条更新保证状态变更的模型事件一致。
            $model->update([$field => Status::ENABLED]);
        }

        return true;
    }

    /**
     * 禁用一条或多条记录。
     */
    public function disable(array $ids, string $field = 'status'): bool
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return true;
        }
        $models = $this->getOperationModels($ids);
        if (count($models) !== count($ids)) {
            return false;
        }

        foreach ($models as $model) {
            // 批量 update 不触发 Updated 事件；逐条更新保证状态变更的模型事件一致。
            $model->update([$field => Status::DISABLED]);
        }

        return true;
    }

    /**
     * 检查主键集合是否全部存在。
     */
    public function existsByKeys(array $values, mixed $where = []): bool
    {
        $modelClass = $this->getModelClass();
        $key = $this->getModel()->getKeyName();

        return $modelClass::whereIn($key, $values)->where($where)->count() === count(array_unique($values));
    }

    /**
     * 检查字段值是否已存在。
     */
    public function existsByField(string $field, mixed $value, mixed $where = []): bool
    {
        $value = is_array($value) ? ($value[$field] ?? null) : $value;
        $modelClass = $this->getModelClass();
        $query = $modelClass::where($field, $value);
        empty($where) || $query->where($where);

        return $query->exists();
    }

    /**
     * 根据字段值查找第一条记录。
     */
    public function findByField(string $field, mixed $value, mixed $where = []): ?Model
    {
        $value = is_array($value) ? ($value[$field] ?? null) : $value;
        $modelClass = $this->getModelClass();
        $query = $modelClass::where($field, $value);
        empty($where) || $query->where($where);

        return $query->first();
    }

    /**
     * 更新单个字段值。
     */
    public function changeValue(int|string $id, string $field, mixed $value): bool
    {
        return $this->update($id, [$field => $value]);
    }

    /**
     * 更新排序字段。
     */
    public function changeSort(int $id, int $value): bool
    {
        return $this->changeValue($id, CoreModel::FIELD_SORT, $value);
    }

    /**
     * 更新状态字段。
     */
    public function changeStatus(int $id, int $value): bool
    {
        return $this->changeValue($id, CoreModel::FIELD_STATUS, $value);
    }

    /**
     * 创建模型实例。
     */
    public function getModel(): Model
    {
        $modelClass = $this->getModelClass();

        return new $modelClass();
    }

    /**
     * 使用子类生成的子查询过滤当前查询。
     */
    public function bindSubQuery(Builder $query, array $params, string $field = '', bool $trashed = true): Builder
    {
        if (!method_exists($this, 'handleSubSearch')) {
            return $query;
        }

        $modelClass = $this->getModelClass();
        $self = $modelClass::query();
        $model = $this->getModel();

        if ($trashed && method_exists($model, 'trashed')) {
            $self->withTrashed();
        }

        $field = $field ?: substr(strrchr($model->getTable(), '_'), 1) . '_id';
        $field = $this->normalizeQueryColumn($field, $this->queryableFields($model));
        if ($field === null) {
            return $query;
        }

        $subQuery = $this->handleSubSearch($self, $params)->select([$model->getKeyName()]);
        if (!empty($subQuery->getQuery()->wheres ?? [])) {
            $query->whereIn($field, $subQuery);
        }

        return $query;
    }

    /**
     * 应用数据权限范围。
     */
    protected function applyDataScope(Builder $query, string $userField = DataField::CREATED_BY, ?string $deptField = null): Builder
    {
        return ScopeProcessor::applyScope($query, null, $userField, $deptField);
    }

    /**
     * 平台账号在用户分配场景可按目标租户读取下拉选项；租户账号不能通过请求参数切换租户边界。
     */
    protected function applyRequestedTenantScope(Builder $query, array $params): Builder
    {
        $rawTenantId = $params[DataField::TENANT] ?? $params['tenantId'] ?? 0;
        $tenantId = is_scalar($rawTenantId) ? (int)$rawTenantId : 0;
        if ($tenantId <= 0 || !System::isPlatformTenant()) {
            return $query;
        }

        try {
            $user = user();
        } catch (\Throwable) {
            $user = null;
        }

        if (!$user || (!$user->isSuper() && !$user->hasPermission('system.tenant.index'))) {
            return $query;
        }

        return $query->withoutGlobalScope(DataField::TENANT)->where(DataField::TENANT, $tenantId);
    }

    /**
     * 检查当前用户是否可访问目标数据。
     */
    protected function canAccessData(int $dataUserId, ?int $dataDeptId = null): bool
    {
        return ScopeProcessor::canAccessData($dataUserId, $dataDeptId);
    }

    /**
     * 是否对按主键读写操作应用数据范围。
     *
     * 通用模块默认开启；平台级全局资源可在 Mapper 中显式返回 false。
     */
    protected function isOperationScopeEnabled(): bool
    {
        return true;
    }

    /**
     * 构建按主键读写的基础查询，默认应用租户和数据范围。
     *
     * @param array<int, int> $ids
     */
    protected function makeOperationQuery(array $ids = [], bool $withTrashed = false, bool $isScope = true): Builder
    {
        $modelClass = $this->getModelClass();
        $query = $withTrashed ? $modelClass::withTrashed() : $modelClass::query();

        if ($ids !== []) {
            $query->whereIn($this->getModel()->getKeyName(), $ids);
        }

        if ($isScope && $this->isOperationScopeEnabled()) {
            $query = $this->applyOperationScope($query);
        }

        return $query;
    }

    /**
     * 读写操作的数据范围策略：
     * - 有 created_by 时使用用户/部门数据范围；
     * - 只有 dept_id 时按部门范围过滤；
     * - 只有 tenant_id 时依赖 CoreModel 的租户全局范围；
     * - 都没有时默认拒绝，避免新模块裸奔。
     */
    protected function applyOperationScope(Builder $query): Builder
    {
        $model = $this->getModel();
        $fields = $this->queryableFields($model);
        $userField = in_array(DataField::CREATED_BY, $fields, true) ? DataField::CREATED_BY : null;
        $deptField = in_array(DataField::DEPT_ID, $fields, true) ? DataField::DEPT_ID : null;

        if ($userField !== null) {
            return $this->applyDataScope($query, $userField, $deptField);
        }

        if ($deptField !== null) {
            try {
                $user = user();
            } catch (\Throwable) {
                $user = null;
            }
            if (!$user) {
                return $this->denyAll($query);
            }

            $deptIds = ScopeProcessor::getDeptIds($user);
            return $deptIds !== [] ? $query->whereIn($deptField, $deptIds) : $this->denyAll($query);
        }

        if (in_array(DataField::TENANT, $fields, true)) {
            return $query;
        }

        return $this->denyAll($query);
    }

    /**
     * @param array<int|string, mixed> $ids
     * @return array<int, int>
     */
    protected function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (mixed $id): int => (int)$id, $ids), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param array<int, int> $ids
     */
    protected function allOperationIdsAccessible(array $ids, bool $withTrashed = false): bool
    {
        if ($ids === []) {
            return false;
        }

        return (int)$this->makeOperationQuery($ids, $withTrashed)->count() === count($ids);
    }

    protected function isModelAccessible(Model $model): bool
    {
        if (!is_a($model, $this->getModelClass())) {
            return false;
        }

        $key = $model->getKey();
        if (!is_numeric($key)) {
            return false;
        }

        return $this->makeOperationQuery([(int)$key], method_exists($model, 'trashed') && (bool)$model->trashed())->exists();
    }

    protected function denyAll(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }

    /**
     * 基于请求参数构建基础查询。
     */
    protected function makeQuery(?array $params, bool $isScope): Builder
    {
        $params ??= [];
        $query = $this->makeFilteredQuery($params, $isScope);
        $this->applyQuerySelect($query, $params);
        $this->applyQueryOrder($query, $params);

        return $query;
    }

    /**
     * 在查询字段列表中仅保留有效可填充字段。
     */
    protected function filterQueryAttrs(array $fields, bool $removePk = false): array
    {
        $model = $this->getModel();
        $attrs = $this->queryableFields($model, true);
        $aliases = array_map('strtolower', $this->querySelectableAliases());

        foreach ($fields as $key => $field) {
            $field = trim((string)$field);
            $normalized = $this->normalizeQueryColumn($field, $attrs);
            $aliasAllowed = in_array(strtolower($field), $aliases, true);

            if ($normalized === null && !$aliasAllowed) {
                unset($fields[$key]);
            } else {
                $fields[$key] = $aliasAllowed ? $field : $normalized;
            }
        }

        if ($removePk) {
            $pkIndex = array_search($model->getKeyName(), $fields, true);
            if ($pkIndex !== false) {
                unset($fields[$pkIndex]);
            }
        }

        return $fields === [] ? ['*'] : array_values($fields);
    }

    /**
     * 允许子类显式开放安全的查询别名或表达式。
     *
     * @return array<int, string>
     */
    protected function querySelectableAliases(): array
    {
        return [];
    }

    /**
     * 应用请求传入的选择字段。
     */
    protected function applyQuerySelect(Builder $query, array $params): void
    {
        empty($params['select']) || $query->select($this->filterQueryAttrs(str2arr($params['select'])));
    }

    /**
     * 应用请求传入的排序字段，字段与方向都必须经过白名单标准化。
     */
    protected function applyQueryOrder(Builder $query, array $params): void
    {
        $model = $query->getModel() ?: $this->getModel();
        $fields = $this->queryableFields($model);
        $orderBy = $params['orderBy'] ?? null;

        if ($orderBy !== null && $orderBy !== false && $orderBy !== '') {
            $orders = is_array($orderBy) ? array_values($orderBy) : str2arr((string)$orderBy);
            $orderTypes = is_array($params['orderType'] ?? null) ? array_values($params['orderType']) : null;
            $applied = [];

            foreach ($orders as $index => $order) {
                $field = $this->normalizeQueryColumn((string)$order, $fields);
                if ($field === null) {
                    continue;
                }

                $direction = $this->normalizeOrderDirection($orderTypes[$index] ?? $params['orderType'] ?? 'asc');
                $query->orderBy($field, $direction);
                $applied[] = $field;

                if ($field === 'sort' && in_array('id', $fields, true) && !in_array('id', $applied, true)) {
                    $query->orderBy('id', $this->sortTieBreakerDirection($direction));
                    $applied[] = 'id';
                }
            }

            if ($applied !== []) {
                return;
            }
        }

        $this->applyDefaultOrder($query, $fields);
    }

    /**
     * 默认排序：优先 sort，其次 id。
     *
     * @param array<int, string> $fields
     */
    protected function applyDefaultOrder(Builder $query, array $fields): void
    {
        if (in_array('sort', $fields, true)) {
            $query->orderBy('sort', 'desc');
            in_array('id', $fields, true) && $query->orderBy('id', $this->defaultIdOrderDirection());
            return;
        }

        in_array('id', $fields, true) && $query->orderBy('id', $this->defaultIdOrderDirection());
    }

    protected function defaultIdOrderDirection(): string
    {
        return 'desc';
    }

    protected function sortTieBreakerDirection(string $sortDirection): string
    {
        return $sortDirection;
    }

    /**
     * @return array<int, string>
     */
    protected function queryableFields(Model $model, bool $forSelect = false): array
    {
        $fields = $model->getFillable();
        $fields[] = $model->getKeyName();
        $fields = array_values(array_unique(array_filter(array_map('strval', $fields))));

        if ($forSelect && method_exists($model, 'getHidden')) {
            $hidden = array_map('strval', $model->getHidden());
            $fields = array_values(array_diff($fields, $hidden));
        }

        return $fields;
    }

    /**
     * 标准化外部传入的字段名，仅允许模型字段或当前模型表前缀字段。
     *
     * @param array<int, string> $allowed
     */
    protected function normalizeQueryColumn(string $field, array $allowed): ?string
    {
        $field = trim($field);
        if ($field === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $field)) {
            return null;
        }

        $table = $this->getModel()->getTable();
        $column = $field;
        if (str_contains($field, '.')) {
            [$prefix, $column] = explode('.', $field, 2);
            if ($prefix !== $table) {
                return null;
            }
        }

        return in_array($column, $allowed, true) ? $field : null;
    }

    protected function normalizeOrderDirection(mixed $direction): string
    {
        return strtolower((string)$direction) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * 在持久化数据中仅保留可填充字段。
     */
    protected function filterModelData(array $data, bool $removePk = false): array
    {
        $model = $this->getModel();
        $fillable = $model->getFillable();
        $primaryKey = $model->getKeyName();
        // 标准写入口不接受审计字段和软删时间戳；这些字段由监听器或专用状态接口维护。
        $guardedFields = $removePk ? array_fill_keys([
            $primaryKey,
            DataField::CREATED_BY,
            DataField::UPDATED_BY,
            'created_at',
            'updated_at',
            'deleted_at',
        ], true) : [];
        $filtered = [];

        foreach ($data as $field => $value) {
            if (!is_string($field) || !in_array($field, $fillable, true)) {
                continue;
            }

            if (isset($guardedFields[$field])) {
                continue;
            }

            $filtered[$field] = $value;
        }

        return $filtered;
    }

    /**
     * 构建不包含列表专用选择和排序的筛选查询。
     */
    protected function makeFilteredQuery(?array $params, bool $isScope): Builder
    {
        $params ??= [];
        $modelClass = $this->getModelClass();
        $query = ($params['recycle'] ?? false) === true ? $modelClass::onlyTrashed() : $modelClass::query();
        $isScope && ($query = $this->applyDataScope($query));

        return method_exists($this, 'handleSearch') ? $this->handleSearch($query, $params) : $query;
    }

    /**
     * 构建适用于统计或扩展数据的查询。
     */
    protected function makeStatsQuery(array $params = [], bool $isScope = true): Builder
    {
        return $this->makeFilteredQuery($params, $isScope);
    }

    /**
     * 统计今日新增数量。
     */
    protected function countToday(Builder $query, string $field = 'created_at'): int
    {
        return (int)$query->clone()->whereDate($field, date('Y-m-d'))->count();
    }

    /**
     * 统计本月新增数量。
     */
    protected function countThisMonth(Builder $query, string $field = 'created_at'): int
    {
        return (int)$query->clone()
            ->whereYear($field, date('Y'))
            ->whereMonth($field, date('m'))
            ->count();
    }

    /**
     * 按指定字段分组统计数量。
     */
    protected function pluckGroupedCounts(Builder $query, string $field): array
    {
        return $query->clone()
            ->selectRaw("{$field}, COUNT(*) as count")
            ->groupBy($field)
            ->pluck('count', $field)
            ->toArray();
    }

    /**
     * 构建通用状态统计摘要。
     */
    protected function buildStatusStatisticsSummary(array $params = [], bool $isScope = true, string $statusField = CoreModel::FIELD_STATUS, string $dateField = 'created_at'): array
    {
        try {
            $query = $this->makeStatsQuery($params, $isScope);
            $statusStats = $this->pluckGroupedCounts($query, $statusField);

            return [
                'total' => (int)$query->count(),
                'active' => (int)($statusStats[Status::ENABLED] ?? 0),
                'inactive' => (int)($statusStats[Status::DISABLED] ?? 0),
                'active_count' => (int)($statusStats[Status::ENABLED] ?? 0),
                'inactive_count' => (int)($statusStats[Status::DISABLED] ?? 0),
                'today_created' => $this->countToday($query, $dateField),
            ];
        } catch (\Throwable) {
            return [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'active_count' => 0,
                'inactive_count' => 0,
                'today_created' => 0,
            ];
        }
    }

    /**
     * 构建列表扩展用的通用状态统计块。
     */
    protected function buildStatusListExtra(array $params = [], bool $isScope = true, string $statusField = CoreModel::FIELD_STATUS, string $dateField = 'created_at'): array
    {
        try {
            $query = $this->makeStatsQuery($params, $isScope);
            $statusStats = $this->pluckGroupedCounts($query, $statusField);

            return [
                'statistics' => [
                    'total' => (int)$query->count(),
                    'today' => $this->countToday($query, $dateField),
                    'by_status' => $statusStats,
                    'active_count' => (int)($statusStats[Status::ENABLED] ?? 0),
                    'inactive_count' => (int)($statusStats[Status::DISABLED] ?? 0),
                ],
            ];
        } catch (\Throwable) {
            return [
                'statistics' => [
                    'total' => 0,
                    'today' => 0,
                    'by_status' => [],
                    'active_count' => 0,
                    'inactive_count' => 0,
                ],
            ];
        }
    }

    /**
     * 解析子类提供的模型类名。
     *
     * @return class-string<Model>
     */
    protected function getModelClass(): string
    {
        if (!property_exists($this, 'model')) {
            throw new \InvalidArgumentException(sprintf('Mapper [%s] must define a $model property.', static::class));
        }

        /** @var mixed $modelClass */
        $modelClass = $this->{'model'};

        if (!is_string($modelClass) || !is_a($modelClass, Model::class, true)) {
            throw new \InvalidArgumentException(sprintf('Mapper [%s] must provide a model class extending %s.', static::class, Model::class));
        }

        return $modelClass;
    }
}
