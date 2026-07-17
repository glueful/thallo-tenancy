<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateTenantRolePolicyTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('tenant_role_overrides')) {
            $schema->createTable('tenant_role_overrides', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('tenant_uuid', 12);
                $table->string('role_slug', 64);
                $table->string('capability', 96);
                $table->string('effect', 8);
                $table->string('created_by', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
                $table->unique(
                    ['tenant_uuid', 'role_slug', 'capability'],
                    'uniq_tenant_role_override'
                );
            });
            $schema->alterTable('tenant_role_overrides', function ($table): void {
                $table->index('tenant_uuid', 'idx_tenant_role_overrides_tenant');
            });
        }
        if (!$schema->hasTable('tenant_role_policy')) {
            $schema->createTable('tenant_role_policy', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('tenant_uuid', 12);
                $table->integer('version')->default(0);
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
                $table->unique('tenant_uuid', 'uniq_tenant_role_policy');
            });
        }
        // Per-workspace availability of the BUILT-IN roles (admin/member/viewer): a row means
        // "this workspace has disabled this reserved role" — absent = active, so existing
        // workspaces are untouched. Deliberately separate from tenant_role_overrides
        // ("offered" and "grants capability X" are different policy dimensions) and from
        // tenant_roles (which holds only genuine custom roles). `owner` never gets a row.
        if (!$schema->hasTable('tenant_role_availability')) {
            $schema->createTable('tenant_role_availability', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('tenant_uuid', 12);
                $table->string('role', 64);
                $table->string('status', 16)->default('disabled');
                $table->string('updated_by', 12)->nullable();
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
                $table->unique(['tenant_uuid', 'role'], 'uniq_tenant_role_availability');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('tenant_role_overrides');
        $schema->dropTableIfExists('tenant_role_policy');
        $schema->dropTableIfExists('tenant_role_availability');
    }

    public function getDescription(): string
    {
        return 'Creates per-tenant role overrides and transactional policy versions.';
    }
}
