<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Users\Repositories\UserRepository;
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
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'The owner: an account email or uuid.');
        $this->addOption('retry', null, InputOption::VALUE_NONE, 'Resume a failed run from the step that failed.');
        $this->addOption('cancel', null, InputOption::VALUE_NONE, 'Abandon a run that has not reached the retrofit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $enablement = $this->getService(TenancyEnablement::class);
        $current = $enablement->status();

        if ((bool) $input->getOption('cancel')) {
            $status = $enablement->cancel();
            $this->line((string) json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->success('Enablement cancelled; multi-tenancy stays off.');
            return self::SUCCESS;
        }
        if ($current->step === EnablementStep::FAILED) {
            if (!(bool) $input->getOption('retry')) {
                $this->line((string) json_encode($current->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->error(
                    'Enablement failed: ' . ($current->failure ?? 'no reason was recorded') . '. Fix the cause, then '
                    . 're-run with --retry to resume from the step that failed, or --cancel to abandon it.',
                );
                return self::FAILURE;
            }
            $enablement->retry();
        }
        $before = $enablement->status()->step;

        if ($before === EnablementStep::RELOADING || $before === EnablementStep::FINALIZING) {
            $status = $enablement->finalize();
        } elseif ($before === EnablementStep::AWAITING_CONFIRM && $this->hasConfirmInput($input)) {
            $owner = $this->ownerUuid(trim((string) $input->getOption('owner')));
            if ($owner === null) {
                return self::FAILURE;
            }
            $status = $enablement->confirm(
                (string) $input->getOption('slug'),
                (string) $input->getOption('name'),
                $owner,
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

    /** The owner's uuid, from an email or a uuid; null (after saying why) when no account matches. */
    private function ownerUuid(string $owner): ?string
    {
        $users = $this->getService(UserRepository::class);
        $user = str_contains($owner, '@') ? $users->findByEmail($owner) : $users->findByUuid($owner);
        $uuid = is_array($user) ? (string) ($user['uuid'] ?? '') : '';
        if ($uuid === '') {
            $this->error("No account matches --owner {$owner}. Give the email or uuid of an existing account.");
            return null;
        }
        return $uuid;
    }

    private function hasConfirmInput(InputInterface $input): bool
    {
        return trim((string) $input->getOption('slug')) !== ''
            && trim((string) $input->getOption('name')) !== ''
            && trim((string) $input->getOption('owner')) !== '';
    }
}
