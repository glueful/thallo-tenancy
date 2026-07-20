<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Adoption;

use Glueful\Bootstrap\ApplicationContext;

/**
 * A pack's seam into enable-time tenant adoption: after the SP1 schema retrofit widens every owned
 * table and provisions the default tenant, each registered contributor gets one chance to adopt
 * sentinel/singleton rows into that tenant (data a plain additive-column retrofit cannot infer).
 *
 * Invoked by {@see \Thallo\Tenancy\Enablement\TenancyEnablement::confirm()} inside the same
 * RETROFITTING try that runs the schema retrofit — a throwing contributor fails the whole step the
 * same way a failing retrofit does (recordFailure(RETROFITTING), resumable via retry()).
 */
interface AdoptionContributor
{
    /** Stable identity for duplicate-registration detection. */
    public function id(): string;

    /**
     * Tenant tables this contributor owns. {@see \Thallo\Tenancy\Enablement\FinalizationProbe}
     * verifies each is registered with the tenancy backstop before allowing ON.
     *
     * @return list<string>
     */
    public function tables(): array;

    /**
     * Adopt sentinel rows into $tenantUuid. Runs as trusted system work (no tenant scoping) during
     * RETROFITTING, after the schema has been widened and the default tenant provisioned.
     */
    public function adopt(ApplicationContext $context, string $tenantUuid): void;
}
