<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Cache\Driver;

use Hyperf\Cache\Driver\FileSystemDriver;
use Psr\Container\ContainerInterface;

final class RuntimeFileSystemDriver extends FileSystemDriver
{
    public function __construct(ContainerInterface $container, array $config)
    {
        $this->storePath = rtrim((string)($config['store_path'] ?? runpath('runtime/cache')), '/\\');
        parent::__construct($container, $config);
    }
}
