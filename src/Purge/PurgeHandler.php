<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Bootstrap\ApplicationContext;

interface PurgeHandler
{
    public function id(): string;

    /** @return list<string> */
    public function dependsOn(): array;

    /** @return array<string, mixed> */
    public function prepare(ApplicationContext $context, string $tenantUuid): array;

    /** @param array<string, mixed> $artifacts */
    public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void;

    /** @param array<string, mixed> $artifacts */
    public function verify(ApplicationContext $context, string $tenantUuid, array $artifacts): bool;
}
