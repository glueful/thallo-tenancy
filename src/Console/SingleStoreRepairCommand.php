<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

#[AsCommand(
    name: 'thallo:tenancy:single-store:repair',
    description: 'Establish or repair the tenant identity used by a single-store installation.',
)]
final class SingleStoreRepairCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Owner user uuid.');
        $this->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Tenant slug.', 'default');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Tenant name.', 'Default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $owner = trim((string) $input->getOption('owner'));
        if ($owner === '') {
            $this->error('The --owner option is required.');
            return self::FAILURE;
        }

        if ($this->getService(UserRepository::class)->findByUuid($owner) === null) {
            $this->error("Owner user '{$owner}' does not exist.");
            return self::FAILURE;
        }

        try {
            $tenantUuid = $this->getService(SingleStoreTenant::class)->ensure(
                trim((string) $input->getOption('slug')),
                trim((string) $input->getOption('name')),
                $owner,
            );
            $this->success("Single-store tenant established: {$tenantUuid}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
