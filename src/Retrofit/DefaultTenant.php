<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Operation-scoped default-tenant provisioning for the enable-time retrofit.
 *
 * Delegates to the canonical single-store provisioner, which talks only to the neutral tenancy
 * contracts and never to concrete Tenant/TenantMembership models.
 *
 * Two invariants:
 *  - OPERATION-SCOPED IDENTITY: the tenant uuid is generated ONCE and persisted as
 *    `tenancy.provisioning_tenant_uuid` before provisioning. A crash-then-retry reuses that same
 *    intended uuid (provisionDefault is idempotent by uuid), so recovery never mints a second
 *    tenant or falls back to a bare slug.
 *  - PRE-EXISTING BLOCK: a fresh enablement that finds tenant rows already present (and no
 *    provisioning uuid yet recorded) throws {@see PreexistingTenantException} — it refuses to
 *    adopt an unrelated existing tenant as the default.
 */
final class DefaultTenant
{
    public function __construct(private readonly SingleStoreTenant $singleStore)
    {
    }

    /**
     * Provision (or resume provisioning of) the default tenant + owner membership, persist the
     * default-tenant pointer, and return the tenant uuid.
     */
    public function ensure(string $slug, string $name, string $ownerUserUuid): string
    {
        return $this->singleStore->ensure($slug, $name, $ownerUserUuid);
    }

    /** The persisted default tenant uuid, or null when provisioning has not completed. */
    public function uuid(): ?string
    {
        return $this->singleStore->defaultUuidOrNull();
    }
}
