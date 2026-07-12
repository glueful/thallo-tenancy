<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Thallo\Tenancy\Contracts\TenantSeedActivator;
use Thallo\Tenancy\Contracts\TenantSeedRepair;
use Thallo\Tenancy\Purge\PurgeCoordinator;

/** Lazily resolves services that exist only when the tenancy control plane is active. */
final class TenantManagementServices
{
    /** @var array<class-string, object|null> */
    private array $resolved = [];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function tenants(): ?TenantAdministration
    {
        return $this->resolve(TenantAdministration::class);
    }

    public function seedActivator(): ?TenantSeedActivator
    {
        return $this->resolve(TenantSeedActivator::class);
    }

    public function seedRepair(): ?TenantSeedRepair
    {
        return $this->resolve(TenantSeedRepair::class);
    }

    public function purges(): ?PurgeCoordinator
    {
        return $this->resolve(PurgeCoordinator::class);
    }

    public function audit(): ?TenancyLifecycleAudit
    {
        return $this->resolve(TenancyLifecycleAudit::class);
    }

    /** @template T of object
     * @param class-string<T> $id
     * @return T|null
     */
    private function resolve(string $id): ?object
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }
        if (!$this->container->has($id)) {
            return $this->resolved[$id] = null;
        }

        try {
            $service = $this->container->get($id);
        } catch (ContainerExceptionInterface | NotFoundExceptionInterface) {
            return $this->resolved[$id] = null;
        }
        if (!$service instanceof $id) {
            throw new \LogicException(sprintf('Service %s has an invalid container binding.', $id));
        }

        return $this->resolved[$id] = $service;
    }
}
