<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Reverification;

use Glueful\Queue\Job;

/** Queue entry point for the lease-protected global domain re-verification sweep. */
final class DomainReverificationSweepJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if ($context === null) {
            throw new \RuntimeException('Domain re-verification requires an application context.');
        }
        if (!(bool) config($context, 'tenancy.domains.reverification.enabled', true)) {
            return;
        }

        app($context, DomainReverificationSweepLock::class)->run(function () use ($context): void {
            $errors = app($context, DomainReverificationSweep::class)->run($context);
            if ($errors !== []) {
                throw new \RuntimeException(
                    'Domain re-verification failed for: ' . implode(', ', $errors)
                );
            }
        });
    }
}
