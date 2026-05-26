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

namespace Plugin\Project\Model;

use Hyperf\Database\Model\Builder;
use Library\CoreModel;
use Library\Interfaces\UserModelInterface;

/** SmartAdminLibrary 独立单测使用的 Project 账号桩，只验证 LoginService 不调用 toArray() 的租户边界。 */
class ProjectAccount extends CoreModel implements UserModelInterface
{
    protected ?string $table = 'project_account';
    protected array $fillable = ['id', 'tenant_id', 'username', 'nickname', 'status'];

    public static function query(): Builder
    {
        throw new \LogicException('PHPStan stub only.');
    }

    public function getId(): int { return (int) ($this->getAttribute('id') ?? 0); }
    public function getName(): string { return (string) ($this->getAttribute('username') ?? ''); }
    public function isSuper(): bool { return false; }
    public function getPermissions(): array { return []; }
    public function hasPermission(string $permission): bool { return false; }
    public function toArray(): array
    {
        throw new \LogicException('ProjectAccount::toArray() must not be called before tenant context is ready.');
    }
}
