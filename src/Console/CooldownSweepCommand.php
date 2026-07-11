<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Glueful\Queue\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Purge\CooldownSweepJob;

#[AsCommand(name: 'thallo:tenancy:hosts:sweep', description: 'Queue an expired host-cooldown sweep.')]
final class CooldownSweepCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        QueueManager::setContext($context);
        $uuid = QueueManager::createDefault()->push(CooldownSweepJob::class, [], 'tenancy-maintenance');
        $this->success("Queued host-cooldown sweep {$uuid}.");
        return self::SUCCESS;
    }
}
