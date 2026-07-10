<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantRequestMiddleware;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/** Inert host proxy that delegates to the tenancy extension only after full activation. */
final class TenantProfileMiddleware implements RouteMiddleware
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $container = $this->context->getContainer();
        $ready = $container->has(FullTenantResolutionReadiness::class)
            && $container->get(FullTenantResolutionReadiness::class)->isReady($this->context);
        if (!$ready) {
            return $next($request);
        }
        if (!$container->has(TenantRequestMiddleware::class)) {
            return Response::error('Tenant resolution is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $container->get(TenantRequestMiddleware::class)->handle($request, $next, ...$params);
    }
}
