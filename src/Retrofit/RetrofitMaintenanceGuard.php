<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The retrofit write-barrier's state holder. `active()` is a PROCESS-LOCAL boolean — never a DB read.
 * {@see SystemFlags::get()} issues a builder SELECT, which re-enters the query interceptor; reading
 * persistence inside the per-query hot path would recurse forever. Persistence is therefore read only
 * by refresh() (once at boot, before the interceptor exists) and by the coarse assertWritable() gate
 * (off the hot path). Registered as a SHARED singleton so the interceptor, every WriteBarrier-injected
 * gate, and the orchestrator all see one in-memory flag.
 */
final class RetrofitMaintenanceGuard implements WriteBarrier
{
    private const KEY = 'tenancy.retrofit_active';

    /** Hot-path state; NEVER read from the DB inside the interceptor. */
    private bool $active = false;

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly MutationBoundaryLock $mutationLock,
    ) {
    }

    /** Called ONCE at boot, before the interceptor is registered — safe to read persistence. */
    public function refresh(): void
    {
        $this->flags->clearCache();
        $this->active = $this->flags->get(self::KEY) === '1';
    }

    public function begin(): void
    {
        $this->flags->put(self::KEY, '1'); // persisted for other processes
        $this->active = true;              // in-memory for this process's interceptor
    }

    public function end(): void
    {
        $this->flags->forget(self::KEY);
        $this->active = false;
    }

    /** Hot path: in-memory only (a DB read here would re-enter the interceptor → infinite recursion). */
    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Coarse boundary (raw-write sites, runners, jobs): re-read FRESH persisted state so an
     * already-running worker sees a mid-flight begin() OR end() from another process. The SELECT this
     * issues fires the interceptor's before(), but before() consults the in-memory bool (no recursion)
     * and, being a SELECT, is ignored regardless. Sync $active in BOTH directions — a worker that saw
     * the barrier rise must also see it fall, or it would reject writes forever after a remote end().
     */
    public function assertWritable(): void
    {
        $this->flags->clearCache();
        $persistedActive = $this->flags->get(self::KEY) === '1';
        $this->active = $persistedActive; // refresh in-memory both ways
        if ($persistedActive) {
            throw new RetrofitInProgressException();
        }
    }

    public function runWritable(callable $operation): mixed
    {
        $this->assertWritable();
        if (!$this->mutationLock->tryShared()) {
            throw new RetrofitInProgressException();
        }

        try {
            return $operation();
        } finally {
            $this->mutationLock->releaseShared();
        }
    }

    /**
     * Narrowly-scoped bypass for the retrofit's OWN builder writes while the barrier is up (e.g. the
     * settings reconciler's DELETE from the soon-to-be-owned `settings` table). Lowers only THIS
     * process's in-memory flag; the persisted flag stays '1' so other processes remain blocked. The
     * retrofit is single-threaded synchronous, so no concurrent write can slip through the window.
     */
    public function runInternal(callable $fn): mixed
    {
        $prev = $this->active;
        $this->active = false;
        try {
            return $fn();
        } finally {
            $this->active = $prev;
        }
    }
}
