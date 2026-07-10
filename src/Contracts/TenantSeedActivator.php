<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Contracts;

interface TenantSeedActivator
{
    public function seedAndActivate(string $tenantUuid, string $ownerUserUuid): void;
}
