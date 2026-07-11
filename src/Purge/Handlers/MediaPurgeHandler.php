<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge\Handlers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Storage\StorageManager;
use PDO;
use Thallo\Tenancy\Purge\PurgeHandler;

final class MediaPurgeHandler implements PurgeHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StorageManager $storage,
    ) {
    }

    public function id(): string
    {
        return 'thallo.media';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function prepare(ApplicationContext $context, string $tenantUuid): array
    {
        if (!$this->exists('media_assets')) {
            return ['objects' => [], 'blob_uuids' => []];
        }
        $statement = $this->connection->getPDO()->prepare(
            'SELECT b.uuid, b.storage_type, b.url FROM media_assets ma '
            . 'JOIN blobs b ON b.uuid = ma.blob_uuid WHERE ma.tenant_uuid = ? ORDER BY b.uuid'
        );
        $statement->execute([$tenantUuid]);
        $objects = [];
        $blobUuids = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $blobUuids[] = (string) $row['uuid'];
            $objects[] = [
                'blob_uuid' => (string) $row['uuid'],
                'disk' => (string) $row['storage_type'],
                'path' => (string) $row['url'],
            ];
        }
        return ['objects' => $objects, 'blob_uuids' => $blobUuids];
    }

    public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void
    {
        $objects = $artifacts['objects'] ?? null;
        $blobUuids = $artifacts['blob_uuids'] ?? null;
        if (!is_array($objects) || !is_array($blobUuids)) {
            throw new \RuntimeException('Invalid media purge manifest.');
        }
        foreach ($objects as $object) {
            if (!is_array($object) || !is_string($object['disk'] ?? null) || !is_string($object['path'] ?? null)) {
                throw new \RuntimeException('Invalid storage object in media purge manifest.');
            }
            $this->storage->disk($object['disk'])->delete($object['path']);
        }

        foreach (['media_usage', 'media_meta', 'media_assets'] as $table) {
            if ($this->exists($table)) {
                $this->connection->table($table)->where('tenant_uuid', $tenantUuid)->forceDelete();
            }
        }
        foreach ($blobUuids as $blobUuid) {
            if (!is_string($blobUuid) || preg_match('/\A[0-9A-Za-z]{12}\z/', $blobUuid) !== 1) {
                throw new \RuntimeException('Invalid blob UUID in media purge manifest.');
            }
            $this->connection->table('blobs')->where('uuid', $blobUuid)->forceDelete();
        }
    }

    public function verify(ApplicationContext $context, string $tenantUuid): bool
    {
        foreach (['media_usage', 'media_meta', 'media_assets'] as $table) {
            if (!$this->exists($table)) {
                continue;
            }
            $statement = $this->connection->getPDO()->prepare(
                "SELECT 1 FROM {$table} WHERE tenant_uuid = ? LIMIT 1"
            );
            $statement->execute([$tenantUuid]);
            if ($statement->fetchColumn() !== false) {
                return false;
            }
        }
        return true;
    }

    private function exists(string $table): bool
    {
        $statement = $this->connection->getPDO()->prepare('SELECT to_regclass(?)');
        $statement->execute([$table]);
        return $statement->fetchColumn() !== null;
    }
}
