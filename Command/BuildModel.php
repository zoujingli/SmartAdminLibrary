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
use Hyperf\Database\Commands\Ast\ModelRewriteConnectionVisitor;
use Hyperf\Database\Commands\Ast\ModelUpdateVisitor;
use Hyperf\Database\Commands\ModelCommand;
use Hyperf\Database\Commands\ModelData;
use Hyperf\Database\Commands\ModelOption;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Stringable\Str;
use Library\Command\Concerns\SourceOnlyCommand;
use Library\Constants\System;
use Library\Exception\CoreResponseException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;

use function Hyperf\Support\make;

#[Command(name: 'xadmin:build:model', description: '搜索并同步模型字段描述')]
class BuildModel extends ModelCommand
{
    use SourceOnlyCommand;

    public function configure(): void
    {
        $this->setName('xadmin:build:model');
        $this->setDescription('搜索并同步模型字段描述.');
        $this->addOption('pool', 'p', InputOption::VALUE_OPTIONAL, 'Which connection pool you want the Model use.', 'default');
        $this->addOption('path', null, InputOption::VALUE_OPTIONAL, 'The path that you want the Model file to be generated.');
        $this->addOption('force-casts', 'F', InputOption::VALUE_NONE, 'Whether force generate the casts for model.');
        $this->addOption('prefix', 'P', InputOption::VALUE_OPTIONAL, 'What prefix that you want the Model set.');
        $this->addOption('inheritance', 'i', InputOption::VALUE_OPTIONAL, 'The inheritance that you want the Model extends.');
        $this->addOption('uses', 'U', InputOption::VALUE_OPTIONAL, 'The default class uses of the Model.');
        $this->addOption('refresh-fillable', 'R', InputOption::VALUE_NONE, 'Whether generate fillable argument for model.');
        $this->addOption('table-mapping', 'M', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Table mappings for model.');
        $this->addOption('ignore-tables', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Ignore tables for creating models.');
        $this->addOption('with-comments', null, InputOption::VALUE_NONE, 'Whether generate the property comments for model.');
        $this->addOption('with-ide', null, InputOption::VALUE_NONE, 'Whether generate the ide file for model.');
        $this->addOption('visitors', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Custom visitors for ast traverser.');
        $this->addOption('property-case', null, InputOption::VALUE_OPTIONAL, 'Which property case you want use, 0: snake case, 1: camel case.');
    }

    public function handle(): void
    {
        // 禁止在 Phar 环境运行
        if (System::isPharMode()) {
            throw new CoreResponseException('不支持在 Phar 环境运行！');
        }
        // 模型生成器配置
        $pool = $this->input->getOption('pool');
        $option = new ModelOption();
        $option->setPool($pool)
            ->setPrefix($this->getOption('prefix', 'prefix', $pool, ''))
            ->setInheritance($this->getOption('inheritance', 'commands.gen:model.inheritance', $pool, 'Model'))
            ->setUses($this->getOption('uses', 'commands.gen:model.uses', $pool, 'Hyperf\DbConnection\Model\Model'))
            ->setForceCasts($this->getOption('force-casts', 'commands.gen:model.force_casts', $pool, false))
            ->setRefreshFillable($this->getOption('refresh-fillable', 'commands.gen:model.refresh_fillable', $pool, false))
            ->setTableMapping($this->getOption('table-mapping', 'commands.gen:model.table_mapping', $pool, []))
            ->setIgnoreTables($this->getOption('ignore-tables', 'commands.gen:model.ignore_tables', $pool, []))
            ->setWithComments($this->getOption('with-comments', 'commands.gen:model.with_comments', $pool, false))
            ->setWithIde($this->getOption('with-ide', 'commands.gen:model.with_ide', $pool, false))
            ->setVisitors($this->getOption('visitors', 'commands.gen:model.visitors', $pool, []))
            ->setPropertyCase($this->getOption('property-case', 'commands.gen:model.property_case', $pool));

        // 创建文件扫描器
        $dirs = ['app', 'plugin'];
        $finder = Finder::create()->path('/(^|\/)Model(\/|$)/');
        $finder->depth('>=1')->filter(fn (\SplFileInfo $file) => !$file->isLink());
        $finder->in(array_filter($dirs, fn ($dir) => file_exists($dir) && is_dir($dir)));

        foreach ($finder->name('*.php')->files() as $file) {
            if (count($classes = $this->extractClassNames($file->getContents())) > 0) {
                try {
                    $table = make($classes[0])->getTable();
                    $option->setPath(mb_substr(dirname($file->getPathname()), mb_strlen(BASE_PATH) + 1));
                    $option->setTableMapping(["{$table}:{$classes[0]}"]);
                    $this->extractModelFile($table, $classes[0], $file->getPathname(), $option);
                } catch (\Error|QueryException|\RuntimeException|\Throwable $exception) {
                    $this->output->writeln("<error>{$exception->getMessage()}</error>");
                }
            }
        }
    }

    private function extractModelFile(string $table, string $class, string $file, ModelOption $option): void
    {
        $table = Str::replaceFirst($option->getPrefix(), '', $table);
        $databaseName = Str::contains($table, '.') ? Str::before($table, '.') : null;
        $columns = $this->formatColumns($this->getSchemaBuilder($option->getPool())->getColumnTypeListing(Str::after($table, '.'), $databaseName));
        if (empty($columns)) {
            $this->output?->error(sprintf('Query columns empty, maybe is table `%s` does not exist.You can check it in database.', $table));
        }

        if (!file_exists($file)) {
            $this->mkdir($file);
            file_put_contents($file, $this->buildClass($table, $class, $option));
        }

        $columns = $this->getColumns($class, $columns, $option->isForceCasts());
        $traverser = new NodeTraverser();
        $traverser->addVisitor(make(ModelUpdateVisitor::class, [
            'class' => $class, 'columns' => $columns, 'option' => $option,
        ]));
        $traverser->addVisitor(make(ModelRewriteConnectionVisitor::class, [$class, $option->getPool()]));
        $data = make(ModelData::class, ['class' => $class, 'columns' => $columns]);
        foreach ($option->getVisitors() as $visitorClass) {
            $traverser->addVisitor(make($visitorClass, [$option, $data]));
        }

        $traverser->addVisitor(new CloningVisitor());

        $originStmts = $this->astParser->parse(file_get_contents($file));
        $originTokens = $this->astParser->getTokens();
        $newStmts = $traverser->traverse($originStmts);
        $code = $this->printer->printFormatPreserving($newStmts, $originStmts, $originTokens);

        file_put_contents($file, $code);
        $this->output->writeln(sprintf('<info>Model %s was created.</info>', $class));

        if ($option->isWithIde()) {
            $this->generateIDE($code, $option, $data);
        }
    }

    /**
     * 提取 PHP 代码中的类名.
     *
     * @param string $code PHP 代码
     * @return array 返回类名数组
     */
    private function extractClassNames(string $code): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            public array $classes = [];

            private string $namespace = '';

            public function enterNode(Node $node): void
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name->toString();
                }

                if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                    $className = $node->name->toString();
                    $this->classes[] = $this->namespace ? "{$this->namespace}\\{$className}" : $className;
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($code));
        return $visitor->classes;
    }
}
