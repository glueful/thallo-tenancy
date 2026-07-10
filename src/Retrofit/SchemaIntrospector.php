<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;
use PDO;

/**
 * PostgreSQL schema introspection for the enable-time retrofit.
 *
 * Reads live catalog metadata (never a cached/declared schema) so the retrofit's phase ladder can
 * decide, from reality, whether a column is NOT NULL and whether a unique/index already exists.
 * Column-set comparison is order-independent and case-folded, so a widened unique matches regardless
 * of the order the retrofit created it in.
 *
 * Uses raw catalog queries on the injected PDO (pg_index/information_schema) rather than the schema
 * builder — introspection needs constraint/index column membership, which the builder does not expose.
 *
 * The tenancy retrofit is PostgreSQL-only. Every introspection method assumes `pgsql`; because this
 * code is only ever reached after {@see RetrofitDdlFactory} has already admitted the driver, the
 * non-pgsql guard below is belt-and-suspenders.
 */
final class SchemaIntrospector
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** The live PDO driver name — the orchestrator's driver gate reads this. */
    public function driver(): string
    {
        return (string) $this->connection->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Name of the UNIQUE constraint/index whose column set EQUALS $columns (order-independent), or
     * null when no unique covers exactly that set.
     *
     * @param list<string> $columns
     */
    public function uniqueName(string $table, array $columns): ?string
    {
        foreach ($this->uniques($table) as $name => $cols) {
            if ($this->sameSet($cols, $columns)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * True when a UNIQUE constraint/index covering exactly $columns (order-independent) exists.
     *
     * @param list<string> $columns
     */
    public function uniqueExists(string $table, array $columns): bool
    {
        return $this->uniqueName($table, $columns) !== null;
    }

    /** True when an index of that exact name exists on $table. */
    public function indexExists(string $table, string $name): bool
    {
        $this->assertPgsql();

        return $this->pgIndexExists($this->connection->getPDO(), $table, $name);
    }

    /** True when the column exists on the table, regardless of nullability. */
    public function columnExists(string $table, string $column): bool
    {
        $this->assertPgsql();

        return $this->infoSchemaColumnExists($this->connection->getPDO(), $table, $column);
    }

    /** True when the column exists AND is declared NOT NULL. */
    public function columnNotNull(string $table, string $column): bool
    {
        $this->assertPgsql();

        return $this->infoSchemaNotNull($this->connection->getPDO(), $table, $column);
    }

    /**
     * Map of unique-index name => its column list.
     *
     * @return array<string, list<string>>
     */
    private function uniques(string $table): array
    {
        $this->assertPgsql();

        return $this->pgUniques($this->connection->getPDO(), $table);
    }

    // --- PostgreSQL -----------------------------------------------------------------------------

    /** @return array<string, list<string>> */
    private function pgUniques(PDO $pdo, string $table): array
    {
        $sql = <<<'SQL'
            SELECT i.relname AS index_name, a.attname AS column_name
            FROM pg_index ix
            JOIN pg_class t ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
            WHERE t.relname = :table
              AND ix.indisunique = true
              AND pg_table_is_visible(t.oid)
            SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':table' => $table]);

        $out = [];
        /** @var array{index_name: string, column_name: string} $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['index_name']][] = $row['column_name'];
        }

        return $out;
    }

    private function pgIndexExists(PDO $pdo, string $table, string $name): bool
    {
        $sql = <<<'SQL'
            SELECT 1
            FROM pg_class i
            JOIN pg_index ix ON ix.indexrelid = i.oid
            JOIN pg_class t ON t.oid = ix.indrelid
            WHERE t.relname = :table
              AND i.relname = :name
              AND pg_table_is_visible(t.oid)
            LIMIT 1
            SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':table' => $table, ':name' => $name]);

        return $stmt->fetchColumn() !== false;
    }

    private function infoSchemaColumnExists(PDO $pdo, string $table, string $column): bool
    {
        // A row in information_schema.columns proves the column exists.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_name = :table AND column_name = :column LIMIT 1'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);

        return $stmt->fetchColumn() !== false;
    }

    private function infoSchemaNotNull(PDO $pdo, string $table, string $column): bool
    {
        // information_schema.columns.is_nullable = 'NO' means the column exists AND is NOT NULL.
        // A missing column returns no row => false.
        $sql = <<<'SQL'
            SELECT is_nullable
            FROM information_schema.columns
            WHERE table_name = :table
              AND column_name = :column
            SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':table' => $table, ':column' => $column]);
        $value = $stmt->fetchColumn();

        return is_string($value) && strtoupper($value) === 'NO';
    }

    // --- shared -------------------------------------------------------------------------------- -

    /**
     * Set-equal comparison of two column lists: same members regardless of order, case-folded.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private function sameSet(array $a, array $b): bool
    {
        $a = array_map('strtolower', $a);
        $b = array_map('strtolower', $b);
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Belt-and-suspenders guard: introspection is only ever reached after the factory gate has admitted
     * pgsql, so a non-pgsql driver here means the gate was bypassed.
     */
    private function assertPgsql(): void
    {
        if ($this->driver() !== 'pgsql') {
            throw new UnsupportedRetrofitDriverException($this->driver());
        }
    }
}
