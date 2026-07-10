<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Cache;

use Glueful\Cache\CacheStore;

final class CacheTransition
{
    public function __construct(private readonly CacheStore $cache)
    {
    }

    public function purge(): void
    {
        $this->cache->deletePattern('render:*');
        $this->cache->deletePattern('tenant:*:render:*');
        $this->cache->deletePattern('thallo:seo:sitemap:*');
        $this->cache->deletePattern('tenant:*:thallo:seo:sitemap:*');
        $this->cache->invalidateTags(['thallo:render:page']);
    }

    public function supportsPatternPurge(): bool
    {
        $probe = 'thallo:tenancy:capexpr:' . bin2hex(random_bytes(4));
        $this->cache->set($probe, '1', 60);
        $this->cache->deletePattern('thallo:tenancy:capexpr:*');
        $survived = $this->cache->get($probe) !== null;

        if ($survived) {
            $this->cache->delete($probe);
        }

        return !$survived;
    }
}
