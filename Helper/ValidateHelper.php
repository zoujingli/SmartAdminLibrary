<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Helper;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Library\Exception\CoreResponseException;

/**
 * 输入验证服务
 * @class ValidateHelper
 */
final class ValidateHelper
{
    /**
     * 初始化构造.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ValidatorFactoryInterface $validator
    ) {}

    /**
     * 快捷输入并验证（ 支持 规则 # 别名 ）.
     * @param array $rules 验证规则（ 验证信息数组 ）
     * @param array|string $input 输入内容 ( all|get|post )
     * @return array
     *
     * name.max:20 => message // 最大长度
     * name.required => message // 必填内容
     * age.between:1,120 => message // 范围限定
     * name.default => 100 // 获取并设置默认值
     * region.value => value // 固定字段数值内容
     *
     * 非 required/default/value 字段只有在输入中存在时才会参与校验并返回，方便 Service::filterData() 直接
     * `return _vali($rules, $data)`，避免更新时未提交字段被补成 null 后误写入数据库。
     */
    public function check(array $rules, array|string $input = ''): array
    {
        if (is_string($input)) {
            $type = trim($input, '.') ?: 'all';
            $input = $this->request->{$type}();
        }
        [$data, $rule, $info, $pending] = [[], [], [], []];
        foreach ($rules as $name => $message) {
            if (is_numeric($name)) {
                [$name, $alias] = explode('#', $message . '#');
                if (array_key_exists($alias ?: $name, $input)) {
                    $data[$name] = $input[$alias ?: $name];
                }
            } elseif (!str_contains($name, '.')) {
                $data[$name] = $message;
            } elseif (preg_match('|^(.*?)\.(.*?)#(.*?)#?$|', $name . '#', $matches)) {
                [, $_key, $_rule, $alias] = $matches;
                $inputKey = $alias ?: $_key;
                $hasInput = array_key_exists($inputKey, $input);
                if (in_array($_rule, ['value', 'default'])) {
                    if ($_rule === 'value') {
                        $data[$_key] = $message;
                        $rule[$_key] = 'nullable';
                    } elseif ($_rule === 'default') {
                        $data[$_key] = $hasInput ? $input[$inputKey] : $message;
                        $rule[$_key] = 'nullable';
                    }
                } else {
                    $pending[] = [$_key, $_rule, $inputKey, $message];
                }
            }
        }

        foreach ($pending as [$_key, $_rule, $inputKey, $message]) {
            $hasInput = array_key_exists($inputKey, $input);
            if (!$hasInput && !array_key_exists($_key, $data) && !$this->requiresMissingFieldValidation($_rule)) {
                continue;
            }

            $info[explode(':', "{$_key}.{$_rule}")[0]] = $message;
            $data[$_key] = $data[$_key] ?? ($hasInput ? $input[$inputKey] : null);
            $rule[$_key] = isset($rule[$_key]) ? ($rule[$_key] . '|' . $_rule) : $_rule;
        }

        if (($validator = $this->validator->make($data, $rule, $info))->fails()) {
            throw new CoreResponseException($validator->errors()->first());
        }
        return $validator->validated();
    }

    /**
     * 这些规则本身需要感知字段缺失；即使输入中没有该字段，也要交给 Validator 触发错误或条件判断。
     */
    private function requiresMissingFieldValidation(string $rule): bool
    {
        $name = strtolower(explode(':', $rule, 2)[0]);

        return str_starts_with($name, 'required')
            || in_array($name, ['accepted', 'declined', 'present'], true);
    }
}
