<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Reverification;

use Glueful\Database\Connection;

/** Owns the dedicated PostgreSQL session lock for one global sweep. */
final class DomainReverificationSweepLock
{
    private const LOCK = "SELECT pg_try_advisory_lock(hashtextextended('tenancy:reverify:sweep', 0))";
    private const UNLOCK = "SELECT pg_advisory_unlock(hashtextextended('tenancy:reverify:sweep', 0))";

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Returns false when another sweep owns the lock. */
    public function run(callable $work): bool
    {
        $pdo = $this->connection->newPdo();
        if (!$this->isTrue($pdo->query(self::LOCK)->fetchColumn())) {
            return false;
        }

        try {
            $work();
        } finally {
            $released = $pdo->query(self::UNLOCK)->fetchColumn();
            if (!$this->isTrue($released)) {
                throw new \RuntimeException('Domain re-verification sweep lock was not released.');
            }
        }

        return true;
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
