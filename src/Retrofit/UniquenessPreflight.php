<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;
use PDO;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Enable-time duplicate detection. Before the retrofit stamps every row with the default tenant and
 * widens each business unique to (tenant_uuid, …business key…), it must prove no two existing rows
 * already share a business key — while a single tenant exists the widened unique reduces to its
 * business-key columns, so a pre-existing duplicate would make the widened unique impossible to create.
 *
 * For every owned table PRESENT in the database it derives each business-key set (the widened-unique
 * columns minus tenant_uuid) and scans for colliding rows with
 * `SELECT <cols>, COUNT(*) FROM <table> GROUP BY <cols> HAVING COUNT(*) > 1`. Rows where any business
 * column is NULL are excluded, mirroring the driver's NULLS-DISTINCT unique semantics (multiple NULLs
 * never collide). Identifiers are quoted through {@see RetrofitDdl::quote()} — never raw-concatenated —
 * so the scan is driver-correct.
 *
 * The concrete {@see RetrofitDdl} is derived from the live connection's driver via
 * {@see RetrofitDdlFactory}: RetrofitDdl is not itself a container service yet (the orchestrator task
 * registers one), so injecting the factory keeps this service container-resolvable today.
 */
final class UniquenessPreflight
{
    /** Cap on colliding-group examples retained per violation (diagnostic only, not the group count). */
    private const EXAMPLE_LIMIT = 5;

    private readonly RetrofitDdl $ddl;

    public function __construct(private readonly Connection $connection, RetrofitDdlFactory $ddlFactory)
    {
        $driver = (string) $this->connection->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->ddl = $ddlFactory->for($driver);
    }

    public function check(): PreflightReport
    {
        $violations = [];

        foreach (ThalloTenantTables::all() as $table => $meta) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $tenantColumn = $meta['tenant_column'];
            foreach ($meta['widened_uniques'] as $unique) {
                $businessColumns = array_values(array_filter(
                    $unique[1],
                    static fn (string $column): bool => $column !== $tenantColumn,
                ));
                if ($businessColumns === []) {
                    continue;
                }

                $groups = $this->duplicateGroups($table, $businessColumns);
                if ($groups === []) {
                    continue;
                }

                $violations[] = [
                    'table' => $table,
                    'columns' => $businessColumns,
                    'groups' => count($groups),
                    'examples' => array_slice($groups, 0, self::EXAMPLE_LIMIT),
                ];
            }
        }

        return new PreflightReport($violations);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->connection->getPDO()->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_name = :table LIMIT 1'
        );
        $stmt->execute([':table' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Rows sharing a business key (NULLs excluded, matching NULLS-DISTINCT unique semantics).
     *
     * @param list<string> $columns
     * @return list<array{values: array<string, scalar|null>, count: int}>
     */
    private function duplicateGroups(string $table, array $columns): array
    {
        $quotedColumns = array_map(fn (string $column): string => $this->ddl->quote($column), $columns);
        $selectList = implode(', ', $quotedColumns);
        $notNull = implode(
            ' AND ',
            array_map(static fn (string $column): string => $column . ' IS NOT NULL', $quotedColumns),
        );

        $sql = 'SELECT ' . $selectList . ', COUNT(*) AS duplicate_count'
            . ' FROM ' . $this->ddl->quote($table)
            . ' WHERE ' . $notNull
            . ' GROUP BY ' . $selectList
            . ' HAVING COUNT(*) > 1';

        $stmt = $this->connection->getPDO()->query($sql);
        if ($stmt === false) {
            return [];
        }

        $groups = [];
        /** @var array<string, scalar|null> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[$column] = $row[$column] ?? null;
            }
            $groups[] = ['values' => $values, 'count' => (int) $row['duplicate_count']];
        }

        return $groups;
    }
}
