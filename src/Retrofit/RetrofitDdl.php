<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

/**
 * Driver-specific DDL string builder for the enable-time schema retrofit.
 *
 * Every method is a PURE builder — it returns SQL text (or a list of statements) and performs no
 * database access. The tenancy retrofit is PostgreSQL-only, so the sole implementation is
 * {@see PostgresRetrofitDdl}; every other driver is rejected by {@see RetrofitDdlFactory}.
 */
interface RetrofitDdl
{
    /** The driver token this strategy emits SQL for (`pgsql`). */
    public function driver(): string;

    /** Quote a single identifier for this driver, escaping any embedded quote characters. */
    public function quote(string $identifier): string;

    /** `ALTER TABLE … ADD COLUMN … <type>` as a nullable column. */
    public function addNullableColumn(string $table, string $column, string $type): string;

    /** Promote an existing column to NOT NULL via `ALTER COLUMN … SET NOT NULL`. */
    public function setNotNull(string $table, string $column): string;

    /**
     * A list of idempotent DROP statements that together remove a unique named `$name` — regardless of
     * whether the driver materialised it as a constraint, an index, or both.
     *
     * @return list<string>
     */
    public function dropUniqueCandidates(string $table, string $name): array;

    /**
     * Add a named UNIQUE across `$columns`.
     *
     * @param list<string> $columns
     */
    public function createUnique(string $table, string $name, array $columns): string;

    /**
     * Create a named non-unique index across `$columns`.
     *
     * @param list<string> $columns
     */
    public function createIndex(string $table, string $name, array $columns): string;

    /** Rename a table from `$from` to `$to`. */
    public function renameTable(string $from, string $to): string;

    /** The column-definition fragment for a surrogate auto-incrementing bigint primary key. */
    public function autoIncrementPk(string $column): string;
}
