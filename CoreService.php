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

use Hyperf\Database\Model\Model;
use Library\Exception\CoreResponseException;
use Library\Exception\ErrorResponseException;

use function Hyperf\Support\class_basename;

/**
 * 服务核心基类。
 *
 * 子类通过 `$mapper` 属性注入对应的数据访问层，
 * 基类统一负责动态转发、公共创建更新逻辑和唯一性校验。
 *
 * @method array getDataList(?array $params = null, bool $isScope = true)
 * @method array getPageList(?array $params = null, bool $isScope = true, string $pageName = 'page')
 * @method bool changeSort(int $id, int $sort)
 * @method bool changeStatus(int $id, int $status)
 * @method null|Model read(mixed $id, array $column = ['*'], bool $isScope = true)
 * @method null|Model findByField(string $field, mixed $value, mixed $where = [])
 * @method bool delete(array $ids)
 * @method bool delreal(array $ids)
 * @method bool recovery(array $ids)
 * @method bool enable(array $ids, string $field = 'status')
 * @method bool disable(array $ids, string $field = 'status')
 * @method Model getModel()
 * @method bool existsByKeys(array $values, mixed $where = [])
 * @method bool existsByField(string $field, mixed $value, mixed $where = [])
 *
 * @mixin \Library\CoreMapper
 */
abstract class CoreService
{
    /**
     * 将未定义的实例方法代理到 Mapper。
     */
    public function __call(string $name, array $arguments): mixed
    {
        $mapper = $this->getMapper();

        if (method_exists($mapper, $name)) {
            return $mapper->{$name}(...$arguments);
        }

        throw new CoreResponseException(sprintf('Method [%s->%s] does not exist.', class_basename(static::class), $name));
    }

    /**
     * 将未定义的静态方法代理到服务单例。
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (method_exists(static::class, $name)) {
            return _once(static::class)->{$name}(...$arguments);
        }

        throw new CoreResponseException(sprintf('Method [%s::%s] does not exist.', class_basename(static::class), $name));
    }

    /**
     * 获取服务单例。
     */
    public static function once(): static
    {
        return _once(static::class);
    }

    /**
     * 创建数据并返回模型对象。
     */
    public function create(array $data): ?Model
    {
        return $this->getMapper()->create($this->filterData($data));
    }

    /**
     * 更新数据并返回持久化结果。
     */
    public function update(mixed $id, array $data): bool
    {
        $mapper = $this->getMapper();
        $model = $mapper->read($id);

        if (!$model) {
            throw new ErrorResponseException('数据不存在');
        }

        return $mapper->update($model, $this->filterData($data, $model->toArray()));
    }

    /**
     * 获取回收站分页列表。
     */
    public function getRecycleList(array $params = []): array
    {
        $params['recycle'] = true;

        return $this->getMapper()->getPageList($params);
    }

    /**
     * 在写入前对数据进行业务过滤。
     */
    protected function filterData(array &$data, array $exists = []): array
    {
        return $data;
    }

    /**
     * 校验字段值在当前 Mapper 作用域内保持唯一。
     */
    protected function ensureUniqueField(string $field, array $data, array $exists, string $message): void
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            return;
        }

        if ($exists !== [] && array_key_exists($field, $exists) && (string)$exists[$field] === (string)$data[$field]) {
            return;
        }

        $where = !empty($exists) && array_key_exists('id', $exists)
            ? fn ($query) => $query->where('id', '!=', $exists['id'])
            : [];

        if ($this->getMapper()->existsByField($field, $data[$field], $where)) {
            throw new ErrorResponseException($message);
        }
    }

    /**
     * 解析子类提供的 Mapper 实例。
     */
    protected function getMapper(): CoreMapper
    {
        if (!property_exists($this, 'mapper')) {
            throw new \InvalidArgumentException(sprintf('Service [%s] must define a $mapper property.', static::class));
        }

        /** @var mixed $mapper */
        $mapper = $this->{'mapper'};

        if (!$mapper instanceof CoreMapper) {
            throw new \InvalidArgumentException(sprintf('Service [%s] must provide a mapper extending %s.', static::class, CoreMapper::class));
        }

        return $mapper;
    }
}
