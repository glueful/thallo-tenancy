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
use Thallo\Tenancy\Contracts\TenantSeedRepair;
use Thallo\Tenancy\Runtime\BootstrapTenantCreationGuard;
use Thallo\Tenancy\StarterSeedException;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Thallo\Tenancy\Purge\PurgeCoordinator;
use Thallo\Tenancy\Purge\PurgeRunRepository;

final class TenantManagementController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly BootstrapTenantCreationGuard $creationGuard,
        private readonly ?TenantAdministration $tenants = null,
        private readonly ?TenantSeedActivator $seeder = null,
        private readonly ?TenantSeedRepair $seedRepair = null,
        private readonly ?PurgeCoordinator $purges = null,
        private readonly ?TenancyLifecycleAudit $audit = null,
        private readonly ?TenantManagementServices $services = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenants = $this->tenantAdministration();
        if ($tenants === null) {
            return Response::success(['tenants' => []]);
        }
        $status = $request->query->get('status');

        return Response::success(['tenants' => $this->withPurgeProgress($tenants->listTenants(
            $this->context,
            is_string($status) && $status !== '' ? $status : null
        ))]);
    }

    /**
     * Honest `purging` tags: a purging tenant additionally reports `purge_stalled` — true when
     * its run needs operator attention (no worker consuming, dispatch/job failure, dead lease
     * — {@see PurgeRunRepository::isStalled()}), so the UI can say "waiting for a worker"
     * instead of an indefinitely spinning tag. Non-purging tenants are untouched.
     *
     * @param list<array<string, mixed>> $tenants
     * @return list<array<string, mixed>>
     */
    private function withPurgeProgress(array $tenants): array
    {
        $runs = $this->services?->purgeRuns();
        if ($runs === null) {
            return $tenants;
        }

        foreach ($tenants as &$tenant) {
            if (($tenant['status'] ?? null) !== 'purging') {
                continue;
            }
            try {
                $run = $runs->findByTenant($this->context, (string) ($tenant['uuid'] ?? ''));
                $tenant['purge_stalled'] = $run !== null && PurgeRunRepository::isStalled($run);
            } catch (\Throwable) {
                // A progress read must never break the list itself.
            }
        }

        return $tenants;
    }

    public function create(Request $request): Response
    {
        $tenants = $this->tenantAdministration();
        $seeder = $this->seedActivator();
        if ($tenants === null || $seeder === null) {
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
            $uuid = $tenants->create($this->context, $slug, $name, $owner);
            try {
                $seeder->seedAndActivate($uuid, $owner);
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
        $tenants = $this->tenantAdministration();
        if ($tenants === null) {
            return $this->unavailable();
        }
        return $this->transition(function () use ($tenants, $uuid): void {
            $tenants->suspend($this->context, $uuid);
        });
    }

    public function reactivate(string $uuid): Response
    {
        $tenants = $this->tenantAdministration();
        if ($tenants === null) {
            return $this->unavailable();
        }
        return $this->transition(function () use ($tenants, $uuid): void {
            $tenants->reactivate($this->context, $uuid);
        });
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $tenants = $this->tenantAdministration();
        if ($tenants === null) {
            return $this->unavailable();
        }
        if (($this->body($request)['confirm'] ?? null) !== true) {
            return Response::validation(['confirm' => 'Workspace deletion must be explicitly confirmed.']);
        }
        if ($this->isSelected($request, $uuid)) {
            return Response::error('Switch away from this workspace before deleting it.', Response::HTTP_CONFLICT);
        }
        try {
            $tenants->deleteTenant($this->context, $uuid);
            $this->lifecycleAudit()?->record('tenant.deleted', $this->actor($request), $uuid);
            return Response::success(['tenant' => $tenants->getTenantLifecycle($this->context, $uuid)]);
        } catch (\DomainException | \RuntimeException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
    }

    public function restore(Request $request, string $uuid): Response
    {
        $tenants = $this->tenantAdministration();
        if ($tenants === null) {
            return $this->unavailable();
        }
        try {
            $tenants->restoreTenant($this->context, $uuid);
            $this->lifecycleAudit()?->record('tenant.restored', $this->actor($request), $uuid);
            return Response::success(['tenant' => $tenants->getTenantLifecycle($this->context, $uuid)]);
        } catch (\DomainException | \RuntimeException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
    }

    public function purge(Request $request, string $uuid): Response
    {
        $tenants = $this->tenantAdministration();
        $purges = $this->purgeCoordinator();
        if ($tenants === null || $purges === null) {
            return $this->unavailable();
        }
        if ($this->isSelected($request, $uuid)) {
            return Response::error('Switch away from this workspace before purging it.', Response::HTTP_CONFLICT);
        }
        $lifecycle = $tenants->getTenantLifecycle($this->context, $uuid);
        $confirm = $this->body($request)['confirm'] ?? null;
        if ($lifecycle === null || !is_string($confirm) || !hash_equals((string) $lifecycle['slug'], $confirm)) {
            return Response::validation(['confirm' => 'Type the workspace slug to confirm permanent purge.']);
        }
        try {
            $runUuid = $purges->request($uuid, $this->actor($request));
            $response = Response::success(['run_uuid' => $runUuid, 'status' => 'requested']);
            $response->setStatusCode(Response::HTTP_ACCEPTED);
            return $response;
        } catch (\DomainException | \RuntimeException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
    }

    public function seed(string $uuid): Response
    {
        $seedRepair = $this->seedRepairService();
        if ($seedRepair === null) {
            return $this->unavailable();
        }

        try {
            $seedRepair->repair($uuid);
            return Response::success(['tenant' => ['uuid' => $uuid, 'status' => 'active']]);
        } catch (StarterSeedException $exception) {
            return Response::error(
                'Starter seeding failed.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                [
                    'tenant_uuid' => $uuid,
                    'failed_definition' => $exception->definitionLabel,
                ],
            );
        } catch (\InvalidArgumentException | \DomainException | \RuntimeException $exception) {
            return Response::validation(['repair' => [$exception->getMessage()]]);
        }
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

    private function isSelected(Request $request, string $tenantUuid): bool
    {
        $selected = trim((string) $request->headers->get('X-Tenant-Id', ''));
        return $selected !== '' && hash_equals($tenantUuid, $selected);
    }

    private function unavailable(): Response
    {
        return Response::error(
            'Tenant administration is unavailable.',
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }

    private function tenantAdministration(): ?TenantAdministration
    {
        return $this->tenants ?? $this->services?->tenants();
    }

    private function seedActivator(): ?TenantSeedActivator
    {
        return $this->seeder ?? $this->services?->seedActivator();
    }

    private function seedRepairService(): ?TenantSeedRepair
    {
        return $this->seedRepair ?? $this->services?->seedRepair();
    }

    private function purgeCoordinator(): ?PurgeCoordinator
    {
        return $this->purges ?? $this->services?->purges();
    }

    private function lifecycleAudit(): ?TenancyLifecycleAudit
    {
        return $this->audit ?? $this->services?->audit();
    }
}
