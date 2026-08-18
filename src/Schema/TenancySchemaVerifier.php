<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/thallo-tenancy (schema policy spec B7): create migrations prove the
 * complete table set they create; permission-seed migrations prove their seeded slugs at the
 * data level — table existence never certifies a seed. Unknown basenames are never adoptable.
 */
final class TenancySchemaVerifier implements StructuralVerifierInterface
{
    /** @var array<string, list<string>> create migration => every table it creates */
    private const CREATED_TABLES = [
        '001_CreateSystemFlagsTable.php' => ['thallo_system_flags'],
        '002_CreateTenantPurgeRunsTable.php' => ['thallo_tenant_purge_runs'],
        '003_CreateTenantApiKeyBindingsTable.php' => ['thallo_tenant_api_key_bindings'],
        '004_CreateTenantRolePolicyTables.php' =>
            ['tenant_role_policy', 'tenant_role_availability', 'tenant_role_overrides'],
        '005_CreateTenantRolesTable.php' => ['tenant_roles'],
        '006_CreateSignupTables.php' =>
            ['signup_intents', 'signup_continuations', 'signup_verifiers', 'signup_rate_counters',
                'signup_daily_counters'],
    ];

    /** @var array<string, list<string>> seed migration => permission slugs it guarantees */
    private const SEEDED_SLUGS = [];

    public function source(): string
    {
        return 'glueful/thallo-tenancy';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        $names = array_merge(array_keys(self::CREATED_TABLES), array_keys(self::SEEDED_SLUGS));
        sort($names);
        return $names;
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        if (isset(self::CREATED_TABLES[$migrationBasename])) {
            $schema = $db->getSchemaBuilder();
            foreach (self::CREATED_TABLES[$migrationBasename] as $table) {
                if (!$schema->hasTable($table)) {
                    return false;
                }
            }
            return true;
        }
        if (isset(self::SEEDED_SLUGS[$migrationBasename])) {
            if (!$db->getSchemaBuilder()->hasTable('permissions')) {
                return false;
            }
            $slugs = self::SEEDED_SLUGS[$migrationBasename];
            $rows = $db->table('permissions')->select(['slug'])->whereIn('slug', $slugs)->get();
            return count(array_unique(array_column($rows, 'slug'))) === count($slugs);
        }
        return false;
    }
}
