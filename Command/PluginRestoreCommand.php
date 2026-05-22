<?php

declare(strict_types=1);

namespace Library\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Di\Annotation\Inject;
use Library\Command\Concerns\SourceOnlyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Library\Command\Concerns\PluginCommandOutput;
use Library\Service\PluginManagerService;

#[Command(name: 'xadmin:plugin:restore', description: 'Restore a plugin backup ZIP file')]
final class PluginRestoreCommand extends HyperfCommand
{
    use PluginCommandOutput;
    use SourceOnlyCommand;

    #[Inject]
    protected PluginManagerService $service;

    public function configure(): void
    {
        $this->setDescription('Restore a plugin backup ZIP file')
            ->addArgument('backup', InputArgument::REQUIRED, 'Plugin backup ZIP path, URL, or default backup name')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'ZIP password')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files and allow destructive schema SQL')
            ->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Skip plugin migrations')
            ->addOption('no-sync', null, InputOption::VALUE_NONE, 'Skip menu/node sync')
            ->addOption('no-data', null, InputOption::VALUE_NONE, 'Restore code only and skip database snapshots')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit report as JSON');
    }

    public function handle(): void
    {
        $report = $this->service->restore(
            (string)$this->input->getArgument('backup'),
            $this->optionString('password'),
            (bool)$this->input->getOption('force'),
            !(bool)$this->input->getOption('no-migrate'),
            !(bool)$this->input->getOption('no-sync'),
            !(bool)$this->input->getOption('no-data'),
        );
        $this->outputPluginReport($report, (bool)$this->input->getOption('json'));
    }

    private function optionString(string $name): ?string
    {
        $value = $this->input->getOption($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
