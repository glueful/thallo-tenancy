<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Cache\TenantHostCachePurger;

#[AsCommand(name: 'thallo:tenancy:domain', description: 'Manage tenant domains.')]
final class DomainManageCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'add|verify|list|enable|disable|remove');
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant uuid');
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain uuid');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Domain host');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $admin = $this->getService(TenantDomainAdministration::class);
            $cache = $this->getService(TenantHostCachePurger::class);
            $context = $this->getContext();
            $result = match ((string) $input->getArgument('action')) {
                'add' => $this->add($input, $admin, $cache),
                'list' => $admin->listDomains($context, $this->required($input, 'tenant')),
                'verify' => $this->domainMutation($input, $admin, $cache, 'verify'),
                'enable' => $this->domainMutation($input, $admin, $cache, 'enable'),
                'disable' => $this->domainMutation($input, $admin, $cache, 'disable'),
                'remove' => $this->domainMutation($input, $admin, $cache, 'remove'),
                default => throw new \InvalidArgumentException('Unknown domain action.'),
            };
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /** @return array<string,mixed> */
    private function add(
        InputInterface $input,
        TenantDomainAdministration $admin,
        TenantHostCachePurger $cache
    ): array {
        $tenant = $this->required($input, 'tenant');
        $result = $admin->addDomain($this->getContext(), $tenant, $this->required($input, 'host'));
        $cache->purgeForTenant($tenant);
        return $result;
    }

    /** @return array<string,mixed> */
    private function domainMutation(
        InputInterface $input,
        TenantDomainAdministration $admin,
        TenantHostCachePurger $cache,
        string $operation
    ): array {
        $uuid = $this->required($input, 'domain');
        $domain = $admin->getDomain($this->getContext(), $uuid);
        if ($domain === null) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
        $result = match ($operation) {
            'verify' => ['status' => $admin->verifyDomain($this->getContext(), $uuid)],
            'enable' => $this->done(fn () => $admin->enableDomain($this->getContext(), $uuid)),
            'disable' => $this->done(fn () => $admin->disableDomain($this->getContext(), $uuid)),
            'remove' => $this->done(fn () => $admin->removeDomain($this->getContext(), $uuid)),
            default => throw new \LogicException('Unknown domain mutation.'),
        };
        $cache->purgeForTenant($domain['tenant_uuid']);

        return $result;
    }

    private function required(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("--{$name} is required.");
        }
        return trim($value);
    }

    /** @param callable():void $operation @return array{ok:true} */
    private function done(callable $operation): array
    {
        $operation();
        return ['ok' => true];
    }
}
