<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Unscoped system-global key/value store. Holds runtime tenancy state that MUST be readable
 * before any tenant resolves (tenancy.enabled, tenancy.schema_state, the default-tenant pointer,
 * enable-job progress). It is NEVER registered in ThalloTenantTables — it has no tenant_uuid.
 */
final class CreateSystemFlagsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_system_flags')) {
            return;
        }

        $schema->createTable('thallo_system_flags', function ($table): void {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->string('updated_at', 32)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_system_flags');
    }

    public function getDescription(): string
    {
        return 'Create thallo_system_flags: unscoped system-global key/value store for tenancy runtime state.';
    }
}
