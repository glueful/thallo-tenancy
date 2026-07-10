<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

/**
 * Immutable summary of one {@see SchemaRetrofit::run()} invocation: the provisioned default tenant, the
 * owned tables that were widened (additively or by rebuild), and the legacy system-settings keys the
 * reconciler moved out of the soon-to-be-owned `settings` table into the system channel.
 *
 * A resumed or idempotent re-run returns a report just the same — the counts reflect the tables present
 * and processed on that pass (moved keys will be empty when there was nothing left to move).
 */
final class RetrofitReport
{
    /**
     * @param list<string> $widenedTables owned tables present in the DB that were widened this run
     * @param list<string> $movedSettingsKeys legacy system keys relocated to the system channel this run
     */
    public function __construct(
        private readonly string $defaultTenantUuid,
        private readonly array $widenedTables,
        private readonly array $movedSettingsKeys,
    ) {
    }

    public function defaultTenantUuid(): string
    {
        return $this->defaultTenantUuid;
    }

    /** @return list<string> */
    public function widenedTables(): array
    {
        return $this->widenedTables;
    }

    public function widenedTableCount(): int
    {
        return count($this->widenedTables);
    }

    /** @return list<string> */
    public function movedSettingsKeys(): array
    {
        return $this->movedSettingsKeys;
    }
}
