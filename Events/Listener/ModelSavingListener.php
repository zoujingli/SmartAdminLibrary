<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Events\Listener;

use Hyperf\Database\Model\Events\Event;
use Hyperf\Database\Model\Events\Saving;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Library\Constants\DataField;
use Library\Constants\System;
use Library\CoreModel;
use Library\Exception\ErrorResponseException;
use Library\Helper\RequestHelper;
use Library\Support\TenantContext;
use System\Model\SystemLogsAction;
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
            $tenantAware = false;
            try {
                $model = $event->getModel();
                if ($model instanceof CoreModel) {
                    $fields = $model->getFillable();
                    $tenantAware = in_array(DataField::TENANT, $fields, true);

                    // 设置租户隔离字段：普通写入只认当前租户；平台代维护等显式入口需临时开启目标租户写入能力。
                    if ($tenantAware) {
                        $field = DataField::TENANT;
                        if ($model instanceof SystemLogsAction) {
                            // 操作日志允许 tenant_id=0 记录平台级或无可信租户事件；其它业务表仍必须写入真实租户。
                            $model->{$field} = max(0, (int)($model->{$field} ?? TenantContext::UNSET_TENANT_ID));
                        } else {
                            $currentTenantId = System::getTenantId();
                            $rawTenantId = $model->{$field} ?? null;
                            $hasRequestedTenant = $rawTenantId !== null && $rawTenantId !== '';
                            $requestedTenantId = $hasRequestedTenant ? (int)$rawTenantId : $currentTenantId;

                            if ($hasRequestedTenant && $requestedTenantId > 0 && TenantContext::canWriteExplicitTenant()) {
                                $model->{$field} = $requestedTenantId;
                            } elseif ($currentTenantId > 0) {
                                $model->{$field} = $currentTenantId;
                            } else {
                                throw new ErrorResponseException('租户上下文无效，禁止写入未归属数据');
                            }

                            if ((int)$model->{$field} <= 0) {
                                throw new ErrorResponseException('租户 ID 必须大于 0');
                            }
                        }
                    }

                    $user = $this->currentSystemUser();
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
            } catch (ErrorResponseException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                // 带 tenant_id 的模型写入必须 fail closed，任何上下文解析异常都不能继续落库到 0/null。
                if ($tenantAware) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * 只有请求中真实携带后台 Token 时才恢复用户，避免开放回调、站点公开访问和 CLI 任务已建立的租户上下文被空登录态清理。
     */
    private function currentSystemUser(): ?SystemUser
    {
        $request = RequestHelper::getRequest();
        if ($request === null) {
            return null;
        }

        $rawToken = trim((string)$request->getHeaderLine('Authorization'));
        if ($rawToken === '') {
            $rawToken = trim((string)$request->getHeaderLine('token'));
        }
        if ($rawToken === '') {
            return null;
        }

        $tenantExisted = TenantContext::has();
        $tenantId = TenantContext::get();
        $user = user(SystemUser::class);
        if (!$user instanceof SystemUser && $tenantExisted && TenantContext::get() !== $tenantId) {
            TenantContext::set($tenantId);
        }

        return $user instanceof SystemUser ? $user : null;
    }
}
