<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */
$messages = [
    '系统异常',
    '操作失败',
    '操作成功',
    '操作不存在',
    '无操作权限',
    '未登录授权',
    '无授权令牌',
    '无权限访问',
    '页面不存在',
    '获取成功',
    '数据不存在',
    '目标为空',
    '删除成功',
    '删除失败',
    '无效区间时间！',
    '不支持在 Phar 环境运行！',
];

return array_combine($messages, $messages);
