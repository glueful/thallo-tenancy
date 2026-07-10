<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

/**
 * PostgreSQL DDL strategy — the sole {@see RetrofitDdl} implementation (the tenancy retrofit is
 * PostgreSQL-only). Identifiers are double-quoted; uniques may exist as a constraint and/or an index,
 * so both are dropped idempotently; NOT NULL is set via `ALTER COLUMN … SET NOT NULL`.
 */
final class PostgresRetrofitDdl implements RetrofitDdl
{
    public function driver(): string
    {
        return 'pgsql';
    }

    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function addNullableColumn(string $table, string $column, string $type): string
    {
        // Columns are nullable by default in PostgreSQL — no NULL keyword needed.
        return 'ALTER TABLE ' . $this->quote($table)
            . ' ADD COLUMN ' . $this->quote($column) . ' ' . $type;
    }

    public function setNotNull(string $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->quote($table)
            . ' ALTER COLUMN ' . $this->quote($column) . ' SET NOT NULL';
    }

    public function dropUniqueCandidates(string $table, string $name): array
    {
        return [
            'ALTER TABLE ' . $this->quote($table) . ' DROP CONSTRAINT IF EXISTS ' . $this->quote($name),
            'DROP INDEX IF EXISTS ' . $this->quote($name),
        ];
    }

    public function createUnique(string $table, string $name, array $columns): string
    {
        return 'ALTER TABLE ' . $this->quote($table)
            . ' ADD CONSTRAINT ' . $this->quote($name) . ' UNIQUE (' . $this->quoteList($columns) . ')';
    }

    public function createIndex(string $table, string $name, array $columns): string
    {
        return 'CREATE INDEX ' . $this->quote($name) . ' ON ' . $this->quote($table)
            . ' (' . $this->quoteList($columns) . ')';
    }

    public function renameTable(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quote($from) . ' RENAME TO ' . $this->quote($to);
    }

    public function autoIncrementPk(string $column): string
    {
        return $this->quote($column) . ' BIGSERIAL PRIMARY KEY';
    }

    /** @param list<string> $columns */
    private function quoteList(array $columns): string
    {
        return implode(', ', array_map(fn (string $c): string => $this->quote($c), $columns));
    }
}
