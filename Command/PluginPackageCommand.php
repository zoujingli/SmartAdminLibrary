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

#[Command(name: 'xadmin:plugin:package', description: 'Package a source plugin directory into a ZIP archive')]
final class PluginPackageCommand extends HyperfCommand
{
    use PluginCommandOutput;
    use SourceOnlyCommand;

    #[Inject]
    protected PluginManagerService $service;

    public function configure(): void
    {
        $this->setDescription('Package a source plugin directory into a ZIP archive')
            ->addArgument('plugin', InputArgument::REQUIRED, 'Plugin name/code or directory')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output directory')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'ZIP password')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit report as JSON');
    }

    public function handle(): void
    {
        $report = $this->service->package(
            (string)$this->input->getArgument('plugin'),
            $this->optionString('output'),
            $this->optionString('password'),
        );
        $this->outputPluginReport($report, (bool)$this->input->getOption('json'));
    }

    private function optionString(string $name): ?string
    {
        $value = $this->input->getOption($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
