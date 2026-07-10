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
        if ($this->readiness->mode($this->context) !== TenantRuntimeReadiness::MODE_FULL_RESOLUTION) {
            throw new EnablementException(
                'Tenant creation requires full multi-tenant resolution. Run '
                . '`thallo:tenancy:resolution:activate` first.',
            );
        }
    }
}
