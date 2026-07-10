<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Contracts\TenantSeedRepair;

#[AsCommand(name: 'thallo:tenant:seed', description: 'Repair and activate a provisioning tenant starter surface.')]
final class TenantSeedCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'Tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->getService(TenantSeedRepair::class)->repair((string) $input->getArgument('uuid'));
            $this->success('Tenant starter surface is complete.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
