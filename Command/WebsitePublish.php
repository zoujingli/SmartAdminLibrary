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
use Library\Support\FrontendPublisher;
use Symfony\Component\Console\Input\InputOption;

/**
 * 发布前端静态资源到 public 目录。
 *
 * 源码模式读取 web/dist，Phar 模式读取包内 storage/extra/web-dist.zip；_app.config.js 始终由中间件动态生成。
 */
#[Command(name: 'xadmin:website:publish', description: '发布前端静态资源到 public 目录，自动保留动态 _app.config.js')]
final class WebsitePublish extends HyperfCommand
{
    public function configure(): void
    {
        $this->setDescription('发布前端静态资源到 public 目录，自动保留动态 _app.config.js')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '仅打印将要执行的操作，不实际执行')
            ->addOption('clean', null, InputOption::VALUE_NONE, '清理已发布的静态资源');
    }

    public function handle(): void
    {
        $dryRun = (bool)$this->input->getOption('dry-run');
        $clean = (bool)$this->input->getOption('clean');

        if ($clean) {
            $count = FrontendPublisher::clean($dryRun, function (string $message): void {
                $this->line('  ' . $message);
            });
            $this->info(sprintf('%s清理完成，共处理 %d 个条目', $dryRun ? '[dry-run] ' : '', $count));
            return;
        }

        $this->info($dryRun ? '[dry-run] 预览发布操作...' : '开始发布前端静态资源...');
        $count = FrontendPublisher::publish($dryRun, function (string $message): void {
            $this->line('  ' . $message);
        });
        $this->info(sprintf('%s发布完成，共 %d 个文件，_app.config.js 保留动态生成', $dryRun ? '[dry-run] ' : '', $count));
    }
}
