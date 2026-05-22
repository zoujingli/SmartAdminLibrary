<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Events\Listener;

use Hyperf\Database\Model\Events\Event;
use Hyperf\Database\Model\Events\Saving;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Library\Constants\DataField;
use Library\Constants\System;
use Library\CoreModel;
use System\Model\SystemUser;

/**
 * 数据更新及创建.
 * @class ModelSavingListener
 */
#[Listener]
final class ModelSavingListener implements ListenerInterface
{
    public function listen(): array
    {
        return [Saving::class];
    }

    public function process(object $event): void
    {
        if ($event instanceof Event) {
            try {
                $model = $event->getModel();
                if ($model instanceof CoreModel) {
                    $user = user();
                    $fields = $model->getFillable();

                    // 设置租户隔离字段：租户空间内强制归属当前租户；平台空间只有具备租户管理能力时才允许显式写入目标租户。
                    if (in_array($field = DataField::TENANT, $fields)) {
                        $currentTenantId = max(0, System::getTenantId());
                        $rawTenantId = $model->{$field} ?? null;
                        $hasRequestedTenant = $rawTenantId !== null && $rawTenantId !== '';
                        $requestedTenantId = $hasRequestedTenant ? max(0, (int)$rawTenantId) : $currentTenantId;
                        try {
                            $canKeepRequestedTenant = $user
                                && (
                                    $user->isSuper()
                                    || $user->hasPermission('system.tenant.index')
                                    || $user->hasPermission('system.tenant.create')
                                );
                        } catch (\Throwable $exception) {
                            $canKeepRequestedTenant = false;
                        }

                        if ($currentTenantId > 0) {
                            $model->{$field} = $currentTenantId;
                        } elseif ($hasRequestedTenant && $requestedTenantId > 0 && $canKeepRequestedTenant) {
                            $model->{$field} = $requestedTenantId;
                        } else {
                            $model->{$field} = $currentTenantId;
                        }
                    }
                    // 如果是系统用户登录
                    if ($user instanceof SystemUser) {
                        // 设置记录更新人
                        if (in_array($field = DataField::UPDATED_BY, $fields)) {
                            $model->{$field} = $user->id;
                        }
                        // 设置记录创建人
                        if (in_array($field = DataField::CREATED_BY, $fields) && !$model->exists) {
                            // 新建记录的归属必须来自当前登录态，禁止请求体伪造 created_by 绕过后续数据范围。
                            $model->{$field} = $user->id;
                        }
                    }
                }
            } catch (\Throwable $exception) {
            }
        }
    }
}
