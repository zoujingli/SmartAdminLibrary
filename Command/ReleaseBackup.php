<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Di\Annotation\Inject;
use Library\Command\Concerns\SourceOnlyCommand;
use Library\Service\ReleaseDatabaseService;
use Symfony\Component\Console\Input\InputOption;

#[Command(name: 'xadmin:release:backup', description: 'Build release database schema and data snapshots')]
final class ReleaseBackup extends HyperfCommand
{
    use SourceOnlyCommand;

    #[Inject]
    protected ReleaseDatabaseService $service;

    public function configure(): void
    {
        $this->setDescription('Build release database schema and data snapshots')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview snapshot paths without writing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit report as JSON');
    }

    public function handle(): void
    {
        $report = $this->service->backup((bool)$this->input->getOption('dry-run'));
        if ((bool)$this->input->getOption('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        $this->line(($report['dry_run'] ? '[dry-run] ' : '') . 'Release database snapshot complete');
        $this->line('Schema: ' . $report['schema_path']);
        $this->line('Data: ' . $report['data_path']);
        $this->line('Schema tables: ' . $report['schema_tables']);
        $this->line('Data rows: ' . $report['data_rows']);
        $this->line('Backup tables: ' . implode(', ', $report['backup_tables']));
        $this->line('Ignore tables: ' . implode(', ', $report['ignore_tables']));
        if ($report['skipped_tables'] !== []) {
            $this->warn('Skipped tables: ' . implode(', ', $report['skipped_tables']));
        }
    }
}
