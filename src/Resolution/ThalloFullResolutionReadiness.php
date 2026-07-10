<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Thallo\Tenancy\System\SystemFlags;

/** One authoritative predicate for Thallo's full tenant-resolution mode. */
final class ThalloFullResolutionReadiness implements FullTenantResolutionReadiness
{
    public function __construct(
        private readonly SystemFlags $flags,
        private readonly ?TenantDomainAdministration $domains,
    ) {
    }

    public function isReady(ApplicationContext $context): bool
    {
        if (
            !$this->flags->tenancyEnabled()
            || $this->flags->get('tenancy.resolution') !== 'full'
            || $this->domains === null
        ) {
            return false;
        }

        $defaultTenant = $this->flags->defaultTenantUuid();
        if ($defaultTenant === null) {
            return false;
        }

        $required = config($context, 'tenancy.public_origin.default_hosts', []);
        if (!is_array($required) || $required === []) {
            return false;
        }

        $active = [];
        foreach ($this->domains->listDomains($context, $defaultTenant) as $domain) {
            if (
                ($domain['verification_status'] ?? '') === 'verified'
                && ($domain['status'] ?? '') === 'active'
            ) {
                $active[] = strtolower(rtrim((string) ($domain['host'] ?? ''), '.'));
            }
        }

        foreach ($required as $host) {
            if (!is_string($host) || !in_array(strtolower(rtrim($host, '.')), $active, true)) {
                return false;
            }
        }

        return true;
    }
}
