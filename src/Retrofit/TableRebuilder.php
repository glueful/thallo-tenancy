<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Staged, recoverable copy-rebuild for the three owned tables that cannot be widened in place —
 * regions (PK `slug`), settings (PK `key`) and entry_redirects (an inline `uuid` unique). Each is
 * rebuilt into a widened `<table>_new` (same columns/types/defaults/indexes/CHECKs PLUS a NOT NULL
 * `tenant_uuid` and a widened PK/unique), every row copied and stamped with the default tenant, then
 * swapped into place through a rename dance that a fresh run can finish after a crash.
 *
 * The staged swap:
 *   1. REBUILD_CREATED  — create `<table>_new` (widened) and copy every row, stamping the tenant.
 *   2. rename `<table>` -> `<table>_backup`; [failpoint fires here]; rename `<table>_new` -> `<table>`.
 *      (recorded as REBUILD_SWAPPED once BOTH renames land)
 *   3. REBUILT          — drop `<table>_backup`.
 *
 * Reality-first recovery inspects actual table existence (never a phase marker alone) before acting:
 *   - canonical present AND already carries `tenant_uuid` => it is the widened table; never recopy,
 *     just clean up any `_new`/`_backup` leftovers and record REBUILT (covers a crash after the second
 *     rename but before the backup drop, and a plain idempotent re-run);
 *   - canonical MISSING but `<table>_backup` present => we crashed mid-swap; rebuild `<table>_new` from
 *     the backup first if it too was lost, complete the `_new` -> canonical rename, then drop the
 *     backup — the backup is never dropped until the widened canonical is provably in place.
 *
 * All DDL/DML runs on raw PDO, so the retrofit's own writes bypass the query-interceptor write barrier
 * by design; identifier quoting, table renames and the surrogate PK fragment come from the driver-
 * specific {@see RetrofitDdl}. The per-table widened schema is PostgreSQL-shaped (the retrofit's test
 * target); the entry_redirects CHECK constraints are reproduced verbatim from migration 010.
 */
final class TableRebuilder
{
    /** The column type for the stamped tenant key — a 12-char nano-id, mirroring the uuid columns. */
    private const TENANT_UUID_TYPE = 'varchar(12)';

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

    public function rebuild(string $table, ?callable $failpoint = null): void
    {
        $meta = ThalloTenantTables::all()[$table]
            ?? throw new InvalidArgumentException("Not a tenant-owned table: {$table}");
        if ($meta['special_backfill'] !== 'rebuild') {
            throw new InvalidArgumentException("Table {$table} takes the additive path, not the rebuild path.");
        }

        $new = $table . '_new';
        $backup = $table . '_backup';

        // Reality-first: a widened canonical already in place is the finished (or finishable) product —
        // never recopy. Clean up any swap leftovers and record REBUILT (idempotent re-run + the
        // crash-after-second-rename-before-backup-drop state both land here).
        if ($this->tableExists($table) && $this->introspector->columnExists($table, $meta['tenant_column'])) {
            $this->dropTableIfExists($new);
            $this->dropTableIfExists($backup);
            $this->progress->mark($table, RetrofitProgress::REBUILT);
            return;
        }

        // Crash recovery: canonical MISSING but the backup survived — we crashed after original->backup
        // and before new->canonical. Finish the swap (rebuilding _new from the backup if it too was
        // lost), then drop the backup. The backup is never dropped until canonical is provably in place.
        if (!$this->tableExists($table) && $this->tableExists($backup)) {
            if (!$this->tableExists($new)) {
                $this->createAndCopy($table, $new, $backup);
            }
            $this->exec($this->ddl->renameTable($new, $table));
            $this->progress->mark($table, RetrofitProgress::REBUILD_SWAPPED);
            $this->dropTableIfExists($backup);
            $this->progress->mark($table, RetrofitProgress::REBUILT);
            return;
        }

        // Fresh start (or a narrow canonical): create the widened _new and copy from the canonical.
        $this->dropTableIfExists($new); // discard any partial _new left by an earlier crash
        $this->createAndCopy($table, $new, $table);
        $this->progress->mark($table, RetrofitProgress::REBUILD_CREATED);

        // The recoverable swap. The failpoint fires in the crash window — right after the original has
        // become the backup and before _new becomes canonical — so a test can force a real mid-swap crash.
        $this->exec($this->ddl->renameTable($table, $backup));
        if ($failpoint !== null) {
            $failpoint();
        }
        $this->exec($this->ddl->renameTable($new, $table));
        $this->progress->mark($table, RetrofitProgress::REBUILD_SWAPPED);

        // Canonical is provably in place — drop the backup.
        $this->dropTableIfExists($backup);
        $this->progress->mark($table, RetrofitProgress::REBUILT);
    }

    /**
     * Create the widened `<newTable>` and copy every row from `<sourceTable>`, stamping the default
     * tenant. The source is the canonical table on a first pass and the backup during recovery — both
     * carry the same business columns, so the explicit column list is identical.
     */
    private function createAndCopy(string $logical, string $newTable, string $sourceTable): void
    {
        $tenantUuid = $this->defaultTenant->uuid();
        if ($tenantUuid === null) {
            throw new RuntimeException(
                "Cannot rebuild {$logical}: no default tenant has been provisioned."
            );
        }

        $this->exec($this->newTableSql($logical, $newTable));

        $columns = $this->sourceColumns($logical);
        $quoted = implode(', ', array_map(fn (string $c): string => $this->ddl->quote($c), $columns));
        $sql = 'INSERT INTO ' . $this->ddl->quote($newTable)
            . ' (' . $quoted . ', ' . $this->ddl->quote('tenant_uuid') . ') '
            . 'SELECT ' . $quoted . ', :tenant FROM ' . $this->ddl->quote($sourceTable);
        $stmt = $this->connection->getPDO()->prepare($sql);
        $stmt->execute([':tenant' => $tenantUuid]);

        // entry_redirects preserves its surrogate id, so its fresh sequence must be advanced past the
        // copied ids or the next builder insert would collide.
        if ($this->hasSurrogateId($logical) && $this->introspector->driver() === 'pgsql') {
            $this->advanceSequence($newTable, 'id');
        }
    }

    /**
     * The widened CREATE TABLE for one rebuild table: the source schema exactly (columns/types/defaults/
     * nullability/indexes/CHECKs) PLUS a NOT NULL `tenant_uuid` and the widened PK/unique. The tenant
     * column is created NOT NULL directly because the copy stamps every row on insert.
     */
    private function newTableSql(string $logical, string $newTable): string
    {
        $table = $this->ddl->quote($newTable);
        $tenant = $this->ddl->quote('tenant_uuid') . ' ' . self::TENANT_UUID_TYPE . ' NOT NULL';

        return match ($logical) {
            'regions' => $this->regionsSql($table, $tenant),
            'settings' => $this->settingsSql($table, $tenant),
            'entry_redirects' => $this->entryRedirectsSql($table, $tenant),
            default => throw new InvalidArgumentException("No rebuild schema for table: {$logical}"),
        };
    }

    /** regions: PK `slug` (64) widened to (tenant_uuid, slug); blocks/settings JSONB, both nullable. */
    private function regionsSql(string $table, string $tenant): string
    {
        return "CREATE TABLE {$table} (\n"
            . '    "slug" varchar(64) NOT NULL,' . "\n"
            . '    "blocks" jsonb,' . "\n"
            . '    "settings" jsonb,' . "\n"
            . '    "updated_at" timestamp,' . "\n"
            . '    "updated_by" varchar(12),' . "\n"
            . '    ' . $tenant . ',' . "\n"
            . '    PRIMARY KEY ("tenant_uuid", "slug")' . "\n"
            . ')';
    }

    /** settings: PK `key` (120) widened to (tenant_uuid, key); updated_at defaults CURRENT_TIMESTAMP. */
    private function settingsSql(string $table, string $tenant): string
    {
        return "CREATE TABLE {$table} (\n"
            . '    "key" varchar(120) NOT NULL,' . "\n"
            . '    "value" text,' . "\n"
            . '    "updated_at" timestamp DEFAULT CURRENT_TIMESTAMP,' . "\n"
            . '    ' . $tenant . ',' . "\n"
            . '    PRIMARY KEY ("tenant_uuid", "key")' . "\n"
            . ')';
    }

    /**
     * entry_redirects: keeps its surrogate id PK + inline `uuid` unique, widens the business unique to
     * include tenant_uuid, and reproduces migration 010's three CHECK constraints VERBATIM (status set,
     * origin set, exactly-one-target). Constraint/index names are `_new`/tenant-suffixed so they never
     * collide with the original's index names while both tables coexist during the swap window (CHECK
     * constraint names are table-scoped in PostgreSQL, so the chk_* names are reproduced as-is).
     */
    private function entryRedirectsSql(string $table, string $tenant): string
    {
        $id = $this->ddl->autoIncrementPk('id');

        return "CREATE TABLE {$table} (\n"
            . '    ' . $id . ',' . "\n"
            . '    "uuid" varchar(12),' . "\n"
            . '    "content_type_uuid" varchar(12),' . "\n"
            . '    "locale" varchar(16),' . "\n"
            . '    "source_slug" varchar(200),' . "\n"
            . '    "target_content_type_uuid" varchar(12),' . "\n"
            . '    "target_locale" varchar(16),' . "\n"
            . '    "target_entry_uuid" varchar(12),' . "\n"
            . '    "target_url" varchar(2048),' . "\n"
            . '    "status" integer DEFAULT 301,' . "\n"
            . "    \"origin\" varchar(16) DEFAULT 'manual',\n"
            . '    "created_by" varchar(12),' . "\n"
            . '    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,' . "\n"
            . '    "updated_at" timestamp,' . "\n"
            . '    ' . $tenant . ',' . "\n"
            . '    CONSTRAINT "uniq_entry_redirect_uuid" UNIQUE ("uuid"),' . "\n"
            . '    CONSTRAINT "uniq_redirect_tenant_type_locale_source"' . "\n"
            . '        UNIQUE ("tenant_uuid", "content_type_uuid", "locale", "source_slug"),' . "\n"
            . '    CONSTRAINT "chk_entry_redirect_status" CHECK (status IN (301, 302, 308)),' . "\n"
            . "    CONSTRAINT \"chk_entry_redirect_origin\" CHECK (origin IN ('auto', 'manual')),\n"
            . '    CONSTRAINT "chk_entry_redirect_exactly_one_target" CHECK (' . "\n"
            . '        (target_entry_uuid IS NOT NULL AND target_content_type_uuid IS NOT NULL' . "\n"
            . '            AND target_locale IS NOT NULL AND target_url IS NULL)' . "\n"
            . '        OR' . "\n"
            . '        (target_entry_uuid IS NULL AND target_content_type_uuid IS NULL' . "\n"
            . '            AND target_locale IS NULL AND target_url IS NOT NULL)' . "\n"
            . '    )' . "\n"
            . ')';
    }

    /**
     * The source column list to copy for one rebuild table (business columns only — tenant_uuid is
     * stamped separately). entry_redirects preserves its surrogate id.
     *
     * @return list<string>
     */
    private function sourceColumns(string $logical): array
    {
        return match ($logical) {
            'regions' => ['slug', 'blocks', 'settings', 'updated_at', 'updated_by'],
            'settings' => ['key', 'value', 'updated_at'],
            'entry_redirects' => [
                'id', 'uuid', 'content_type_uuid', 'locale', 'source_slug',
                'target_content_type_uuid', 'target_locale', 'target_entry_uuid', 'target_url',
                'status', 'origin', 'created_by', 'created_at', 'updated_at',
            ],
            default => throw new InvalidArgumentException("No rebuild columns for table: {$logical}"),
        };
    }

    private function hasSurrogateId(string $logical): bool
    {
        return $logical === 'entry_redirects';
    }

    /** Advance the fresh surrogate sequence past the max copied id (PostgreSQL). */
    private function advanceSequence(string $table, string $column): void
    {
        $pdo = $this->connection->getPDO();
        $seqStmt = $pdo->prepare('SELECT pg_get_serial_sequence(:t, :c)');
        $seqStmt->execute([':t' => $table, ':c' => $column]);
        $sequence = $seqStmt->fetchColumn();
        if (!is_string($sequence) || $sequence === '') {
            return;
        }

        $max = (int) $pdo->query(
            'SELECT COALESCE(MAX(' . $this->ddl->quote($column) . '), 0) FROM ' . $this->ddl->quote($table)
        )->fetchColumn();

        // is_called = false => the next nextval() returns exactly $max + 1.
        $setStmt = $pdo->prepare('SELECT setval(:seq, :val, false)');
        $setStmt->execute([':seq' => $sequence, ':val' => $max + 1]);
    }

    private function tableExists(string $table): bool
    {
        if ($this->introspector->driver() !== 'pgsql') {
            throw new RuntimeException(
                'Unsupported driver for table rebuild: ' . $this->introspector->driver()
            );
        }

        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare('SELECT to_regclass(:t)');
        $stmt->execute([':t' => $table]);
        $value = $stmt->fetchColumn();

        return $value !== null && $value !== false;
    }

    private function dropTableIfExists(string $table): void
    {
        $this->exec('DROP TABLE IF EXISTS ' . $this->ddl->quote($table));
    }

    private function exec(string $sql): void
    {
        $this->connection->getPDO()->exec($sql);
    }
}
