<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Explicit classification marker for system-global Thallo routes. */
final class TenantSystemMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): Response
    {
        return $next($request);
    }
}
