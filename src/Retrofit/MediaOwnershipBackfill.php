<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;

/** Assigns every pre-enablement blob to the first/default tenant before ownership is widened. */
final class MediaOwnershipBackfill
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function run(string $tenantUuid): int
    {
        $schema = $this->connection->getSchemaBuilder();
        if (!$schema->hasTable('blobs') || !$schema->hasTable('media_assets')) {
            return 0;
        }

        $statement = $this->connection->getPDO()->prepare(
            'INSERT INTO media_assets (blob_uuid, tenant_uuid, created_at)
             SELECT b.uuid, :tenant, CURRENT_TIMESTAMP
             FROM blobs b
             LEFT JOIN media_assets ma ON ma.blob_uuid = b.uuid
             WHERE ma.blob_uuid IS NULL
             ON CONFLICT (blob_uuid) DO NOTHING'
        );
        $statement->execute(['tenant' => $tenantUuid]);

        return $statement->rowCount();
    }
}
