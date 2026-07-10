<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Operation-scoped default-tenant provisioning for the enable-time retrofit.
 *
 * Talks ONLY to the neutral {@see TenantProvisioner} contract — never the tenancy extension's
 * concrete Tenant/TenantMembership models (contract-only rule, spec §4).
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
    private const KEY_PROVISIONING = 'tenancy.provisioning_tenant_uuid';
    private const KEY_DEFAULT = 'tenancy.default_tenant_uuid';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TenantProvisioner $provisioner,
        private readonly SystemFlags $flags,
    ) {
    }

    /**
     * Provision (or resume provisioning of) the default tenant + owner membership, persist the
     * default-tenant pointer, and return the tenant uuid.
     */
    public function ensure(string $slug, string $name, string $ownerUserUuid): string
    {
        $uuid = $this->flags->get(self::KEY_PROVISIONING);
        if ($uuid === null || $uuid === '') {
            // Fresh start: refuse to adopt an unrelated pre-existing tenant.
            if ($this->provisioner->hasAnyTenant($this->context)) {
                throw new PreexistingTenantException();
            }
            // Record the intended identity BEFORE provisioning so a crash-then-retry reuses it.
            $uuid = Utils::generateNanoID(12);
            $this->flags->put(self::KEY_PROVISIONING, $uuid);
        }

        $tenantUuid = $this->provisioner->provisionDefault(
            $this->context,
            $uuid,
            $slug,
            $name,
            $ownerUserUuid,
        );

        $this->flags->put(self::KEY_DEFAULT, $tenantUuid);

        return $tenantUuid;
    }

    /** The persisted default tenant uuid, or null when provisioning has not completed. */
    public function uuid(): ?string
    {
        $uuid = $this->flags->get(self::KEY_DEFAULT);

        return ($uuid === null || $uuid === '') ? null : $uuid;
    }
}
