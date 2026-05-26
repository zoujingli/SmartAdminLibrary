<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Interfaces;

/**
 * 节点名称解析接口
 * 用于将权限节点转换为可读名称，解耦基础库与具体业务模块。
 */
interface NodeNameResolverInterface
{
    /**
     * 根据功能节点解析显示名称.
     */
    public function findNameByNode(string $node): string;
}
