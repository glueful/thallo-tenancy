<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\TenancyEnablement;

#[AsCommand(name: 'thallo:tenancy:disable', description: 'Disable tenant scoping without narrowing the schema.')]
final class TenancyDisableCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $status = $this->getService(TenancyEnablement::class)->disable();
            $this->line((string) json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($status->reloading) {
                $this->warning('Re-run this command in a fresh process to verify disabled-widened mode.');
            }

            return $status->step === EnablementStep::FAILED ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
