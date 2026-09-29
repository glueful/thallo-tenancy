<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Adoption;

use Glueful\Database\Connection;
use PDO;
use PDOException;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use WeakMap;
use WeakReference;

/**
 * Keeps single-store work and the adoption flip apart (PostgreSQL shared/exclusive advisory lock).
 *
 * A unit of work — a request, a job, a command — that resolves the single store's '' tenant
 * {@see holdShared() holds} the gate shared from BEFORE it reads the tenancy mode until it ends:
 * the lock lives on the unit's own database session, so it costs one round trip and no extra
 * connection, and it ends with the session, or earlier through {@see release()}. The flip that
 * widens the schema and moves unassigned rows into the default tenant closes the gate
 * {@see acquireExclusive() exclusively} around that move, from a session of its own. So no unit that
 * saw the single store can overlap the move — none reads an emptied partition or writes a fresh ''
 * row after it — and a unit that tries during the move is refused instead of waiting.
 *
 * Holds are kept per database session, not per instance: Connection shares one session per process
 * and configuration, so a second container booted in the process (tests do) sees and can release the
 * same hold. A session that reconnects mid-unit has lost its lock with it; the next hold then fails
 * closed rather than carry on as if nothing had happened, because what the unit read before may
 * already be stale.
 *
 * Deliberately NOT {@see \Thallo\Tenancy\Retrofit\MutationBoundaryLock}'s key: that lock is held
 * per statement and taken without a timeout; this one is held for a whole unit and waited on with
 * one, so a worker that holds it indefinitely fails the flip resumably instead of hanging it.
 * Other drivers have no retrofit, so the gate is open there.
 */
final class AdoptionGate
{
    private const KEY = 4823712;

    /** @var WeakMap<PDO, true>|null the sessions that hold the gate shared */
    private static ?WeakMap $holds = null;

    /** @var WeakReference<PDO>|null the session this gate last held on */
    private ?WeakReference $heldOn = null;

    private ?PDO $maintenancePdo = null;
    private bool $exclusive = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Hold the gate shared for the rest of this unit of work; false while a flip holds it.
     *
     * @throws RetrofitInProgressException when the unit's session reconnected and lost its hold
     */
    public function holdShared(): bool
    {
        if (!$this->supported()) {
            return true;
        }
        $pdo = $this->connection->getPDO();
        if (isset(self::holds()[$pdo])) {
            return true;
        }
        if ($this->heldOn !== null) {
            $lostOn = $this->heldOn->get();
            $this->heldOn = null;
            if ($lostOn !== $pdo) {
                throw new RetrofitInProgressException();
            }
        }

        $value = $pdo->query('SELECT pg_try_advisory_lock_shared(' . self::KEY . ')')->fetchColumn();
        if (!($value === true || $value === '1' || $value === 1 || $value === 't')) {
            return false;
        }
        self::holds()[$pdo] = true;
        $this->heldOn = WeakReference::create($pdo);

        return true;
    }

    public function isHeld(): bool
    {
        return $this->supported() && isset(self::holds()[$this->connection->getPDO()]);
    }

    /** End this unit of work's hold early (the session's close ends it otherwise). */
    public function release(): void
    {
        $this->heldOn = null;
        if (!$this->supported()) {
            return;
        }
        $pdo = $this->connection->getPDO();
        if (!isset(self::holds()[$pdo])) {
            return;
        }
        unset(self::holds()[$pdo]);
        try {
            $pdo->query('SELECT pg_advisory_unlock_shared(' . self::KEY . ')')->fetchColumn();
        } catch (PDOException) {
            // The session is gone, and its lock with it.
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

    /** @return WeakMap<PDO, true> */
    private static function holds(): WeakMap
    {
        return self::$holds ??= new WeakMap();
    }

    private function supported(): bool
    {
        return $this->connection->getDriverName() === 'pgsql';
    }

    private function maintenance(): PDO
    {
        return $this->maintenancePdo ??= $this->connection->newPdo();
    }
}
