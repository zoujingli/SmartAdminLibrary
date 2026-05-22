<?php

declare(strict_types=1);

namespace System\Model;

use Hyperf\Database\Model\Builder;
use Library\Interfaces\UserModelInterface;

/** SmartAdminLibrary 独立分析时使用的 System 模型桩，仅用于解析跨插件默认类型。 */
class SystemUser implements UserModelInterface
{
    public static function query(): Builder
    {
        throw new \LogicException('PHPStan stub only.');
    }

    public static function whereHas(string $relation, callable $callback): Builder
    {
        throw new \LogicException('PHPStan stub only.');
    }

    public function getId(): int { return 0; }
    public function getName(): string { return ''; }
    public function isSuper(): bool { return false; }
    public function getPermissions(): array { return []; }
    public function hasPermission(string $permission): bool { return false; }
    public function toArray(): array { return []; }
}

class SystemTenant
{
    public int $status = 0;
    public string $expired_at = '';
    public static function query(): Builder
    {
        throw new \LogicException('PHPStan stub only.');
    }
}

class SystemDept
{
    public static function query(): Builder
    {
        throw new \LogicException('PHPStan stub only.');
    }
}

class SystemLogsAction {}

class SystemLogsChange {}
