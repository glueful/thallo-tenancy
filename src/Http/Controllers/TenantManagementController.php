<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\Contracts\TenantSeedActivator;
use Thallo\Tenancy\Runtime\BootstrapTenantCreationGuard;
use Thallo\Tenancy\StarterSeedException;

final class TenantManagementController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly BootstrapTenantCreationGuard $creationGuard,
        private readonly ?TenantAdministration $tenants = null,
        private readonly ?TenantSeedActivator $seeder = null,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        $status = $request->query->get('status');

        return Response::success(['tenants' => $this->tenants->listTenants(
            $this->context,
            is_string($status) && $status !== '' ? $status : null
        )]);
    }

    public function create(Request $request): Response
    {
        if ($this->tenants === null || $this->seeder === null) {
            return $this->unavailable();
        }
        $body = $this->body($request);
        $slug = is_string($body['slug'] ?? null) ? trim($body['slug']) : '';
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $owner = $this->actor($request);
        if ($slug === '' || $name === '' || $owner === null) {
            return Response::validation(['tenant' => 'Slug, name, and an authenticated owner are required.']);
        }

        try {
            $this->creationGuard->assertCanCreateTenant();
            $uuid = $this->tenants->create($this->context, $slug, $name, $owner);
            try {
                $this->seeder->seedAndActivate($uuid, $owner);
            } catch (\Throwable $e) {
                return Response::error(
                    'Tenant was created but starter seeding failed.',
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    [
                        'tenant_uuid' => $uuid,
                        'status' => 'provisioning',
                        'failed_definition' => $e instanceof StarterSeedException
                            ? $e->definitionLabel
                            : null,
                        'repair_command' => 'php glueful thallo:tenant:seed ' . $uuid,
                    ],
                );
            }

            return Response::created(['uuid' => $uuid, 'status' => 'active']);
        } catch (EnablementException | \InvalidArgumentException | \DomainException $e) {
            return Response::validation(['tenant' => $e->getMessage()]);
        }
    }

    public function suspend(string $uuid): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        return $this->transition(function () use ($uuid): void {
            $this->tenants->suspend($this->context, $uuid);
        });
    }

    public function reactivate(string $uuid): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        return $this->transition(function () use ($uuid): void {
            $this->tenants->reactivate($this->context, $uuid);
        });
    }

    /** @param callable():void $operation */
    private function transition(callable $operation): Response
    {
        try {
            $operation();
            return Response::success();
        } catch (\RuntimeException | \DomainException $e) {
            return Response::validation(['tenant' => $e->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        return is_array($body) ? $body : [];
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

    private function unavailable(): Response
    {
        return Response::error(
            'Tenant administration is unavailable.',
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }
}
