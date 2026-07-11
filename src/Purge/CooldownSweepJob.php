<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Queue\Job;

final class CooldownSweepJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('CooldownSweepJob requires an ApplicationContext.');
        }
        $context->getContainer()->get(ReleasedHostRepository::class)
            ->pruneExpired($context, gmdate('Y-m-d H:i:s'));
    }
}
