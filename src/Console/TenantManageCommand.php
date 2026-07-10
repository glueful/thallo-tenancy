<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Runtime\BootstrapTenantCreationGuard;
use Thallo\Tenancy\Contracts\TenantSeedActivator;

#[AsCommand(name: 'thallo:tenancy:tenant', description: 'Manage tenants.')]
final class TenantManageCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'create|list|suspend|reactivate');
        $this->addOption('uuid', null, InputOption::VALUE_REQUIRED, 'Tenant uuid');
        $this->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Tenant slug');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Tenant name');
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Owner user uuid');
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $admin = $this->getService(TenantAdministration::class);
            $context = $this->getContext();
            $result = match ((string) $input->getArgument('action')) {
                'create' => $this->create($input, $admin),
                'list' => $admin->listTenants($context, $this->option($input, 'status')),
                'suspend' => $this->done(function () use ($admin, $input, $context): void {
                    $admin->suspend($context, $this->required($input, 'uuid'));
                }),
                'reactivate' => $this->done(function () use ($admin, $input, $context): void {
                    $admin->reactivate($context, $this->required($input, 'uuid'));
                }),
                default => throw new \InvalidArgumentException('Unknown tenant action.'),
            };
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /** @return array<string,string> */
    private function create(InputInterface $input, TenantAdministration $admin): array
    {
        $seeder = $this->getService(TenantSeedActivator::class);
        $this->getService(BootstrapTenantCreationGuard::class)->assertCanCreateTenant();
        $uuid = $admin->create(
            $this->getContext(),
            $this->required($input, 'slug'),
            $this->required($input, 'name'),
            $this->required($input, 'owner')
        );
        $seeder->seedAndActivate(
            $uuid,
            $this->required($input, 'owner'),
        );

        return ['uuid' => $uuid, 'status' => 'active'];
    }

    private function required(InputInterface $input, string $name): string
    {
        $value = $this->option($input, $name);
        if ($value === null) {
            throw new \InvalidArgumentException("--{$name} is required.");
        }
        return $value;
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param callable():void $operation @return array{ok:true} */
    private function done(callable $operation): array
    {
        $operation();
        return ['ok' => true];
    }
}
