<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Contracts;

interface TenantSeedRepair
{
    public function repair(string $tenantUuid): void;
}
