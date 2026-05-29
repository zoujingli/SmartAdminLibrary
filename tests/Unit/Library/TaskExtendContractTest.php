<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class TaskExtendContractTest extends TestCase
{
    public function testCommonTaskStandardKeepsExpectedContract(): void
    {
        $root = dirname(__DIR__, 3);
        $common = $this->source($root, ['plugin/Library/common.php', 'common.php']);
        $taskExtend = $this->source($root, ['plugin/Library/Helper/TaskExtend.php', 'Helper/TaskExtend.php']);
        $taskStdout = $this->source($root, ['plugin/Library/Helper/TaskStdout.php', 'Helper/TaskStdout.php']);
        $taskController = $this->optionalSource($root, ['plugin/System/src/Controller/TaskController.php']);
        $systemDoc = $this->optionalSource($root, ['docs/接口参考/系统数据接口.md']);

        $this->assertStringContainsString('function _task(string $name, Closure $callback, int $locktime = 300): string', $common);
        $this->assertStringContainsString('TaskExtend::class', $common);
        $this->assertStringContainsString('ConfigInterface $config', $taskExtend);
        $this->assertStringContainsString("public const STATUS_RUNNING = 'lock'", $taskExtend);
        $this->assertStringContainsString("public const STATUS_DONE = 'done'", $taskExtend);
        $this->assertStringContainsString("public const STATUS_FAILED = 'fail'", $taskExtend);
        $this->assertStringContainsString("public const STATUS_UNKNOWN = 'unknown'", $taskExtend);
        $this->assertStringContainsString('private const TTL = 3600', $taskExtend);
        $this->assertStringContainsString('private const LOG_LIMIT = 50', $taskExtend);
        $this->assertStringContainsString("sprintf('tenant:%d:%s'", $taskExtend);
        $this->assertStringContainsString("['NX', 'EX' => max(1, \$locktime)]", $taskExtend);
        $this->assertStringContainsString('return $currentTaskId', $taskExtend);
        $this->assertStringContainsString('避免前端首轮轮询早于协程启动时误判 unknown', $taskExtend);
        $this->assertStringContainsString('Coroutine::create', $taskExtend);
        $this->assertStringContainsString('writeMeta($taskId, $tenantId, $name)', $taskExtend);
        $this->assertStringContainsString("'tenant_id' => \$tenantId", $taskExtend);
        $this->assertStringContainsString("TenantContext::get()", $taskExtend);
        $this->assertStringContainsString("'stat' => self::STATUS_UNKNOWN", $taskExtend);
        $this->assertStringContainsString("'progress' => is_array(\$progress) ? \$progress : null", $taskExtend);
        $this->assertStringContainsString("'logs' => array_values", $taskExtend);
        $this->assertStringContainsString("'current' => \$current", $taskStdout);
        $this->assertStringContainsString("'total' => \$total", $taskStdout);
        $this->assertStringContainsString("'percent' => \$percent", $taskStdout);
        $this->assertStringContainsString("'message' => trim(\$message)", $taskStdout);
        $this->assertStringContainsString("'updated_at' => date('Y-m-d H:i:s')", $taskStdout);
        $this->assertStringContainsString('lTrim($this->logKey, -$this->maxLogs, -1)', $taskStdout);
        if ($taskController !== '') {
            $this->assertStringContainsString("Controller(prefix: 'system/task')", $taskController);
            $this->assertStringContainsString("GetMapping(path: 'status')", $taskController);
            $this->assertStringContainsString("code: 'system.task.status'", $taskController);
        }
        if ($systemDoc !== '') {
            $this->assertStringContainsString('/system/task/status', $systemDoc);
            $this->assertStringContainsString('current/total/percent/message/updated_at', $systemDoc);
        }
    }

    /**
     * @param array<int, string> $paths
     */
    private function source(string $root, array $paths): string
    {
        $source = $this->optionalSource($root, $paths);
        $this->assertNotSame('', $source, sprintf('Source file must exist: %s', implode(' or ', $paths)));

        return $source;
    }

    /**
     * @param array<int, string> $paths
     */
    private function optionalSource(string $root, array $paths): string
    {
        foreach ($paths as $path) {
            $file = $root . '/' . $path;
            if (is_file($file)) {
                return (string)file_get_contents($file);
            }
        }

        return '';
    }
}
