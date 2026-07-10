<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Cache;

use Glueful\Cache\CacheStore;

final class TenantHostCachePurger
{
    public function __construct(private readonly CacheStore $cache)
    {
    }

    public function purgeForTenant(string $tenantUuid): void
    {
        $prefix = 'tenant:' . $tenantUuid . ':';
        $this->cache->deletePattern($prefix . 'render:*');
        $this->cache->deletePattern($prefix . 'thallo:seo:sitemap:*');
        $this->cache->invalidateTags(['thallo:render:page']);
    }
}
