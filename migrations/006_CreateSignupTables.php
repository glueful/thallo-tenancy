<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateSignupTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('signup_intents')) {
            $schema->createTable('signup_intents', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('kind', 16);
                $table->string('origin', 16);
                $table->string('email', 255);
                $table->string('username', 255)->nullable();
                $table->string('first_name', 100)->nullable();
                $table->string('last_name', 100)->nullable();
                $table->string('password_hash', 255)->nullable();
                $table->string('tenant_uuid', 12)->nullable();
                $table->string('desired_slug', 64)->nullable();
                $table->string('workspace_name', 160)->nullable();
                $table->string('result_user_uuid', 12)->nullable();
                $table->string('result_tenant_uuid', 12)->nullable();
                $table->string('status', 20);
                $table->string('completion_outcome', 32)->nullable();
                $table->string('request_ip_hash', 64)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
                $table->unique('uuid', 'uniq_signup_intent_uuid');
            });
            $schema->alterTable('signup_intents', function ($table): void {
                $table->index('email', 'idx_signup_intents_email');
                $table->index('status', 'idx_signup_intents_status');
                $table->index('expires_at', 'idx_signup_intents_expires');
            });
        }

        if (!$schema->hasTable('signup_verifiers')) {
            $schema->createTable('signup_verifiers', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('intent_uuid', 12);
                $table->string('otp_hash', 255);
                $table->integer('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
                $table->unique('intent_uuid', 'uniq_signup_verifier_intent');
            });
        }

        if (!$schema->hasTable('signup_continuations')) {
            $schema->createTable('signup_continuations', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('intent_uuid', 12);
                $table->string('current_hash', 64)->nullable();
                $table->string('previous_hash', 64)->nullable();
                $table->string('previous_operation_id', 96)->nullable();
                $table->timestamp('previous_valid_until')->nullable();
                $table->string('last_operation_id', 96)->nullable();
                $table->string('last_operation_payload_hash', 64)->nullable();
                $table->string('last_operation_status', 16)->nullable();
                $table->json('last_operation_result')->nullable();
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
                $table->unique('intent_uuid', 'uniq_signup_continuation_intent');
            });
        }

        if (!$schema->hasTable('signup_rate_counters')) {
            $schema->createTable('signup_rate_counters', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('dimension', 32);
                $table->string('bucket_hash', 64);
                $table->timestamp('window_start');
                $table->integer('count')->default(0);
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
                $table->unique(
                    ['dimension', 'bucket_hash', 'window_start'],
                    'uniq_signup_rate_bucket',
                );
            });
        }

        if (!$schema->hasTable('signup_daily_counters')) {
            $schema->createTable('signup_daily_counters', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('capability', 20);
                $table->string('day', 10);
                $table->integer('count')->default(0);
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
                $table->unique(['capability', 'day'], 'uniq_signup_daily_cap');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('signup_daily_counters');
        $schema->dropTableIfExists('signup_rate_counters');
        $schema->dropTableIfExists('signup_continuations');
        $schema->dropTableIfExists('signup_verifiers');
        $schema->dropTableIfExists('signup_intents');
    }

    public function getDescription(): string
    {
        return 'Creates the system-global public signup intent and abuse-control tables.';
    }
}
