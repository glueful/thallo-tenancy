<?php

declare(strict_types=1);

namespace Thallo\Tenancy\PublicOrigin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Persists the admin-set public origin (base domain + default hosts) in SystemFlags and
 * hydrates it over config at boot. A process-local revision, captured at construction,
 * gates resolution activation against config the running process never loaded.
 */
final class PublicOriginStore
{
    private const KEY_BASE = 'tenancy.public_origin.base_domain';
    private const KEY_HOSTS = 'tenancy.public_origin.default_hosts';
    private const KEY_REVISION = 'tenancy.public_origin.revision';

    /** Revision this process observed at construction (boot) — process-local, like bootId. */
    private readonly ?string $hydratedRevision;
    private readonly ?string $fallbackBaseDomain;
    /** @var list<string> */
    private readonly array $fallbackHosts;
    private ?string $appliedBaseDomain = null;
    /** @var list<string> */
    private array $appliedHosts = [];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly Connection $connection,
    ) {
        $fallbackBase = $this->context->getConfig(self::KEY_BASE);
        $this->fallbackBaseDomain = is_string($fallbackBase) && $fallbackBase !== ''
            ? $fallbackBase
            : null;
        $fallbackHosts = $this->context->getConfig(self::KEY_HOSTS, []);
        $this->fallbackHosts = array_values(array_filter(
            is_array($fallbackHosts) ? $fallbackHosts : [],
            'is_string'
        ));
        $revision = $this->flags->get(self::KEY_REVISION);
        $this->hydratedRevision = ($revision === null || $revision === '') ? null : $revision;
    }

    public function persistedBaseDomain(): ?string
    {
        $value = $this->flags->get(self::KEY_BASE);

        return ($value === null || $value === '') ? null : $value;
    }

    /** @return list<string> */
    public function persistedHosts(): array
    {
        $raw = (string) $this->flags->get(self::KEY_HOSTS);

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($h) => $h !== ''));
    }

    public function fallbackBaseDomain(): ?string
    {
        return $this->fallbackBaseDomain;
    }

    /** @return list<string> */
    public function fallbackHosts(): array
    {
        return $this->fallbackHosts;
    }

    public function desiredBaseDomain(): ?string
    {
        return $this->persistedBaseDomain() ?? $this->fallbackBaseDomain;
    }

    /** @return list<string> */
    public function desiredHosts(): array
    {
        $persisted = $this->persistedHosts();
        return $persisted !== [] ? $persisted : $this->fallbackHosts;
    }

    public function appliedBaseDomain(): ?string
    {
        return $this->appliedBaseDomain;
    }

    /** @return list<string> */
    public function appliedHosts(): array
    {
        return $this->appliedHosts;
    }

    /** Boot-only: override config with the persisted values so every config() consumer sees them. */
    public function hydrate(): void
    {
        $base = $this->persistedBaseDomain();
        $this->appliedBaseDomain = $base ?? $this->fallbackBaseDomain;
        if ($base !== null) {
            $this->context->overrideConfig(self::KEY_BASE, $base);
        }
        $hosts = $this->persistedHosts();
        $this->appliedHosts = $hosts !== [] ? $hosts : $this->fallbackHosts;
        if ($hosts !== []) {
            $this->context->overrideConfig(self::KEY_HOSTS, $hosts);
        }
    }

    public function isStale(): bool
    {
        $this->flags->clearCache(); // a remote HTTP/CLI process may have written a new revision
        $current = (string) $this->flags->get(self::KEY_REVISION);

        return !hash_equals((string) $this->hydratedRevision, $current);
    }

    public function assertFreshForActivation(): void
    {
        if ($this->isStale()) {
            throw new EnablementException(
                'Public origin changed since this process started — restart required before activating.'
            );
        }
    }

    /**
     * Persist normalized values, bumping the revision only when they changed. Returns whether a
     * write occurred. Caller is responsible for normalization/validation and for holding the lock.
     *
     * @param list<string> $hosts
     */
    public function writeChanged(?string $baseDomain, array $hosts): bool
    {
        if ($baseDomain === $this->persistedBaseDomain() && $hosts === $this->persistedHosts()) {
            return false;
        }

        $this->connection->transaction(function () use ($baseDomain, $hosts): void {
            if ($baseDomain === null) {
                $this->flags->forget(self::KEY_BASE);
            } else {
                $this->flags->put(self::KEY_BASE, $baseDomain);
            }
            $this->flags->put(self::KEY_HOSTS, implode(',', $hosts));
            $this->flags->put(self::KEY_REVISION, bin2hex(random_bytes(16)));
        });

        return true;
    }
}
