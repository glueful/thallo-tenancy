<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge\Handlers;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Cache\TenantHostCachePurger;
use Thallo\Tenancy\Purge\PurgeHandler;

final class CachePurgeHandler implements PurgeHandler
{
    public function __construct(private readonly TenantHostCachePurger $cache)
    {
    }

    public function id(): string
    {
        return 'thallo.cache';
    }

    public function dependsOn(): array
    {
        return ['thallo.tables'];
    }

    public function prepare(ApplicationContext $context, string $tenantUuid): array
    {
        return [];
    }

    public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void
    {
        $this->cache->purgeForTenant($tenantUuid);
    }

    public function verify(ApplicationContext $context, string $tenantUuid): bool
    {
        return true;
    }
}
