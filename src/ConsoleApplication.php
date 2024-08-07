<?php
declare(strict_types=1);

namespace IfCastle\Console;

use IfCastle\Application\ApplicationAbstract;
use IfCastle\ServiceManager\DescriptorRepositoryInterface;

class ConsoleApplication            extends ApplicationAbstract
{
    protected function engineStartAfter(): void
    {
        (new SymfonyApplication(
            $this->systemEnvironment,
            $this->systemEnvironment->resolveDependency(DescriptorRepositoryInterface::class)
        ))->run();
    }
}