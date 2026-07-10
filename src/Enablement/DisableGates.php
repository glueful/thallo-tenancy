<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Tenancy\Contracts\StarterCoverageCheck;
use Thallo\Tenancy\Resolution\ResolutionActivationStep;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;
use Thallo\Tenancy\System\SystemFlags;

final class DisableGates
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly ResolutionActivationStore $resolution,
        private readonly ?TenantAdministration $tenants = null,
        private readonly ?StarterCoverageCheck $coverage = null,
        private readonly ?TenantContextRunner $runner = null,
    ) {
    }

    public function assertCanDisable(): void
    {
        if ($this->tenants === null || count($this->tenants->listTenants($this->context)) !== 1) {
            throw new EnablementException('Disable requires exactly one tenant.');
        }
        if ($this->resolution->step() === ResolutionActivationStep::FULL) {
            throw new EnablementException(
                'Deactivate full resolution first: php glueful thallo:tenancy:resolution:deactivate'
            );
        }
        $defaultTenantUuid = $this->flags->defaultTenantUuid();
        if ($defaultTenantUuid === null) {
            throw new EnablementException('Disable requires a default tenant pointer.');
        }
        if ($this->coverage === null || $this->runner === null) {
            throw new EnablementException('Starter coverage checking is unavailable.');
        }
        $violations = $this->runner->runAsTenant(
            $defaultTenantUuid,
            fn(): array => $this->coverage->coverageViolations(),
        );
        if ($violations !== []) {
            throw new EnablementException(
                'Starter definitions must be synchronized before disable: '
                . implode('; ', $violations)
                . '. Run php glueful thallo:tenant:sync --all.'
            );
        }
    }
}
