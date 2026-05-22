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

#[Command(name: 'xadmin:plugin:install', description: 'Install a plugin ZIP file or URL into plugin directory')]
final class PluginInstallCommand extends HyperfCommand
{
    use PluginCommandOutput;
    use SourceOnlyCommand;

    #[Inject]
    protected PluginManagerService $service;

    public function configure(): void
    {
        $this->setDescription('Install a plugin ZIP file or URL into plugin directory')
            ->addArgument('source', InputArgument::REQUIRED, 'Plugin ZIP path or URL')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'ZIP password')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing plugin directory')
            ->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Skip plugin migrations')
            ->addOption('no-sync', null, InputOption::VALUE_NONE, 'Skip menu/node sync')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit report as JSON');
    }

    public function handle(): void
    {
        $report = $this->service->install(
            (string)$this->input->getArgument('source'),
            $this->optionString('password'),
            (bool)$this->input->getOption('force'),
            !(bool)$this->input->getOption('no-migrate'),
            !(bool)$this->input->getOption('no-sync'),
        );
        $this->outputPluginReport($report, (bool)$this->input->getOption('json'));
    }

    private function optionString(string $name): ?string
    {
        $value = $this->input->getOption($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
