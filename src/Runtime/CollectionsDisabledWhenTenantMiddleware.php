<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Tenancy\System\SystemFlags;

/** Fences the unsupported collections subsystem while tenant scoping is active. */
final class CollectionsDisabledWhenTenantMiddleware implements RouteMiddleware
{
    public function __construct(private readonly SystemFlags $flags)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): Response
    {
        if ($this->flags->tenancyEnabled()) {
            return new Response(
                'Collections are unavailable while multi-tenancy is enabled.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $next($request);
    }
}
