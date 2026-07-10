<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobCreatedHook;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\ThalloTenantTables;

/** Proves that a fresh process enforces every SP1 boundary before it may report ON. */
final class FinalizationProbe
{
    private const CONTRACT_RESOLVER = 'Glueful\\Extensions\\Contracts\\Tenancy\\CurrentTenantResolver';
    private const CONTRACT_RUNNER = 'Glueful\\Extensions\\Contracts\\Tenancy\\TenantContextRunner';

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly Connection $db,
        private readonly TenantRuntimeReadiness $readiness,
        private readonly TenantCacheSegment $segment,
        private readonly ?TenantContextRunner $runner = null,
        private readonly ?TenantEnforcementProbe $enforcementProbe = null,
        private readonly ?BlobCreatedHook $blobCreatedHook = null,
        private readonly ?BlobAccessPolicy $blobAccessPolicy = null,
    ) {
    }

    public function passes(ApplicationContext $context): bool
    {
        return $this->report($context)['ok'];
    }

    /**
     * @return array{bindings:bool,blobPolicy:bool,enabled:bool,ready:bool,enforcement:bool,
     *     scopedQuery:bool,segment:bool,ok:bool}
     */
    public function report(ApplicationContext $context): array
    {
        $container = $context->getContainer();
        $bindings = $container->has(self::CONTRACT_RESOLVER) && $container->has(self::CONTRACT_RUNNER);
        $blobPolicy = $this->blobCreatedHook !== null
            && $this->blobAccessPolicy !== null
            && $this->blobCreatedHook === $this->blobAccessPolicy;
        $enabled = $this->flags->tenancyEnabled();
        $ready = $this->readiness->isReady($context);
        $defaultTenant = $this->flags->defaultTenantUuid() ?? '';

        $enforcement = $this->allOwnedTablesAreRegistered();
        $scopedQuery = false;
        $hasSegment = false;

        if (
            $bindings
            && $blobPolicy
            && $enabled
            && $ready
            && $enforcement
            && $defaultTenant !== ''
            && $this->runner !== null
        ) {
            try {
                $this->runner->runAsTenant(
                    $defaultTenant,
                    function () use (&$scopedQuery, &$hasSegment, $context): void {
                        $this->db->table('content_types')->select(['id'])->limit(1)->get();
                        $scopedQuery = true;
                        $hasSegment = str_starts_with($this->segment->segment($context, 'render'), 'tenant:');
                    },
                );
            } catch (\Throwable) {
                $scopedQuery = false;
                $hasSegment = false;
            }
        }

        $ok = $bindings
            && $blobPolicy
            && $enabled
            && $ready
            && $enforcement
            && $scopedQuery
            && $hasSegment;

        return [
            'bindings' => $bindings,
            'blobPolicy' => $blobPolicy,
            'enabled' => $enabled,
            'ready' => $ready,
            'enforcement' => $enforcement,
            'scopedQuery' => $scopedQuery,
            'segment' => $hasSegment,
            'ok' => $ok,
        ];
    }

    private function allOwnedTablesAreRegistered(): bool
    {
        if ($this->enforcementProbe === null) {
            return false;
        }

        foreach (ThalloTenantTables::tableNames() as $table) {
            if (!$this->enforcementProbe->isRegistered($table)) {
                return false;
            }
        }

        return true;
    }
}
