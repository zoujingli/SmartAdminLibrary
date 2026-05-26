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

use Hyperf\Database\Model\Builder as ModelBuilder;
use Hyperf\Database\Query\Builder as QueryBuilder;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Contract\RequestInterface;
use Library\Exception\CoreResponseException;

/**
 * 快速查询助手工具.
 * @class QueryHelper
 * @mixin \Hyperf\Database\Model\Builder
 * @mixin \Hyperf\Database\Query\Builder
 */
final class QueryHelper
{
    /**
     * 当前输入变量.
     */
    private array|string $input = 'all';

    /**
     * 模型或查询对象
     */
    private ModelBuilder|QueryBuilder $query;

    /**
     * 初始化构造.
     */
    public function __construct(
        public RequestInterface $request
    ) {}

    /**
     * 动态设置属性.
     */
    public function __set(string $name, mixed $value)
    {
        $this->query->{$name} = $value;
    }

    /**
     * 动态调用模型方法.
     * @return $this|mixed
     */
    public function __call(string $name, array $args)
    {
        if (is_callable($callable = [$this->query, $name])) {
            $value = $callable(...$args);
            return match (true) {
                $name[0] === '_' => $this,
                $value instanceof $this->query => $this,
                default => $value
            };
        }
        return $this;
    }

    /**
     * 获取数据库对象
     */
    public function getQuery(): ModelBuilder|QueryBuilder
    {
        return $this->query;
    }

    /**
     * 设置查询对象
     * @param ModelBuilder|QueryBuilder|string $query 查询实例
     * @param array|string $input 输入内容
     * @param null|callable $callable 回调函数
     * @return $this
     */
    public function withQuery(ModelBuilder|QueryBuilder|string $query, array|string $input = 'all', ?callable $callable = null): static
    {
        $this->input = $this->getInputData($input);
        $this->query = match (true) {
            is_string($query) => Db::table($query),
            default => $query
        };

        $callable && $callable($this, $this->query);
        return $this;
    }

    /**
     * 设置 Like 查询条件.
     * @param array|string $fields 查询字段
     * @param string $split 前后分割符
     * @param null|array|string $input 输入数据
     * @param string $alias 别名分割符
     * @return $this
     */
    public function like(array|string $fields, string $split = '', array|string|null $input = null, string $alias = '#'): static
    {
        $data = $this->getInputData($input ?? $this->input);
        foreach (str2arr($fields) as $field) {
            [$dk, $qk] = str_contains($field, $alias) ? explode($alias, $field, 2) : [$field, $field];
            if (isset($data[$qk]) && $data[$qk] !== '') {
                $this->setWhere($dk, 'like', "%{$split}{$data[$qk]}{$split}%");
            }
        }
        return $this;
    }

    /**
     * 设置 Equal 查询条件.
     * @param array|string $fields 查询字段
     * @param null|array|string $input 输入类型
     * @param string $alias 别名分割符
     * @return $this
     */
    public function equal(array|string $fields, array|string|null $input = null, string $alias = '#'): QueryHelper
    {
        $data = $this->getInputData($input ?: $this->input);
        foreach (str2arr($fields) as $field) {
            [$dk, $qk] = [$field, $field];
            if (stripos($field, $alias) !== false) {
                [$dk, $qk] = explode($alias, $field);
            }
            if (isset($data[$qk]) && $data[$qk] !== '') {
                $this->setWhere($dk, '=', strval($data[$qk]));
            }
        }
        return $this;
    }

    /**
     * 设置 IN 区间查询.
     * @param array|string $fields 查询字段
     * @param string $split 输入分隔符
     * @param null|array|string $input 输入数据
     * @param string $alias 别名分割符
     * @return $this
     */
    public function in(array|string $fields, string $split = ',', array|string|null $input = null, string $alias = '#'): QueryHelper
    {
        $data = $this->getInputData($input ?: $this->input);
        foreach (str2arr($fields) as $field) {
            [$dk, $qk] = [$field, $field];
            if (stripos($field, $alias) !== false) {
                [$dk, $qk] = explode($alias, $field);
            }
            if (isset($data[$qk]) && $data[$qk] !== '') {
                $this->setWhere($dk, 'in', is_array($data[$qk]) ? $data[$qk] : explode($split, strval($data[$qk])));
            }
        }
        return $this;
    }

    /**
     * 两字段范围查询.
     * @example field1:field2#field,field11:field22#field00
     * @param array|string $fields 查询字段
     * @param null|array|string $input 输入数据
     * @param string $alias 别名分割符
     * @return $this
     */
    public function valueRange(array|string $fields, array|string|null $input = null, string $alias = '#'): QueryHelper
    {
        $data = $this->getInputData($input ?: $this->input);
        foreach (str2arr($fields) as $field) {
            if (str_contains($field, ':')) {
                if (stripos($field, $alias) !== false) {
                    [$dk0, $qk0] = explode($alias, $field);
                    [$dk1, $dk2] = explode(':', $dk0);
                } else {
                    [$qk0] = [$dk1, $dk2] = explode(':', $field, 2);
                }
                if (isset($data[$qk0]) && $data[$qk0] !== '') {
                    $this->query->where([[$dk1, '<=', $data[$qk0]], [$dk2, '>=', $data[$qk0]]]);
                }
            }
        }
        return $this;
    }

    /**
     * 设置内容区间查询.
     * @param array|string $fields 查询字段
     * @param string $split 输入分隔符
     * @param null|array|string $input 输入数据
     * @param string $alias 别名分割符
     * @return $this
     */
    public function valueBetween(array|string $fields, string $split = ' ', array|string|null $input = null, string $alias = '#'): QueryHelper
    {
        return $this->setBetweenWhere($fields, $split, $input, $alias);
    }

    /**
     * 设置日期时间区间查询.
     * @param array|string $fields 查询字段
     * @param string $split 输入分隔符
     * @param null|array|string $input 输入数据
     * @param string $alias 别名分割符
     * @return $this
     */
    public function dateBetween(array|string $fields, string $split = ' - ', array|string|null $input = null, string $alias = '#'): QueryHelper
    {
        return $this->setBetweenWhere($fields, $split, $input, $alias, static function ($value, $type) {
            if (preg_match('#^\d{4}(-\d\d){2}\s+\d\d(:\d\d){2}$#', $value)) {
                return $value;
            }
            return $type === 'after' ? "{$value} 23:59:59" : "{$value} 00:00:00";
        });
    }

    /**
     * 设置时间戳区间查询.
     * @param array|string $fields 查询字段
     * @param string $split 输入分隔符
     * @param null|array|string $input 输入数据
     * @param string $alias 别名分割符
     * @return $this
     */
    public function timeBetween(array|string $fields, string $split = ' - ', array|string|null $input = null, string $alias = '#'): QueryHelper
    {
        return $this->setBetweenWhere($fields, $split, $input, $alias, static function ($value, $type) {
            if (preg_match('#^\d{4}(-\d\d){2}\s+\d\d(:\d\d){2}$#', $value)) {
                return strtotime($value);
            }
            return $type === 'after' ? strtotime("{$value} 23:59:59") : strtotime("{$value} 00:00:00");
        });
    }

    /**
     * 支持 | 和 & 操作符.
     */
    private function setWhere(string $key, string $type, array|string ...$args): void
    {
        $this->query->where(function (ModelBuilder|QueryBuilder $query) use ($key, $type, $args) {
            // 使用 match 表达式优化方法映射
            $method = match ($type) {
                'in' => 'whereIn','between' => 'whereBetween',default => 'where'
            };
            // 标准规则时追加参数
            if ($method === 'where') {
                array_unshift($args, $type);
            }
            // 支持 | 和 & 混合规则
            foreach (str2arr($key, '&') as $andGroup) {
                $orFields = str2arr($andGroup, '|');
                // 使用 match 表达式优化条件判断
                match (count($orFields)) {
                    1 => $query->{$method}($orFields[0], ...$args),
                    default => $this->processOrFields($query, $orFields, $method, $args, $andGroup)
                };
            }
        });
    }

    /**
     * 处理 OR 字段组.
     */
    private function processOrFields(ModelBuilder|QueryBuilder $query, array $orFields, string $method, array $args, string $andGroup): void
    {
        // 检查是否连续两个 |，如果是则不使用括号
        if (str_contains($andGroup, '||')) {
            // 连续两个 |，不使用括号，直接使用 or
            foreach ($orFields as $fieldIndex => $field) {
                $query->{$fieldIndex === 0 ? $method : "or{$method}"}($field, ...$args);
            }
        } else {
            // 多个字段用 | 连接，需要用括号包围
            $query->where(function (ModelBuilder|QueryBuilder $subQuery) use ($orFields, $method, $args) {
                foreach ($orFields as $fieldIndex => $field) {
                    $subQuery->{$fieldIndex === 0 ? $method : "or{$method}"}($field, ...$args);
                }
            });
        }
    }

    /**
     * 设置区域查询条件.
     * @param array|string $fields 查询字段
     * @param string $split 输入分隔符
     * @param null|array|string $input 输入数据
     * @param string $alias 别名分割符
     * @param null|callable $callback 回调函数
     * @return $this
     */
    private function setBetweenWhere(array|string $fields, string $split = ' ', array|string|null $input = null, string $alias = '#', ?callable $callback = null): QueryHelper
    {
        $data = $this->getInputData($input ?: $this->input);
        foreach (str2arr($fields) as $field) {
            [$dk, $qk] = [$field, $field];
            if (stripos($field, $alias) !== false) {
                [$dk, $qk] = explode($alias, $field);
            }
            if (!empty($data[$qk])) {
                if (is_array($data[$qk]) || is_string($data[$qk])) {
                    [$begin, $after] = is_array($data[$qk]) ? $data[$qk] : explode($split, $data[$qk] . $split);
                    empty($after) && $after = $begin;
                } else {
                    throw new CoreResponseException('无效区间时间！');
                }
                if (is_callable($callback)) {
                    $after = call_user_func($callback, $after, 'after');
                    $begin = call_user_func($callback, $begin, 'begin');
                }
                $this->setWhere($dk, 'between', [$begin, $after]);
            }
        }
        return $this;
    }

    /**
     * 动态初始化数据.
     */
    private function getInputData(array|string $input = ''): array
    {
        return is_array($input) ? $input : $this->request->{$input ?: 'all'}();
    }
}
