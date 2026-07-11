<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Purge\PurgeCoordinator;

#[AsCommand(name: 'thallo:tenancy:purge:recover', description: 'Redispatch recoverable workspace purge runs.')]
final class PurgeRecoveryCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->getService(PurgeCoordinator::class)->recover();
        $this->success("Dispatched {$count} recoverable purge run(s).");
        return self::SUCCESS;
    }
}
