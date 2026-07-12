<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

class CreateTenantApiKeyBindingsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_tenant_api_key_bindings')) {
            return;
        }

        $schema->createTable('thallo_tenant_api_key_bindings', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('api_key_uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->unique('api_key_uuid', 'uniq_tenant_api_key_binding');
            // Plain indexed scalars, NO cross-package FKs — matches the pack convention
            // (thallo_tenant_purge_runs, released_hosts). This migration is always loaded, so it must
            // not hard-depend on another provider's table lifecycle/ordering. Revocation and workspace
            // purge delete bindings explicitly; an orphaned row is inert and cleaned by those paths.
        });
        $schema->alterTable('thallo_tenant_api_key_bindings', function ($table): void {
            $table->index('tenant_uuid', 'idx_tenant_api_key_bindings_tenant');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_tenant_api_key_bindings');
    }

    public function getDescription(): string
    {
        return 'Creates system-global API-key to tenant bindings.';
    }
}
