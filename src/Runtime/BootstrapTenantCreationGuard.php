<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\Enablement\EnablementException;

/** Prevents a second tenant until SP2 supplies full request-time resolution. */
final class BootstrapTenantCreationGuard
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TenantRuntimeReadiness $readiness,
    ) {
    }

    public function assertCanCreateTenant(): void
    {
        if ($this->readiness->mode($this->context) === TenantRuntimeReadiness::MODE_BOOTSTRAP_DEFAULT) {
            throw new EnablementException(
                'A second tenant cannot be created while single-tenant bootstrap resolution is active. '
                . 'Enable full multi-tenant resolution first.',
            );
        }
    }
}
