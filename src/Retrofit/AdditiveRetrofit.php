<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * The additive per-table retrofit path: advances one ADDITIVE owned table through the persisted phase
 * ladder — add the nullable `tenant_uuid` column, backfill it with the default tenant, promote it to
 * NOT NULL, drop each narrow (pre-tenant) business unique, and add the widened `(tenant_uuid, …)`
 * unique plus the tenant index.
 *
 * Tables whose {@see ThalloTenantTables} metadata marks them `special_backfill = 'rebuild'`
 * (regions/settings/entry_redirects) are NOT handled here — they take the copy-rebuild path
 * ({@see TableRebuilder}) and are rejected outright.
 *
 * Idempotent + resumable: every phase is gated by {@see RetrofitProgress::reached()} (skip already-
 * completed phases on a re-run) AND by live introspection (skip a DDL whose effect is already present,
 * so a crash between running the DDL and recording progress cannot cause a duplicate-DDL error). All
 * DDL/DML runs on raw PDO — the retrofit's own writes bypass the query-interceptor write barrier by
 * design; all DDL text is produced by the driver-specific {@see RetrofitDdl}.
 */
final class AdditiveRetrofit
{
    /** The column type for the stamped tenant key — a 12-char nano-id, mirroring the uuid columns. */
    private const TENANT_UUID_TYPE = 'varchar(12)';

    /** Postgres caps identifiers at 63 chars. */
    private const MAX_IDENTIFIER = 63;

    private readonly RetrofitDdl $ddl;

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaIntrospector $introspector,
        private readonly RetrofitProgress $progress,
        private readonly DefaultTenant $defaultTenant,
        RetrofitDdlFactory $ddlFactory,
    ) {
        $driver = (string) $this->connection->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->ddl = $ddlFactory->for($driver);
    }

    public function apply(string $table): void
    {
        $meta = ThalloTenantTables::all()[$table]
            ?? throw new InvalidArgumentException("Not a tenant-owned table: {$table}");

        if ($meta['special_backfill'] === 'rebuild') {
            throw new InvalidArgumentException(
                "Table {$table} takes the rebuild path, not the additive path."
            );
        }

        $column = $meta['tenant_column'];

        $this->addColumn($table, $column);
        $this->backfill($table, $column);
        $this->setNotNull($table, $column);
        $this->dropNarrowUniques($table, $column, $meta['widened_uniques']);
        $this->addWidenedUniques($table, $meta['widened_uniques'], $meta['indexes']);
    }

    /** Phase 1 — add the nullable tenant column when absent. */
    private function addColumn(string $table, string $column): void
    {
        if ($this->progress->reached($table, RetrofitProgress::COLUMN_ADDED)) {
            return;
        }
        if (!$this->introspector->columnExists($table, $column)) {
            $this->exec($this->ddl->addNullableColumn($table, $column, self::TENANT_UUID_TYPE));
        }
        $this->progress->mark($table, RetrofitProgress::COLUMN_ADDED);
    }

    /** Phase 2 — stamp every not-yet-stamped row with the default tenant. */
    private function backfill(string $table, string $column): void
    {
        if ($this->progress->reached($table, RetrofitProgress::BACKFILLED)) {
            return;
        }
        $tenantUuid = $this->defaultTenant->uuid();
        if ($tenantUuid === null) {
            throw new RuntimeException(
                "Cannot backfill {$table}.{$column}: no default tenant has been provisioned."
            );
        }
        $sql = 'UPDATE ' . $this->ddl->quote($table)
            . ' SET ' . $this->ddl->quote($column) . ' = :tenant'
            . ' WHERE ' . $this->ddl->quote($column) . ' IS NULL';
        $stmt = $this->connection->getPDO()->prepare($sql);
        $stmt->execute([':tenant' => $tenantUuid]);

        $this->progress->mark($table, RetrofitProgress::BACKFILLED);
    }

    /** Phase 3 — promote tenant_uuid ONLY to NOT NULL; business columns are left untouched. */
    private function setNotNull(string $table, string $column): void
    {
        if ($this->progress->reached($table, RetrofitProgress::NOT_NULL)) {
            return;
        }
        if (!$this->introspector->columnNotNull($table, $column)) {
            $this->exec($this->ddl->setNotNull($table, $column));
        }
        $this->progress->mark($table, RetrofitProgress::NOT_NULL);
    }

    /**
     * Phase 4 — drop each narrow (pre-tenant) unique that a widened unique replaces, then assert it is
     * gone. Global nano-id uniques (uuid) are NOT listed in widened_uniques, so they survive.
     *
     * @param list<array{0: string|null, 1: list<string>}> $widenedUniques
     */
    private function dropNarrowUniques(string $table, string $tenantColumn, array $widenedUniques): void
    {
        if ($this->progress->reached($table, RetrofitProgress::NARROW_UNIQUE_DROPPED)) {
            return;
        }
        foreach ($widenedUniques as $unique) {
            $businessColumns = $this->businessColumns($unique[1], $tenantColumn);
            if ($businessColumns === []) {
                continue;
            }
            $narrowName = $this->introspector->uniqueName($table, $businessColumns);
            if ($narrowName === null) {
                continue; // already dropped, or never existed as a narrow unique
            }
            foreach ($this->ddl->dropUniqueCandidates($table, $narrowName) as $statement) {
                $this->exec($statement);
            }
            if ($this->introspector->uniqueExists($table, $businessColumns)) {
                throw new RuntimeException(
                    "Failed to drop narrow unique '{$narrowName}' on {$table}."
                );
            }
        }
        $this->progress->mark($table, RetrofitProgress::NARROW_UNIQUE_DROPPED);
    }

    /**
     * Phase 5 — create each widened `(tenant_uuid, …)` unique (guarded by the live column set) and the
     * tenant index (guarded by name).
     *
     * @param list<array{0: string|null, 1: list<string>}> $widenedUniques
     * @param list<string> $indexes
     */
    private function addWidenedUniques(string $table, array $widenedUniques, array $indexes): void
    {
        if ($this->progress->reached($table, RetrofitProgress::WIDENED_UNIQUE_ADDED)) {
            return;
        }
        foreach ($widenedUniques as $unique) {
            $columns = $unique[1];
            if ($this->introspector->uniqueExists($table, $columns)) {
                continue;
            }
            $name = $this->widenedUniqueName($table, $unique[0], $columns);
            $this->exec($this->ddl->createUnique($table, $name, $columns));
        }
        foreach ($indexes as $indexColumn) {
            $name = $this->constrainName($table . '_' . $indexColumn . '_index');
            if (!$this->introspector->indexExists($table, $name)) {
                $this->exec($this->ddl->createIndex($table, $name, [$indexColumn]));
            }
        }
        $this->progress->mark($table, RetrofitProgress::WIDENED_UNIQUE_ADDED);
    }

    /**
     * Business-key columns of a widened unique: the widened set minus the tenant column.
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private function businessColumns(array $columns, string $tenantColumn): array
    {
        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => $column !== $tenantColumn,
        ));
    }

    /**
     * The name for a widened unique — the registry name when given, else a deterministic name derived
     * from the table + columns (the introspector matches by column set, so the exact name is free).
     *
     * @param list<string> $columns
     */
    private function widenedUniqueName(string $table, ?string $name, array $columns): string
    {
        if ($name !== null) {
            return $name;
        }

        return $this->constrainName($table . '_' . implode('_', $columns) . '_unique');
    }

    /** Keep a generated identifier within the driver's length limit, hashing the overflow. */
    private function constrainName(string $name): string
    {
        if (strlen($name) <= self::MAX_IDENTIFIER) {
            return $name;
        }

        return substr($name, 0, 42) . '_' . substr(hash('sha256', $name), 0, 20);
    }

    private function exec(string $sql): void
    {
        $this->connection->getPDO()->exec($sql);
    }
}
