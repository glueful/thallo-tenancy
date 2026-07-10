<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Database\Connection;

/** Serializes enablement state transitions across processes on the active PostgreSQL database. */
final class EnablementLock
{
    private const LOCK_KEY = 4823710;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @template T @param callable(): T $operation @return T */
    public function withLock(callable $operation): mixed
    {
        $pdo = $this->connection->getPDO();
        $acquire = $pdo->prepare('SELECT pg_try_advisory_lock(:key)');
        $acquire->execute(['key' => self::LOCK_KEY]);

        if ($acquire->fetchColumn() !== true) {
            throw new EnablementLockedException();
        }

        try {
            return $operation();
        } finally {
            $release = $pdo->prepare('SELECT pg_advisory_unlock(:key)');
            $release->execute(['key' => self::LOCK_KEY]);
        }
    }
}
