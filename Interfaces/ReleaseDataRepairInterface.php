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
 * 发布升级业务数据修复契约。
 *
 * 修复器必须先支持旧结构只读预检，再在 release 结构与必要数据同步完成后幂等写入；
 * `blocking` 中的任何项目都会在数据库写入前阻止本次发布。
 */
interface ReleaseDataRepairInterface
{
    public function code(): string;

    /**
     * @return array{required:bool,blocking:list<array<string,mixed>>,summary:array<string,mixed>,items?:list<array<string,mixed>>}
     */
    public function preview(): array;

    /**
     * @return array<string,mixed>
     */
    public function repair(): array;
}
