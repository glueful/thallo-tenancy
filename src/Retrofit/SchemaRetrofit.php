<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;
use RuntimeException;
use Thallo\Contracts\Settings\SystemKeyReconciler;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Enable-time schema-retrofit orchestrator: turns a single-tenant Thallo install into a row-level
 * multi-tenant one by composing the retrofit engine pieces in a strict, resumable order. It is a
 * service invoked by an operation — never an ambient migration (spec §7.4).
 *
 * {@see run()} ordering (each step below the barrier is individually idempotent, so a second run after a
 * completed retrofit is a no-op-ish success and a run resumed after an interrupt completes):
 *   1. DRIVER GATE — {@see RetrofitDdlFactory::for()} throws {@see UnsupportedRetrofitDriverException} on
 *      any non-supported driver BEFORE any mutation (Thallo v1 is Postgres-only).
 *   2. RAISE the write-barrier ({@see RetrofitMaintenanceGuard::begin()}).
 *   3. PROVISION the default tenant + owner membership. This writes the NON-owned tenants/
 *      tenant_memberships registry, so the barrier does not block it.
 *   4. UNIQUENESS PREFLIGHT — if any business key would collide once every row shares the default
 *      tenant, LOWER the barrier and throw. This is the ONLY path that lowers the barrier: it fails
 *      BEFORE any schema mutation, so nothing is left half-widened.
 *   5. RECONCILE legacy system keys out of the soon-to-be-owned `settings` table. The reconciler DELETEs
 *      via the builder, which the interceptor would reject — so it (and ONLY it) runs through
 *      {@see RetrofitMaintenanceGuard::runInternal()}, which lowers only THIS process's in-memory flag
 *      (the persisted flag stays up, other processes stay blocked).
 *   6. WIDEN each PRESENT owned table — rebuild tables via {@see TableRebuilder}, the rest via
 *      {@see AdditiveRetrofit}. Both run raw PDO, bypassing the barrier by design; absent tables are
 *      skipped (an uninstalled pack's tables are not a divergence).
 *   7. ASSERT every present owned table is coherently widened. On any incoherence, throw — the barrier
 *      stays UP (a mid-failure keeps writes blocked for Phase E to resolve).
 *   8. RECORD schema_state = 'widened'.
 *   9. ASSERT the flag agrees with the live schema.
 *  10. LEAVE the barrier UP on success — Phase E lowers it atomically with the transition to `on`.
 */
final class SchemaRetrofit
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaIntrospector $introspector,
        private readonly RetrofitDdlFactory $ddlFactory,
        private readonly RetrofitMaintenanceGuard $guard,
        private readonly DefaultTenant $defaultTenant,
        private readonly UniquenessPreflight $preflight,
        private readonly SystemKeyReconciler $reconciler,
        private readonly AdditiveRetrofit $additive,
        private readonly TableRebuilder $rebuilder,
        private readonly RetrofitDiagnostics $diagnostics,
        private readonly SystemFlags $flags,
        private readonly MediaOwnershipBackfill $mediaOwnership,
        private readonly MutationBoundaryLock $mutationLock,
        private readonly AdoptionGate $adoptionGate,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $adoptAtFlip adopts unassigned rows into the default
     *        tenant (it receives its uuid) inside the flip's transaction — see step 8
     * @param int $adoptionGateTimeoutMs how long the flip waits for single-store work to end
     */
    public function run(
        string $slug,
        string $name,
        string $ownerUserUuid,
        ?\Closure $adoptAtFlip = null,
        int $adoptionGateTimeoutMs = 10000,
    ): RetrofitReport {
        // 1. Driver gate — reject unsupported drivers before touching anything.
        $this->ddlFactory->for($this->introspector->driver());

        // 2. Raise the barrier (persisted + in-memory) so every builder writer is refused.
        $this->guard->begin();

        // 3. Provision (or resume provisioning of) the default tenant + owner membership. The tenants
        //    registry is NOT owned, so this write is not barrier-blocked.
        $tenantUuid = $this->defaultTenant->ensure($slug, $name, $ownerUserUuid);

        // 4. Prove no pre-existing duplicates. A violation fails BEFORE any schema mutation, so the
        //    barrier comes back DOWN here (and only here).
        $preflightReport = $this->preflight->check();
        if ($preflightReport->hasViolations()) {
            $this->guard->end();
            $preflightReport->throwIfFailed();
        }

        // 5. Move legacy system keys out of the soon-to-be-owned `settings` table. The reconciler's
        //    builder DELETE must bypass the barrier — and ONLY this call does.
        /** @var list<string> $movedKeys */
        $movedKeys = $this->guard->runInternal(fn (): array => $this->reconciler->reconcile());

        $this->mutationLock->acquireExclusive();
        try {
            // Existing framework blobs predate tenant ownership. Attribute them before the additive
            // media_assets path promotes tenant_uuid to NOT NULL.
            $this->mediaOwnership->run($tenantUuid);

            // 6. Widen each present owned table. Raw PDO — bypasses the barrier by design.
            $widened = [];
            foreach (ThalloTenantTables::all() as $table => $meta) {
                if (!$this->tableExists($table)) {
                    continue; // absent (uninstalled pack) — skip it, not a divergence
                }
                if ($meta['special_backfill'] === 'rebuild') {
                    $this->rebuilder->rebuild($table);
                } else {
                    $this->additive->apply($table);
                }
                $widened[] = $table;
            }

            // 7. Every present owned table must be coherent. A failure leaves the barrier UP.
            $incoherent = [];
            foreach ($this->diagnostics->checkTables() as $table => $result) {
                if ($result['ok'] === false) {
                    $incoherent[] = $table . ' (' . $result['detail'] . ')';
                }
            }
            if ($incoherent !== []) {
                throw new RuntimeException(
                    'Schema retrofit incoherent — table(s) not fully widened: '
                    . implode('; ', $incoherent) . '.'
                );
            }

            // 8. The flip: record the widened schema state and adopt unassigned rows into the
            //    default tenant in ONE transaction, with the adoption gate closed. Resolvers that
            //    answer from the schema state (the single store's '' before, the default tenant
            //    after) never see the new state before the rows it points at are there, and no unit
            //    of work that saw the single store overlaps the move — one still running fails the
            //    flip instead (AdoptionGateBusyException, resumable). This process is not in the
            //    middle of single-store work, so its own hold, if any, ends first.
            $this->adoptionGate->release();
            $this->adoptionGate->acquireExclusive($adoptionGateTimeoutMs);
            try {
                $this->connection->transaction(function () use ($adoptAtFlip, $tenantUuid): void {
                    if ($adoptAtFlip !== null) {
                        $adoptAtFlip($tenantUuid);
                    }
                    $this->flags->put('tenancy.schema_state', 'widened');
                });
            } finally {
                $this->adoptionGate->releaseExclusive();
            }

            // 9. The persisted flag must agree with the live schema.
            $agreement = $this->diagnostics->checkAgreement();
            if ($agreement['ok'] === false) {
                throw new RuntimeException(
                    'Schema retrofit flag/reality disagreement after widening: '
                    . (string) json_encode($agreement['detail']) . '.'
                );
            }

            // Barrier stays UP on success — Phase E lowers it with the transition to `on`.
            return new RetrofitReport($tenantUuid, $widened, $movedKeys);
        } finally {
            $this->mutationLock->releaseExclusive();
        }
    }

    /** Live table presence — never a phase marker or cached declaration. */
    private function tableExists(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }
}
