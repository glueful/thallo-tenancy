<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\TenancyEnablement;

#[AsCommand(name: 'thallo:tenancy:enable', description: 'Advance the multi-tenancy enablement flow.')]
final class TenancyEnableCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('slug', null, InputOption::VALUE_REQUIRED, 'First tenant slug.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'First tenant name.');
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Owner user UUID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $enablement = $this->getService(TenancyEnablement::class);
        $before = $enablement->status()->step;

        if ($before === EnablementStep::RELOADING || $before === EnablementStep::FINALIZING) {
            $status = $enablement->finalize();
        } elseif ($before === EnablementStep::AWAITING_CONFIRM && $this->hasConfirmInput($input)) {
            $status = $enablement->confirm(
                (string) $input->getOption('slug'),
                (string) $input->getOption('name'),
                (string) $input->getOption('owner'),
            );
        } else {
            $status = $enablement->begin();
        }

        $this->line((string) json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($status->step->needsFreshBoot() && $status->step !== $before) {
            $this->warning(
                'Reached ' . $status->step->value . '. Re-run this command in a fresh process to continue.',
            );
        } elseif ($status->step === EnablementStep::AWAITING_CONFIRM && !$this->hasConfirmInput($input)) {
            $this->warning('Re-run with --slug, --name, and --owner to confirm the retrofit.');
        } elseif ($status->step === EnablementStep::ON) {
            $this->success('Multi-tenancy is now ON.');
        }

        return $status->step === EnablementStep::FAILED ? self::FAILURE : self::SUCCESS;
    }

    private function hasConfirmInput(InputInterface $input): bool
    {
        return trim((string) $input->getOption('slug')) !== ''
            && trim((string) $input->getOption('name')) !== ''
            && trim((string) $input->getOption('owner')) !== '';
    }
}
