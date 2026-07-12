<?php

declare(strict_types=1);

namespace Thallo\Tenancy\ApiKeyBinding;

use Glueful\Database\Connection;

final class TenantApiKeyBindingRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function bind(string $apiKeyUuid, string $tenantUuid): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->connection->table('thallo_tenant_api_key_bindings')
            ->where('api_key_uuid', $apiKeyUuid)
            ->first();
        if ($existing === null) {
            $this->connection->table('thallo_tenant_api_key_bindings')->insert([
                'api_key_uuid' => $apiKeyUuid,
                'tenant_uuid' => $tenantUuid,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $this->connection->table('thallo_tenant_api_key_bindings')
            ->where('api_key_uuid', $apiKeyUuid)
            ->update(['tenant_uuid' => $tenantUuid, 'updated_at' => $now]);
    }

    public function unbind(string $apiKeyUuid): void
    {
        $this->connection->table('thallo_tenant_api_key_bindings')
            ->where('api_key_uuid', $apiKeyUuid)
            ->delete();
    }

    public function tenantFor(string $apiKeyUuid): ?string
    {
        $row = $this->connection->table('thallo_tenant_api_key_bindings')
            ->where('api_key_uuid', $apiKeyUuid)
            ->first();
        return is_array($row) && is_string($row['tenant_uuid'] ?? null) ? $row['tenant_uuid'] : null;
    }

    public function copyBinding(string $fromApiKeyUuid, string $toApiKeyUuid): void
    {
        $tenantUuid = $this->tenantFor($fromApiKeyUuid);
        if ($tenantUuid !== null) {
            $this->bind($toApiKeyUuid, $tenantUuid);
        }
    }

    /** @return list<string> */
    public function bindingsForTenant(string $tenantUuid): array
    {
        $rows = $this->connection->table('thallo_tenant_api_key_bindings')
            ->where('tenant_uuid', $tenantUuid)
            ->get();

        return array_values(array_map(
            'strval',
            array_column($rows, 'api_key_uuid'),
        ));
    }
}
