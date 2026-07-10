<?php

declare(strict_types=1);

namespace Thallo\Tenancy\System;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * Thin key/value store over the unscoped `thallo_system_flags` table — the runtime tenancy state
 * that must be readable before tenant resolution. Modeled on App\Settings\SettingsStore, but
 * system-global (never tenant-scoped). Missing table (fresh install) reads as "off".
 *
 * Also serves as the app's {@see SystemChannel}: its get/put/forget are the unscoped home for
 * system settings keys (see App\Settings\SystemKeys), keeping them out of the scoped `settings` table.
 */
final class SystemFlags implements SystemChannel
{
    private const KEY_ENABLED = 'tenancy.enabled';
    private const KEY_SCHEMA_STATE = 'tenancy.schema_state';
    private const KEY_DEFAULT_TENANT = 'tenancy.default_tenant_uuid';

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    public function put(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = db($this->context)->table('thallo_system_flags')->where(['key' => $key])->first();
        if ($existing === null) {
            db($this->context)->table('thallo_system_flags')
                ->insert(['key' => $key, 'value' => $value, 'updated_at' => $now]);
        } else {
            db($this->context)->table('thallo_system_flags')->where(['key' => $key])
                ->update(['value' => $value, 'updated_at' => $now]);
        }
        $this->cache = null;
    }

    public function forget(string $key): void
    {
        db($this->context)->table('thallo_system_flags')->where(['key' => $key])->delete();
        $this->cache = null;
    }

    public function tenancyEnabled(): bool
    {
        return $this->get(self::KEY_ENABLED) === '1';
    }

    /** @return 'none'|'widened' */
    public function schemaState(): string
    {
        return $this->get(self::KEY_SCHEMA_STATE) === 'widened' ? 'widened' : 'none';
    }

    public function defaultTenantUuid(): ?string
    {
        $uuid = $this->get(self::KEY_DEFAULT_TENANT);
        return ($uuid === null || $uuid === '') ? null : $uuid;
    }

    public function clearCache(): void
    {
        $this->cache = null;
    }

    /** @return array<string,string> */
    private function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        // Fresh install: the table may not exist yet — treat ONLY that case as empty (=> off).
        // We check existence explicitly rather than catching every throwable, so a real DB
        // outage on the read below propagates instead of masquerading as "tenancy off".
        if (!db($this->context)->getSchemaBuilder()->hasTable('thallo_system_flags')) {
            return $this->cache = [];
        }

        $out = [];
        foreach (db($this->context)->table('thallo_system_flags')->get() as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }
        return $this->cache = $out;
    }
}
