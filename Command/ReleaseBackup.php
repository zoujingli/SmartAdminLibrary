<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Di\Annotation\Inject;
use Library\Service\ReleaseDatabaseService;
use Symfony\Component\Console\Input\InputOption;

#[Command(name: 'xadmin:release:backup', description: 'Build release install package or runtime database backup')]
final class ReleaseBackup extends HyperfCommand
{
    #[Inject]
    protected ReleaseDatabaseService $service;

    public function configure(): void
    {
        $this->setDescription('Build release install package or runtime database backup')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Build install package under storage/extra/release')
            ->addOption('with-data', null, InputOption::VALUE_NONE, 'Backup all table data; not allowed with --install')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview backup paths without writing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit report as JSON');
    }

    public function handle(): void
    {
        $report = $this->service->backup(
            (bool)$this->input->getOption('with-data'),
            (bool)$this->input->getOption('install'),
            (bool)$this->input->getOption('dry-run')
        );

        if ((bool)$this->input->getOption('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        $this->line(($report['dry_run'] ? '[dry-run] ' : '') . ($report['install'] ? 'Release install package complete' : 'Release runtime backup complete'));
        $this->line('Mode: ' . ($report['install'] ? 'install' : 'backup'));
        $this->line('With data: ' . ($report['with_data'] ? 'yes' : 'no'));
        if (!$report['install']) {
            $this->line('Backup ID: ' . ($report['backup_id'] ?? ''));
        }
        $this->line('Path: ' . $report['backup_path']);
        $this->line('Schema: ' . $report['schema_path']);
        $this->line('Data: ' . $report['data_path']);
        $this->line('Meta: ' . $report['meta_path']);
        $this->line('Schema tables: ' . $report['schema_tables']);
        $this->line('Data rows: ' . $report['data_rows']);
        $this->line('Data tables: ' . implode(', ', $report['data_tables']));
        $this->line('Necessary tables: ' . implode(', ', $report['backup_tables']));
        if ($report['skipped_tables'] !== []) {
            $this->warn('Skipped tables: ' . implode(', ', $report['skipped_tables']));
        }
    }
}
