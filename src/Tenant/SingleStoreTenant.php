<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Tenant;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioner;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\Retrofit\PreexistingTenantException;
use Thallo\Tenancy\System\SystemFlags;

/** Canonical tenant identity and provisioning path for single-store and tenant modes. */
final class SingleStoreTenant
{
    private const KEY_PROVISIONING = 'tenancy.provisioning_tenant_uuid';
    private const KEY_DEFAULT = 'tenancy.default_tenant_uuid';
    private const LOCK_KEY = 'thallo:single-store-tenant';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly SystemFlags $flags,
        private readonly ContractTenantProvisioner $provisioner,
        private readonly ?CurrentTenantResolver $current = null,
    ) {
    }

    public function resolve(): string
    {
        if ($this->flags->tenancyEnabled()) {
            if ($this->current === null) {
                throw new \RuntimeException('Tenancy is enabled but tenant resolution is unavailable.');
            }
            $tenantUuid = $this->current->tenantUuid($this->context);
            if ($tenantUuid === '') {
                throw new \RuntimeException('Tenancy is enabled but no tenant was resolved for this request.');
            }

            return $tenantUuid;
        }

        $tenantUuid = $this->flags->defaultTenantUuid();
        if ($tenantUuid === null) {
            throw new \RuntimeException(
                'No single-store tenant is established. Run thallo:tenancy:single-store:repair.',
            );
        }

        return $tenantUuid;
    }

    public function defaultUuidOrNull(): ?string
    {
        return $this->flags->defaultTenantUuid();
    }

    public function ensure(string $slug, string $name, string $ownerUserUuid): string
    {
        try {
            return $this->connection->transaction(function () use ($slug, $name, $ownerUserUuid): string {
                $statement = $this->connection->getPDO()->prepare(
                    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                );
                $statement->execute([self::LOCK_KEY]);

                $this->flags->clearCache();
                $default = $this->flags->defaultTenantUuid();
                $intended = $this->flags->get(self::KEY_PROVISIONING);

                if ($default !== null) {
                    if ($intended !== null && $intended !== '' && $intended !== $default) {
                        throw new \RuntimeException('Single-store tenant pointers disagree.');
                    }

                    return $this->provisioner->provisionDefault(
                        $this->context,
                        $default,
                        $slug,
                        $name,
                        $ownerUserUuid,
                    );
                }

                if ($intended === null || $intended === '') {
                    if ($this->provisioner->hasAnyTenant($this->context)) {
                        throw new PreexistingTenantException();
                    }
                    $intended = Utils::generateNanoID(12);
                    $this->flags->put(self::KEY_PROVISIONING, $intended);
                }

                $tenantUuid = $this->provisioner->provisionDefault(
                    $this->context,
                    $intended,
                    $slug,
                    $name,
                    $ownerUserUuid,
                );
                $this->flags->put(self::KEY_DEFAULT, $tenantUuid);

                return $tenantUuid;
            });
        } finally {
            // A rolled-back transaction must not leave transaction-local flag values memoized.
            $this->flags->clearCache();
        }
    }
}
