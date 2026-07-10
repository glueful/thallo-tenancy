<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Reality-first retrofit diagnostics: reads the LIVE schema (never a phase marker or a cached
 * declaration) and reports whether each owned table is coherently widened, and whether the persisted
 * {@see SystemFlags::schemaState()} agrees with that reality.
 *
 * Coherence keys ONLY on the tenant key + the widened unique(s): a present owned table is coherent
 * when `tenant_uuid` exists AND is NOT NULL AND every widened unique (the registry's widened_uniques,
 * plus the widened PK for the two rebuild tables whose primary key — not a listed unique — carries the
 * tenant column) is present. Business columns are deliberately NOT inspected — on the live schema only
 * surrogate/PK columns are NOT NULL, so requiring e.g. `content_types.slug` NOT NULL would wrongly
 * report a correctly-widened table as incoherent.
 *
 * `checkAgreement()` only COMPARES the flag against reality — it never requires the flag to already be
 * `widened`, so it cannot deadlock a retrofit that is mid-flight or has not started.
 */
final class RetrofitDiagnostics
{
    /**
     * Widened PKs for the rebuild tables whose primary key (not a listed unique) carries the tenant
     * column. entry_redirects keeps a surrogate `id` PK and lists its widened business unique in
     * {@see ThalloTenantTables}, so it needs no entry here.
     *
     * @var array<string, list<string>>
     */
    private const REBUILD_PK = [
        'regions' => ['tenant_uuid', 'slug'],
        'settings' => ['tenant_uuid', 'key'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaIntrospector $introspector,
        private readonly SystemFlags $flags,
    ) {
    }

    /**
     * Per owned table PRESENT in the DB, report its widening coherence. Absent tables (e.g. an
     * uninstalled pack's tables) are omitted — the retrofit skips them, so they are not a divergence.
     *
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function checkTables(): array
    {
        $out = [];
        foreach (ThalloTenantTables::all() as $table => $meta) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $out[$table] = $this->tableCoherence($table, $meta);
        }

        return $out;
    }

    /**
     * Does the persisted schema_state agree with the live schema? `none` agrees when NO present owned
     * table is coherently widened; nullable staging columns are allowed before enablement (media
     * ownership uses one for pre-attribution). `widened` agrees when EVERY present owned table is
     * coherent. Comparison only — never requires the flag to be widened first.
     *
     * @return array{ok: bool, detail: array<string, mixed>}
     */
    public function checkAgreement(): array
    {
        $state = $this->flags->schemaState();

        if ($state === 'none') {
            $widened = [];
            foreach (ThalloTenantTables::all() as $table => $meta) {
                if ($this->tableExists($table) && $this->tableCoherence($table, $meta)['ok']) {
                    $widened[] = $table;
                }
            }

            return [
                'ok' => $widened === [],
                'detail' => ['schema_state' => 'none', 'widened_tables' => $widened],
            ];
        }

        $incoherent = [];
        foreach ($this->checkTables() as $table => $result) {
            if ($result['ok'] === false) {
                $incoherent[] = $table;
            }
        }

        return [
            'ok' => $incoherent === [],
            'detail' => ['schema_state' => 'widened', 'incoherent_tables' => $incoherent],
        ];
    }

    /**
     * Both reports combined.
     *
     * @return array{
     *   tables: array<string, array{ok: bool, detail: string}>,
     *   agreement: array{ok: bool, detail: array<string, mixed>}
     * }
     */
    public function check(): array
    {
        return [
            'tables' => $this->checkTables(),
            'agreement' => $this->checkAgreement(),
        ];
    }

    /**
     * @param array{tenant_column: string, widened_uniques: list<array{0: string|null, 1: list<string>}>, ...} $meta
     * @return array{ok: bool, detail: string}
     */
    private function tableCoherence(string $table, array $meta): array
    {
        $column = $meta['tenant_column'];

        if (!$this->introspector->columnExists($table, $column)) {
            return ['ok' => false, 'detail' => "missing {$column} column"];
        }
        if (!$this->introspector->columnNotNull($table, $column)) {
            return ['ok' => false, 'detail' => "{$column} is nullable"];
        }
        foreach ($this->widenedUniques($table, $meta) as $columns) {
            if (!$this->introspector->uniqueExists($table, $columns)) {
                return ['ok' => false, 'detail' => 'missing widened unique: (' . implode(', ', $columns) . ')'];
            }
        }

        return ['ok' => true, 'detail' => 'coherent'];
    }

    /**
     * The widened column sets that must exist for a table to be coherent: the registry's widened
     * uniques, plus the widened PK for the two rebuild tables whose primary key carries the tenant key.
     *
     * @param array{widened_uniques: list<array{0: string|null, 1: list<string>}>, ...} $meta
     * @return list<list<string>>
     */
    private function widenedUniques(string $table, array $meta): array
    {
        $sets = [];
        foreach ($meta['widened_uniques'] as $unique) {
            $sets[] = $unique[1];
        }
        if (isset(self::REBUILD_PK[$table])) {
            $sets[] = self::REBUILD_PK[$table];
        }

        return $sets;
    }

    /** Live table presence (raw query on the PDO — never a cached/declared schema). */
    private function tableExists(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }
}
