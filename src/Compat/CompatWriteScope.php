<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Compat;

use Thallo\Contracts\Tenancy\TenantWriteScope;
use Thallo\Tenancy\ThalloTenantTables;

/** Boot-frozen compatibility scope for widened, single-tenant operation. */
final class CompatWriteScope implements TenantWriteScope
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $schemaState,
        private readonly ?string $defaultTenantUuid,
    ) {
    }

    public function mode(): string
    {
        if ($this->enabled) {
            return 'normal';
        }

        return $this->schemaState === 'widened' ? 'compat' : 'off';
    }

    public function tenantUuidForWrite(): ?string
    {
        if ($this->mode() !== 'compat') {
            return null;
        }
        if ($this->defaultTenantUuid === null || $this->defaultTenantUuid === '') {
            throw new \RuntimeException('Compatibility mode requires a default tenant.');
        }

        return $this->defaultTenantUuid;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function stampIfMissing(string $table, array $row): array
    {
        if (
            $this->mode() !== 'compat'
            || !in_array($table, ThalloTenantTables::tableNames(), true)
            || array_key_exists('tenant_uuid', $row)
        ) {
            return $row;
        }

        $row['tenant_uuid'] = $this->tenantUuidForWrite();
        return $row;
    }
}
