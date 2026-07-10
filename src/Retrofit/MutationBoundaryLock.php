<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;
use PDO;

/** PostgreSQL shared/exclusive advisory lock for tenant-owned mutation quiescence. */
final class MutationBoundaryLock
{
    private const KEY = 4823711;

    private ?PDO $participantPdo = null;
    private ?PDO $maintenancePdo = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function acquireExclusive(): void
    {
        $this->maintenance()->exec('SELECT pg_advisory_lock(' . self::KEY . ')');
    }

    public function releaseExclusive(): void
    {
        $this->maintenance()->exec('SELECT pg_advisory_unlock(' . self::KEY . ')');
    }

    public function tryShared(): bool
    {
        $value = $this->participant()
            ->query('SELECT pg_try_advisory_lock_shared(' . self::KEY . ')')
            ->fetchColumn();

        return $value === true || $value === '1' || $value === 1 || $value === 't';
    }

    public function releaseShared(): void
    {
        $this->participant()->exec('SELECT pg_advisory_unlock_shared(' . self::KEY . ')');
    }

    private function participant(): PDO
    {
        return $this->participantPdo ??= $this->connection->newPdo();
    }

    private function maintenance(): PDO
    {
        return $this->maintenancePdo ??= $this->connection->newPdo();
    }
}
