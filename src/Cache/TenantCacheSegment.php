<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Cache;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Thallo\Tenancy\System\SystemFlags;

final class TenantCacheSegment
{
    public function __construct(
        private readonly SystemFlags $flags,
        private readonly ?CurrentTenantResolver $resolver = null,
    ) {
    }

    public function segment(ApplicationContext $context, string $surface = 'cache'): string
    {
        if (!$this->flags->tenancyEnabled()) {
            return '';
        }

        if ($this->resolver === null) {
            throw new MissingTenantForCacheException($surface);
        }

        $tenantUuid = $this->resolver->tenantUuid($context);
        if ($tenantUuid === '') {
            throw new MissingTenantForCacheException($surface);
        }

        return 'tenant:' . $tenantUuid . ':';
    }
}
