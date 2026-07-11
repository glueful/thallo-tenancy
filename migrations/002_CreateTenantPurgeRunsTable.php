<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateTenantPurgeRunsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('thallo_tenant_purge_runs')) {
            $schema->createTable('thallo_tenant_purge_runs', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12)->unique();
                $table->string('tenant_uuid', 12)->index();
                $table->string('requested_by_uuid', 12)->nullable();
                $table->string('status', 32);
                $table->timestamp('lease_expires_at')->nullable();
                $table->string('lease_owner', 64)->nullable();
                $table->integer('attempts')->default(0);
                $table->json('plan');
                $table->json('artifacts');
                $table->string('failed_handler', 120)->nullable();
                $table->string('failed_phase', 32)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

                $table->index('status');
                $table->index('lease_expires_at');
            });
        }

        $schema->getConnection()->getPDO()->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS uniq_active_tenant_purge_run "
            . "ON thallo_tenant_purge_runs (tenant_uuid) WHERE status <> 'completed'"
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_tenant_purge_runs');
    }

    public function getDescription(): string
    {
        return 'Create the system-global, lease-owned tenant purge run ledger.';
    }
}
