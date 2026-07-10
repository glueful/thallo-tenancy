<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Contracts;

interface TenantStarterSync
{
    /** @return list<array{kind:string,source_id:string,action:string}> */
    public function sync(string $tenantUuid, ?string $kind = null): array;

    /** @return array<string,list<array{kind:string,source_id:string,action:string}>> */
    public function syncAll(?string $kind = null): array;
}
