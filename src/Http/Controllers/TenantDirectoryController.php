<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Http\Response;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;

final class TenantDirectoryController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?TenantAdministration $tenants = null,
    ) {
    }

    public function mine(Request $request): Response
    {
        if ($this->tenants === null) {
            return Response::success(['tenants' => []]);
        }
        $actor = $this->actor($request);
        if ($actor === null) {
            return Response::unauthorized();
        }

        return Response::success(['tenants' => $this->hasSystemAccess($actor)
            ? $this->tenants->listTenants($this->context, 'active')
            : $this->tenants->listTenantsForUser($this->context, $actor)]);
    }

    private function actor(Request $request): ?string
    {
        $identity = $request->attributes->get('auth.user');
        if ($identity instanceof UserIdentity) {
            return $identity->uuid();
        }
        $user = $request->attributes->get('user');

        return is_array($user) && is_string($user['uuid'] ?? null) ? $user['uuid'] : null;
    }

    private function hasSystemAccess(string $actor): bool
    {
        try {
            return PermissionManager::getInstance(null, $this->context)
                ->can($actor, 'system.access', 'thallo');
        } catch (\Throwable) {
            return false;
        }
    }
}
