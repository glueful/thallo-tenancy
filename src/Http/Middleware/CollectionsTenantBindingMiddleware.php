<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\ApiKeyBinding\TenantApiKeyBindingRepository;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

final class CollectionsTenantBindingMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TenantApiKeyBindingRepository $bindings,
        private readonly SingleStoreTenant $singleStore,
        private readonly ?CurrentTenantResolver $current = null,
        private readonly ?TenantContextRunner $runner = null,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $keyUuid = trim((string) $request->attributes->get('api_key_uuid', ''));
        $headerTenant = trim((string) $request->headers->get('X-Tenant-Id', ''));
        if ($keyUuid === '') {
            return $headerTenant === ''
                ? $next($request)
                : Response::forbidden('Anonymous collection requests cannot select a tenant explicitly.');
        }

        $boundTenant = $this->bindings->tenantFor($keyUuid);
        if ($boundTenant === null) {
            return Response::forbidden('This API key is not bound to a workspace.');
        }
        $resolvedTenant = $this->current?->tenantUuid($this->context) ?? $this->singleStore->resolve();
        if (
            ($headerTenant !== '' && $headerTenant !== $boundTenant)
            || ($resolvedTenant !== '' && $resolvedTenant !== $boundTenant)
        ) {
            return Response::forbidden('The API key workspace binding conflicts with the selected workspace.');
        }

        return $this->runner !== null
            ? $this->runner->runAsTenant($boundTenant, static fn () => $next($request))
            : $next($request);
    }
}
