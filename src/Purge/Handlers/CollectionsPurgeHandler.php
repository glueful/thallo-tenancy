<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge\Handlers;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Purge\PurgeHandler;

/** Collections tenancy is disabled; this explicit owner prevents silent omission when it lands. */
final class CollectionsPurgeHandler implements PurgeHandler
{
    public function id(): string
    {
        return 'thallo.collections';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function prepare(ApplicationContext $context, string $tenantUuid): array
    {
        return [];
    }

    public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void
    {
    }

    public function verify(ApplicationContext $context, string $tenantUuid): bool
    {
        return true;
    }
}
