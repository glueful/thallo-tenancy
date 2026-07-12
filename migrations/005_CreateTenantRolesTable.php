<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateTenantRolesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_roles')) {
            return;
        }
        $schema->createTable('tenant_roles', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('tenant_uuid', 12);
            $table->string('slug', 64);
            $table->string('name', 160);
            $table->string('status', 16)->default('active');
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->unique(['tenant_uuid', 'slug'], 'uniq_tenant_role_slug');
        });
        $schema->alterTable('tenant_roles', function ($table): void {
            $table->index('tenant_uuid', 'idx_tenant_roles_tenant');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('tenant_roles');
    }

    public function getDescription(): string
    {
        return 'Creates per-tenant custom membership roles.';
    }
}
