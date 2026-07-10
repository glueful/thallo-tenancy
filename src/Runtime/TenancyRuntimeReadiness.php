<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness as TenantRuntimeReadinessContract;
use Thallo\Tenancy\System\SystemFlags;

/** SP1 readiness: full resolution when available, otherwise one unambiguous default tenant. */
final class TenancyRuntimeReadiness implements TenantRuntimeReadinessContract
{
    public function __construct(
        private readonly SystemFlags $flags,
        private readonly Connection $connection,
        private readonly ?TenantContextRunner $runner = null,
    ) {
    }

    public function isReady(ApplicationContext $context): bool
    {
        return $this->mode($context) !== self::MODE_NONE;
    }

    public function mode(ApplicationContext $context): string
    {
        if (!$this->flags->tenancyEnabled()) {
            return self::MODE_NONE;
        }

        $container = $context->getContainer();
        if ($container->has(FullTenantResolutionReadiness::class)) {
            $full = $container->get(FullTenantResolutionReadiness::class);
            if ($full instanceof FullTenantResolutionReadiness && $full->isReady($context)) {
                return self::MODE_FULL_RESOLUTION;
            }
        }

        if ($this->runner === null) {
            return self::MODE_NONE;
        }

        $defaultTenant = $this->flags->defaultTenantUuid();
        if ($defaultTenant === null || !$this->connection->getSchemaBuilder()->hasTable('tenants')) {
            return self::MODE_NONE;
        }

        $tenants = $this->connection->table('tenants')
            ->where('status', 'active')
            ->select(['uuid'])
            ->get();

        if (count($tenants) !== 1 || (string) ($tenants[0]['uuid'] ?? '') !== $defaultTenant) {
            return self::MODE_NONE;
        }

        return self::MODE_BOOTSTRAP_DEFAULT;
    }
}
