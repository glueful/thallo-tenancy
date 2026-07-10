<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\TenancyEnablement;

#[AsCommand(name: 'thallo:tenancy:status', description: 'Show multi-tenancy enablement status.')]
final class TenancyStatusCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->getService(TenancyEnablement::class)->status();
        $this->line((string) json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($status->step === EnablementStep::FINALIZING) {
            $this->warning('A crash-recovery finalization is pending; run thallo:tenancy:enable.');
        } elseif ($status->step->needsFreshBoot()) {
            $this->warning('A fresh process is required to continue enablement.');
        }

        return $status->step === EnablementStep::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
