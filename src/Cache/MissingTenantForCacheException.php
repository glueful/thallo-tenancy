<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Cache;

final class MissingTenantForCacheException extends \RuntimeException
{
    public function __construct(string $surface)
    {
        parent::__construct("Tenancy is enabled but no tenant resolved for the {$surface} cache.");
    }
}
