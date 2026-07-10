<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Contracts\TenantStarterSync;

#[AsCommand(name: 'thallo:tenant:blocks:sync', description: 'Synchronize starter block definitions for tenants.')]
final class TenantBlockSyncCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::OPTIONAL, 'Tenant uuid');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Synchronize all active tenants.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $uuid = $input->getArgument('uuid');
            $all = (bool) $input->getOption('all');
            if ($all === (is_string($uuid) && $uuid !== '')) {
                throw new \InvalidArgumentException('Supply exactly one tenant uuid or --all.');
            }
            $sync = $this->getService(TenantStarterSync::class);
            $report = $all
                ? $sync->syncAll('block_type')
                : $sync->sync((string) $uuid, 'block_type');
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
