<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantResolutionProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Routing\RouteCache;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\System\SystemFlags;

/** Resumable activation of host/header-based full resolution. */
final class FullResolutionActivation
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ResolutionActivationStore $store,
        private readonly EnablementLock $lock,
        private readonly SystemFlags $flags,
        private readonly TenantDomainAdministration $domains,
        private readonly TenantResolutionProbe $probe,
        private readonly TenantRuntimeReadiness $readiness,
        private readonly ?TenantAdministration $tenants = null,
    ) {
    }

    /** @return array{step:string,mode:string,failure:?string,fresh_boot_required:bool} */
    public function status(): array
    {
        $step = $this->store->step();

        return [
            'step' => $step->value,
            'mode' => $this->readiness->mode($this->context),
            'failure' => $this->store->failure(),
            'fresh_boot_required' => $step === ResolutionActivationStep::AWAITING_FRESH_BOOT,
        ];
    }

    /** @return array{step:string,mode:string,failure:?string,fresh_boot_required:bool} */
    public function advance(): array
    {
        return $this->lock->withLock(function (): array {
            $step = $this->store->step();
            try {
                $this->assertCanActivate();
                match ($step) {
                    ResolutionActivationStep::INACTIVE => $this->move(
                        $step,
                        ResolutionActivationStep::MAPPING_HOSTS
                    ),
                    ResolutionActivationStep::MAPPING_HOSTS => $this->mapHosts(),
                    ResolutionActivationStep::VERIFYING_WIRING => $this->verifyWiring(),
                    ResolutionActivationStep::REBUILDING_ROUTES => $this->rebuildRoutes(),
                    ResolutionActivationStep::AWAITING_FRESH_BOOT => $this->complete(),
                    ResolutionActivationStep::FULL => null,
                    ResolutionActivationStep::FAILED => throw new EnablementException(
                        'Resolution activation failed; retry it before advancing.'
                    ),
                };
            } catch (\Throwable $e) {
                if ($step !== ResolutionActivationStep::FAILED) {
                    $this->store->recordFailure($step, $e->getMessage());
                }
            }

            return $this->status();
        });
    }

    /** @return array{step:string,mode:string,failure:?string,fresh_boot_required:bool} */
    public function retry(): array
    {
        return $this->lock->withLock(function (): array {
            if (!$this->store->retry()) {
                throw new EnablementException('Resolution activation is not retryable.');
            }

            return $this->status();
        });
    }

    /** @return array{step:string,mode:string,failure:?string,fresh_boot_required:bool} */
    public function deactivate(): array
    {
        return $this->lock->withLock(function (): array {
            if ($this->store->step() !== ResolutionActivationStep::FULL) {
                throw new EnablementException('Full tenant resolution is not active.');
            }
            if ($this->tenants === null || count($this->tenants->listTenants($this->context)) !== 1) {
                throw new EnablementException('Resolution deactivation requires exactly one tenant.');
            }
            if (!$this->store->deactivate(ResolutionActivationStep::FULL)) {
                throw new EnablementException('Resolution deactivation state changed concurrently.');
            }

            $container = $this->context->getContainer();
            if ($container->has(RouteCache::class)) {
                $container->get(RouteCache::class)->clear();
            }

            return $this->status();
        });
    }

    private function assertCanActivate(): void
    {
        if (!$this->flags->tenancyEnabled() || $this->flags->get('tenancy.enable_step') !== 'on') {
            throw new EnablementException('SP1 tenancy enablement must be ON before full resolution.');
        }
    }

    private function mapHosts(): void
    {
        $default = $this->flags->defaultTenantUuid();
        if ($default === null) {
            throw new EnablementException('The default tenant pointer is missing.');
        }
        foreach ($this->requiredHosts() as $host) {
            $this->domains->addPreverifiedDomain($this->context, $default, $host);
        }
        $this->move(ResolutionActivationStep::MAPPING_HOSTS, ResolutionActivationStep::VERIFYING_WIRING);
    }

    private function verifyWiring(): void
    {
        $default = $this->flags->defaultTenantUuid();
        foreach ($this->requiredHosts() as $host) {
            if ($default === null || $this->probe->probePublicHost($this->context, $host) !== $default) {
                throw new EnablementException("Required host does not resolve to the default tenant: {$host}");
            }
        }
        $this->move(
            ResolutionActivationStep::VERIFYING_WIRING,
            ResolutionActivationStep::REBUILDING_ROUTES
        );
    }

    private function rebuildRoutes(): void
    {
        $container = $this->context->getContainer();
        if ($container->has(RouteCache::class)) {
            $container->get(RouteCache::class)->clear();
        }
        if (!$this->store->markAwaitingFreshBoot(ResolutionActivationStep::REBUILDING_ROUTES)) {
            throw new EnablementException('Resolution activation state changed concurrently.');
        }
    }

    private function complete(): void
    {
        $default = $this->flags->defaultTenantUuid();
        foreach ($this->requiredHosts() as $host) {
            if ($default === null || $this->probe->probePublicHost($this->context, $host) !== $default) {
                throw new EnablementException("Fresh-boot host probe failed: {$host}");
            }
        }
        if (!$this->store->completeFull(ResolutionActivationStep::AWAITING_FRESH_BOOT)) {
            throw new EnablementException('Resolution activation state changed concurrently.');
        }
    }

    private function move(ResolutionActivationStep $from, ResolutionActivationStep $to): void
    {
        if (!$this->store->compareAndSet($from, $to)) {
            throw new EnablementException('Resolution activation state changed concurrently.');
        }
    }

    /** @return list<string> */
    private function requiredHosts(): array
    {
        $hosts = config($this->context, 'tenancy.public_origin.default_hosts', []);
        if (!is_array($hosts) || $hosts === []) {
            throw new EnablementException('At least one default tenant host must be configured.');
        }

        return array_values(array_filter($hosts, 'is_string'));
    }
}
