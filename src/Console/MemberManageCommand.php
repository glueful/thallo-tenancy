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

#[AsCommand(name: 'thallo:tenancy:member', description: 'Manage tenant memberships.')]
final class MemberManageCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'add|set-role|remove|list');
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant uuid');
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'User uuid');
        $this->addOption('role', null, InputOption::VALUE_REQUIRED, 'Membership role');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $admin = $this->getService(TenantAdministration::class);
            $context = $this->getContext();
            $tenant = $this->required($input, 'tenant');
            $result = match ((string) $input->getArgument('action')) {
                'list' => $admin->listMembers($context, $tenant),
                'add' => $this->done(fn () => $admin->addMember(
                    $context,
                    $tenant,
                    $this->required($input, 'user'),
                    $this->required($input, 'role')
                )),
                'set-role' => $this->done(fn () => $admin->setMemberRole(
                    $context,
                    $tenant,
                    $this->required($input, 'user'),
                    $this->required($input, 'role')
                )),
                'remove' => $this->done(fn () => $admin->removeMember(
                    $context,
                    $tenant,
                    $this->required($input, 'user')
                )),
                default => throw new \InvalidArgumentException('Unknown membership action.'),
            };
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
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
