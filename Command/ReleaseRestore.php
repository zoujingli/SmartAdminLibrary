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
use Library\Service\ReleaseDatabaseService;
use Symfony\Component\Console\Input\InputOption;

#[Command(name: 'xadmin:release:restore', description: 'Restore release-managed data from a runtime release backup')]
final class ReleaseRestore extends HyperfCommand
{
    #[Inject]
    protected ReleaseDatabaseService $service;

    public function configure(): void
    {
        $this->setDescription('Restore release-managed data from a runtime release backup')
            ->addOption('backup', null, InputOption::VALUE_REQUIRED, 'Backup id under runtime/release/backups')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview restore without writing to database')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit report as JSON');
    }

    public function handle(): void
    {
        $backupId = (string)$this->input->getOption('backup');
        if ($backupId === '') {
            $this->error('Missing required --backup option.');
            exit(1);
        }

        $report = $this->service->restore($backupId, (bool)$this->input->getOption('dry-run'));
        if ((bool)$this->input->getOption('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        $this->line(($report['dry_run'] ? '[dry-run] ' : '') . 'Release database restore complete');
        $this->line('Backup: ' . $report['backup_id']);
        $this->line('Path: ' . $report['backup_path']);
        $this->line('Backup tables: ' . implode(', ', $report['backup_tables']));
        if (!$report['dry_run']) {
            $this->line('Restored rows: ' . $report['data_rows']);
        }
        if ($report['skipped_tables'] !== []) {
            $this->warn('Skipped tables: ' . implode(', ', $report['skipped_tables']));
        }
    }
}
