<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;

/** Fresh-boot proof that disabled-widened compatibility mode is usable before lowering the barrier. */
final class DisableProbe
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly Connection $connection,
        private readonly RetrofitMaintenanceGuard $guard,
        private readonly EnablementStore $store,
        private readonly CacheStore $cache,
        private readonly ?TenantEnforcementProbe $enforcementProbe = null,
    ) {
    }

    /** @return array{scoping: bool, hook: bool, write: bool, sentinel: bool, ok: bool} */
    public function passes(): array
    {
        $tenantUuid = $this->flags->defaultTenantUuid();
        $scoping = $this->enforcementProbe === null || $this->enforcementProbe->registeredTables() === [];
        $hooked = Connection::applyInsertHooks('content_types', ['title' => 'probe']);
        $hook = $tenantUuid !== null && ($hooked['tenant_uuid'] ?? null) === $tenantUuid;
        $write = false;

        try {
            $write = (bool) $this->guard->runInternal(function () use ($tenantUuid): bool {
                return $this->connection->transaction(function () use ($tenantUuid): bool {
                    $key = 'tenancy_disable_probe_' . bin2hex(random_bytes(6));
                    $this->connection->table('settings')->insert([
                        'key' => $key,
                        'value' => '1',
                        'tenant_uuid' => $tenantUuid,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $row = $this->connection->table('settings')->where(['key' => $key])->first();
                    $this->connection->table('settings')->where(['key' => $key])->delete();

                    return ($row['value'] ?? null) === '1'
                        && ($row['tenant_uuid'] ?? null) === $tenantUuid;
                });
            });
        } catch (\Throwable) {
            $write = false;
        }

        $sentinelKey = $this->store->sentinelKey();
        $sentinel = $sentinelKey !== null && $this->cache->get($sentinelKey) === null;

        return [
            'scoping' => $scoping,
            'hook' => $hook,
            'write' => $write,
            'sentinel' => $sentinel,
            'ok' => $scoping && $hook && $write && $sentinel,
        ];
    }
}
