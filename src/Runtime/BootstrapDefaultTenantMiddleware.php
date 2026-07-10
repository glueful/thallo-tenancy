<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Tenancy\System\SystemFlags;

/** Resolves SP1 tenant-data requests into the single bootstrap tenant. */
final class BootstrapDefaultTenantMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly TenantRuntimeReadiness $readiness,
        private readonly ?CurrentTenantResolver $resolver = null,
        private readonly ?TenantContextRunner $runner = null,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): Response
    {
        if (!$this->flags->tenancyEnabled()) {
            return $next($request);
        }

        if ($this->resolver !== null && $this->resolver->tenantUuid($this->context) !== '') {
            return $next($request);
        }

        if (
            $this->runner === null
            || $this->readiness->mode($this->context) !== TenantRuntimeReadiness::MODE_BOOTSTRAP_DEFAULT
        ) {
            return new Response('Tenant resolution unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $defaultTenant = $this->flags->defaultTenantUuid();
        if ($defaultTenant === null) {
            return new Response('Tenant resolution unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->runner->runAsTenant(
            $defaultTenant,
            static fn (): Response => $next($request),
        );
    }
}
