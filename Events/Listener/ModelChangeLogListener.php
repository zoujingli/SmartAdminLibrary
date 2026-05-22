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

use Hyperf\Database\Model\Events\Created;
use Hyperf\Database\Model\Events\Deleted;
use Hyperf\Database\Model\Events\Event;
use Hyperf\Database\Model\Events\ForceDeleted;
use Hyperf\Database\Model\Events\Restored;
use Hyperf\Database\Model\Events\Updated;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Library\CoreModel;
use Library\Support\ModelChangeLog;
use System\Model\SystemLogsAction;
use System\Model\SystemLogsChange;

#[Listener]
final class ModelChangeLogListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            Created::class,
            Updated::class,
            Deleted::class,
            ForceDeleted::class,
            Restored::class,
        ];
    }

    public function process(object $event): void
    {
        if (!$event instanceof Event) {
            return;
        }

        $model = $event->getModel();
        if (!$model instanceof CoreModel || $model instanceof SystemLogsAction || $model instanceof SystemLogsChange) {
            return;
        }

        // 只有声明日志规则的模型才记录字段变更，避免通用底座自动产生不可读噪音。
        if ($model->getLogRules() === []) {
            return;
        }

        if ($event instanceof Created) {
            ModelChangeLog::recordModel($model, 'created', $model->getAttributes(), []);
            return;
        }

        if ($event instanceof Updated) {
            $changes = $model->getChanges();
            $original = [];
            foreach ($changes as $field => $value) {
                $original[(string)$field] = $model->getOriginal((string)$field);
            }
            ModelChangeLog::recordModel($model, 'updated', $changes, $original);
            return;
        }

        if ($event instanceof Deleted) {
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }

            // 删除/恢复属于动作型变更，不能依赖 deleted_at 字段，否则会被审计字段过滤掉。
            ModelChangeLog::recordFields($model, 'deleted', [[
                'field' => 'action',
                'label' => '操作',
                'old' => '正常',
                'new' => '已删除',
            ]]);
            return;
        }

        if ($event instanceof ForceDeleted) {
            ModelChangeLog::recordFields($model, 'force_deleted', [[
                'field' => 'action',
                'label' => '操作',
                'old' => method_exists($model, 'trashed') && $model->trashed() ? '已删除' : '正常',
                'new' => '彻底删除',
            ]]);
            return;
        }

        if ($event instanceof Restored) {
            ModelChangeLog::recordFields($model, 'restored', [[
                'field' => 'action',
                'label' => '操作',
                'old' => '已删除',
                'new' => '正常',
            ]]);
        }
    }
}
