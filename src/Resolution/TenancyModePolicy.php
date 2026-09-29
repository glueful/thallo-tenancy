<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The one three-mode tenancy policy, driven entirely off {@see SystemFlags}. Every Thallo-side
 * tenant resolver answers from it — commerce's seam and payments' resolver — so the modes cannot
 * drift apart:
 *
 *   (a) schema not widened                         -> '' (sentinel)
 *   (b) widened schema, enforcement not yet active -> the persisted default tenant; fail-closed
 *       \RuntimeException if none is set (a widened schema with no default tenant is a broken
 *       adoption -- this NEVER silently falls back to the sentinel)
 *   (c) enforcement active                         -> delegates to the shared, request-scoped
 *       {@see CurrentTenantResolver} (never rebound; re-resolved fresh on every call)
 *
 * With an {@see AdoptionGate}, resolving the sentinel holds the gate for the rest of the unit of
 * work, and the mode is read again once it holds: the flip that moves single-store rows into the
 * default tenant can then never overlap work that saw the sentinel, and resolving during the flip
 * fails closed ({@see RetrofitInProgressException}).
 *
 * The mode is read LIVE on each call: `enforcementActive()` clears the flags cache before
 * answering, so a flip mid-process shows on the very next call. Nothing here caches a mode or a
 * tenant; only the shared resolver SERVICE is memoized, lazily, the first time mode (c) is entered.
 */
final class TenancyModePolicy
{
    private ?CurrentTenantResolver $sharedResolver = null;

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly ContainerInterface $container,
        private readonly ?AdoptionGate $gate = null,
    ) {
    }

    public function mode(): TenancyMode
    {
        if ($this->flags->enforcementActive()) {
            return TenancyMode::Enforced;
        }

        return $this->flags->schemaState() === 'widened' ? TenancyMode::DefaultTenant : TenancyMode::Sentinel;
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        $mode = $this->mode();
        if ($mode === TenancyMode::Sentinel && $this->gate !== null) {
            if (!$this->gate->holdShared()) {
                throw new RetrofitInProgressException();
            }
            $mode = $this->mode(); // the flip may have committed before the hold
            if ($mode !== TenancyMode::Sentinel) {
                $this->gate->release();
            }
        }

        return match ($mode) {
            TenancyMode::Enforced => $this->sharedResolver()->tenantUuid($context),
            TenancyMode::DefaultTenant => $this->defaultTenantUuid(),
            TenancyMode::Sentinel => '',
        };
    }

    /** The persisted default tenant; fails closed when a widened schema has none. */
    public function defaultTenantUuid(): string
    {
        $default = $this->flags->defaultTenantUuid();
        if ($default === null || $default === '') {
            throw new \RuntimeException('Widened tenancy schema without a persisted default tenant.');
        }

        return $default;
    }

    /**
     * The shared CONTRACT resolver -- resolved from the container (and memoized) the first time
     * mode (c) is entered. NEVER rebound to a Thallo-local implementation: this always delegates
     * to whatever glueful/tenancy (or another host) bound as {@see CurrentTenantResolver}.
     *
     * Deliberately NOT constructor-injected: it must stay resolvable in installs where
     * glueful/tenancy is not bound at all -- modes (a)/(b) never touch it.
     */
    public function sharedResolver(): CurrentTenantResolver
    {
        if ($this->sharedResolver !== null) {
            return $this->sharedResolver;
        }

        if (!$this->container->has(CurrentTenantResolver::class)) {
            throw new \RuntimeException(
                'Tenancy enforcement is active but no CurrentTenantResolver is bound '
                . '(install glueful/tenancy).'
            );
        }

        $resolver = $this->container->get(CurrentTenantResolver::class);
        if (!$resolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return $this->sharedResolver = $resolver;
    }
}
