<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Adoption;

use Glueful\Database\Connection;
use PDO;
use PDOException;

/**
 * Keeps single-store work and the adoption flip apart (PostgreSQL shared/exclusive advisory lock).
 *
 * A unit of work — a request, a job, a command — that resolves the single store's '' tenant
 * {@see holdShared() holds} the gate shared from BEFORE it reads the tenancy mode until it ends:
 * the lock lives on a dedicated session that closes with the process, or earlier through
 * {@see release()}. The flip that widens the schema and moves unassigned rows into the default
 * tenant closes the gate {@see acquireExclusive() exclusively} around that move. So no unit that
 * saw the single store can overlap the move — none reads an emptied partition or writes a fresh ''
 * row after it — and a unit that tries during the move is refused instead of waiting.
 *
 * Deliberately NOT {@see \Thallo\Tenancy\Retrofit\MutationBoundaryLock}'s key: that lock is held
 * per statement and taken without a timeout; this one is held for a whole unit and waited on with
 * one, so a worker that holds it indefinitely fails the flip resumably instead of hanging it.
 * The hold belongs to the PROCESS, not to one instance: a process is one unit of work, and a
 * second container booted in it (tests do) must neither hold twice nor keep a hold nobody can
 * release. It is kept per database — advisory locks never conflict across databases, so a hold
 * taken in another database guards nothing here. Other drivers have no retrofit, so the gate is
 * open there.
 */
final class AdoptionGate
{
    private const KEY = 4823712;

    private static ?PDO $participantPdo = null;
    private static ?string $participantDatabase = null;
    private static bool $held = false;
    private ?PDO $maintenancePdo = null;
    private ?string $database = null;
    private bool $exclusive = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Hold the gate shared for the rest of this unit of work; false while a flip holds it. */
    public function holdShared(): bool
    {
        if (!$this->supported() || $this->isHeld()) {
            return true;
        }

        $value = $this->participant()
            ->query('SELECT pg_try_advisory_lock_shared(' . self::KEY . ')')
            ->fetchColumn();

        return self::$held = ($value === true || $value === '1' || $value === 1 || $value === 't');
    }

    public function isHeld(): bool
    {
        return self::$held && $this->supported() && self::$participantDatabase === $this->database();
    }

    /** End this unit of work's hold early (the session's close ends it otherwise). */
    public function release(): void
    {
        if (!$this->isHeld()) {
            return;
        }
        try {
            $this->participant()->exec('SELECT pg_advisory_unlock_shared(' . self::KEY . ')');
        } catch (PDOException) {
            self::$participantPdo = null; // the session is gone, and its lock with it
        } finally {
            self::$held = false;
        }
    }

    /**
     * Close the gate: wait up to $timeoutMs for every holding unit of work to end, refusing new ones
     * meanwhile.
     *
     * @throws AdoptionGateBusyException when a unit of work still holds it after $timeoutMs
     */
    public function acquireExclusive(int $timeoutMs): void
    {
        if ($this->exclusive || !$this->supported()) {
            return;
        }

        $pdo = $this->maintenance();
        $pdo->exec('SET lock_timeout = ' . max(1, $timeoutMs));
        try {
            $pdo->query('SELECT pg_advisory_lock(' . self::KEY . ')')->fetchColumn();
            $this->exclusive = true;
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) === '55P03') { // lock_not_available
                throw new AdoptionGateBusyException($timeoutMs);
            }
            throw $e;
        } finally {
            $pdo->exec('SET lock_timeout = 0');
        }
    }

    public function holdsExclusive(): bool
    {
        return $this->exclusive;
    }

    public function releaseExclusive(): void
    {
        if (!$this->exclusive) {
            return;
        }
        $this->maintenance()->exec('SELECT pg_advisory_unlock(' . self::KEY . ')');
        $this->exclusive = false;
    }

    private function supported(): bool
    {
        return $this->connection->getDriverName() === 'pgsql';
    }

    private function participant(): PDO
    {
        if (self::$participantDatabase !== $this->database()) {
            self::$participantPdo = null; // closing another database's session ends its hold there
            self::$held = false;
            self::$participantDatabase = $this->database();
        }

        return self::$participantPdo ??= $this->connection->newPdo();
    }

    private function database(): string
    {
        return $this->database ??= (string) $this->connection->getPDO()
            ->query('SELECT current_database()')
            ->fetchColumn();
    }

    private function maintenance(): PDO
    {
        return $this->maintenancePdo ??= $this->connection->newPdo();
    }
}
