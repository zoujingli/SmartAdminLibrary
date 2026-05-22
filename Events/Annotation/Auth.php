<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Events\Annotation;

use Hyperf\Di\Annotation\AbstractAnnotation;
use System\Model\SystemUser;

/**
 * 权限认证注解
 * 用于标记需要权限验证的控制器和方法.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Auth extends AbstractAnnotation
{
    // 检查授权权限，需要登录并验证权限
    public const CHECK = 'check';

    // 登录检查权限，仅需要验证登录状态
    public const LOGIN = 'login';

    protected const map = [
        'add' => '添加',
        'edit' => '编辑',
        'index' => '管理',
        'remove' => '删除',
        'forbid' => '禁用',
        'resume' => '启用',
    ];

    /**
     * 运行时解析后的权限节点。
     */
    public string $node = '';

    /**
     * 实际用于鉴权的登录用户模型类名。
     */
    public string $userModel = SystemUser::class;

    /**
     * @param ?string $name 权限名称
     * @param string $type 权限类型(login检查登录,auth检查授权)
     * @param ?bool $menu 是否菜单
     * @param ?string $code 自定义权限节点，留空时按控制器方法自动推导
     * @param ?string $userModel 明确指定登录用户模型类名；默认后台 SystemUser
     */
    public function __construct(
        public ?string $name = '',
        public string $type = Auth::CHECK,
        public ?bool $menu = false,
        public ?string $code = null,
        ?string $userModel = null,
    ) {
        // Auth 注解只接受明确用户模型类名；后台默认 SystemUser，Project 前台接口必须显式声明 ProjectAccount。
        $this->userModel = $userModel ?: SystemUser::class;
    }

    /**
     * 权限绑定节点.
     * @return $this
     */
    public function with(string $node): static
    {
        [, $method] = explode('@', $node . '@');
        $prefix = self::map[$method] ?? '';
        if (mb_strlen($prefix) > 0 && mb_strpos($this->name, $prefix) !== 0) {
            $this->name = $prefix . $this->name;
        }
        $this->node = self::parseNode($this->code ?: $node) ?? '';
        return $this;
    }

    /**
     * 路径节点转换.
     */
    public static function parseNode(string $name): ?string
    {
        // 统一节点格式：模块.资源.动作
        // 约定：
        // - 去除命名空间中的 Controller 段
        // - camelCase 动作统一转为 kebab-case（如 resetPassword -> reset-password）
        // - 将 \ / @ : _ 统一转为 .
        // - 合并连续分隔符，并去掉首尾 .
        $name = str_replace(
            ['\Controller\\', '/Controller/', 'Controller@', 'Controller:', 'Controller\\', 'Controller/'],
            ['\\', '/', '@', ':', '\\', '/'],
            $name
        );
        $name = (string)preg_replace('/(?<=\p{Ll}|\d)(\p{Lu})/u', '-$1', $name);
        $node = strtolower((string)preg_replace('/(\\\|\/|@|:|_)+/', '.', $name));
        $node = (string)preg_replace('/\.+/', '.', $node);

        return trim($node, '.');
    }
}
