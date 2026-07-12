<?php

declare(strict_types=1);

namespace Thallo\Tenancy;

/**
 * The single source of truth for Thallo's tenant-owned tables + their retrofit metadata.
 *
 * Consumed by table registration (boot gate), the schema retrofit (Phase C), diagnostics
 * (Phase F), and tests — NO table list is hand-maintained anywhere else.
 *
 * Excludes collections (collection_definitions/collection_schema_changes + dynamic collection
 * tables): their table_name names a PHYSICAL table and is globally unique, so per-tenant
 * collections are a dedicated follow-up (SP4), not folded into the foundation (spec §6).
 *
 * `widened_uniques`: each entry is [name|null, columns[]] where columns is the NEW composite
 * (tenant_uuid first). uuid nano-id uniques stay GLOBAL and are not listed. `special_backfill`
 * = 'rebuild' marks tables needing PK/inline-unique reconstruction (no surrogate id, or an
 * inline ->unique()); everything else is an additive column add.
 *
 * DELIBERATELY-GLOBAL: `filter_indexes` is intentionally NOT owned (asserted by
 * RawPdoScopingLintTest's GLOBAL_BY_PROOF list). Three-part proof:
 *  (a) its `content_type_uuid` is a globally-unique 12-char nano-id owned by exactly one tenant, so
 *      the `(content_type_uuid, field)` unique can never collide across tenants — no widening needed
 *      for coexistence;
 *  (b) its rows catalog GLOBAL physical schema — `CREATE INDEX CONCURRENTLY ON entry_versions (<expr>)`
 *      is one shared object serving every tenant's (already tenant-scoped) `entry_versions` rows;
 *      owning the registry would misrepresent a shared index as per-tenant;
 *  (c) access is AUTHORIZED by a tenant-scoped `content_types` lookup — EnsureFilterIndexesJob
 *      reconciles only a `content_type_uuid` it reached through the owning tenant's context, so
 *      registry access is gated by owned-table authorization, not by the weaker assumption that a
 *      tenant "only knows" its own uuids.
 */
final class ThalloTenantTables
{
    /**
     * @return array<string, array{
     *   tenant_column: string,
     *   kind: 'definition'|'instance',
     *   widened_uniques: list<array{0: string|null, 1: list<string>}>,
     *   indexes: list<string>,
     *   special_backfill: string|null
     * }>
     */
    public static function all(): array
    {
        $def = 'definition';
        $inst = 'instance';

        return [
            // --- core definitions ---
            'content_types' => self::row($def, [[null, ['tenant_uuid', 'slug']]]),
            'block_types' => self::row($def, [['uniq_block_type_slug', ['tenant_uuid', 'slug']]]),
            'block_type_migrations' => self::row($def),
            'regions' => self::row($def, [], 'rebuild'), // PK is `slug` => (tenant_uuid, slug)

            // --- core instance data ---
            'entries' => self::row($inst),
            'entry_drafts' => self::row($inst),
            'entry_versions' => self::row($inst),
            'entry_publications' => self::row($inst),
            'entry_routes' => self::row(
                $inst,
                [['uniq_route_type_locale_slug', ['tenant_uuid', 'content_type_uuid', 'locale', 'slug']]],
            ),
            'entry_references' => self::row($inst),
            'published_entry_references' => self::row($inst),
            'entry_redirects' => self::row(
                $inst,
                [['uniq_redirect_type_locale_source', ['tenant_uuid', 'content_type_uuid', 'locale', 'source_slug']]],
                'rebuild', // inline ->unique() on uuid forces a table rebuild
            ),
            'entry_schema_migrations' => self::row($inst),
            'entry_schedules' => self::row($inst),
            'form_submissions' => self::row($inst),
            'media_assets' => self::row($inst, [], 'media_assets'),
            'media_meta' => self::row($inst),
            'media_usage' => self::row($inst),
            // settings: the site subset is tenant-owned (system keys move to the channel). INSTANCE
            // (per-tenant site data/config), NOT a schema definition — matters for divergence
            // checks + diagnostics. PK is `key` => (tenant_uuid, key), so needs a rebuild backfill.
            'settings' => self::row($inst, [], 'rebuild'),

            // --- pack tables (present only when the pack is installed; retrofit skips absent tables) ---
            'render_templates' => self::row(
                $def,
                [['uniq_render_template_theme_path', ['tenant_uuid', 'theme', 'path']]],
            ),
            'render_template_versions' => self::row($def),
            'navigation_menus' => self::row($def, [['uniq_navigation_menu_slug', ['tenant_uuid', 'slug']]]),
            'navigation_items' => self::row($inst),
            'seo_meta' => self::row($inst, [[null, ['tenant_uuid', 'entry_uuid', 'locale']]]),
            'analytics_facts' => self::row($inst),
            'analytics_daily' => self::row($inst, [[null, ['tenant_uuid', 'day', 'event', 'subject']]]),
            'analytics_active_actors' => self::row(
                $inst,
                [[null, ['tenant_uuid', 'day', 'metric', 'actor_type', 'actor_id_hash']]],
            ),
            'workflow_review_states' => self::row(
                $inst,
                [['uniq_workflow_state_entry_locale', ['tenant_uuid', 'entry_uuid', 'locale']]],
            ),
            'workflow_transitions' => self::row($inst),

            // Collection metadata is row-scoped; dynamic tc_* data tables are structurally
            // isolated per tenant and are never registered here.
            'collection_definitions' => self::row(
                $def,
                [['uniq_collection_def_tenant_name', ['tenant_uuid', 'name']]],
            ),
            'collection_schema_changes' => self::row($inst),

            // --- added by this pack ---
            'starter_provenance' => self::row($inst),
        ];
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::all());
    }

    /**
     * @param 'definition'|'instance' $kind
     * @param list<array{0: string|null, 1: list<string>}> $widenedUniques
     * @return array{
     *   tenant_column: string,
     *   kind: 'definition'|'instance',
     *   widened_uniques: list<array{0: string|null, 1: list<string>}>,
     *   indexes: list<string>,
     *   special_backfill: string|null
     * }
     */
    private static function row(string $kind, array $widenedUniques = [], ?string $specialBackfill = null): array
    {
        return [
            'tenant_column' => 'tenant_uuid',
            'kind' => $kind,
            'widened_uniques' => $widenedUniques,
            'indexes' => ['tenant_uuid'],
            'special_backfill' => $specialBackfill,
        ];
    }
}
