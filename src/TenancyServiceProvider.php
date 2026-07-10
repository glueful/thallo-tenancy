<?php

declare(strict_types=1);

namespace Thallo\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Glueful\Database\Connection;
use Glueful\Extensions\ServiceProvider;
use PDO;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Tenancy\Retrofit\AdditiveRetrofit;
use Thallo\Tenancy\Retrofit\DefaultTenant;
use Thallo\Tenancy\Retrofit\RetrofitDdlFactory;
use Thallo\Tenancy\Retrofit\RetrofitDiagnostics;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\Retrofit\RetrofitDdl;
use Thallo\Tenancy\Retrofit\RetrofitProgress;
use Thallo\Tenancy\Retrofit\RetrofitWriteBarrierInterceptor;
use Thallo\Tenancy\Retrofit\SchemaIntrospector;
use Thallo\Tenancy\Retrofit\SchemaRetrofit;
use Thallo\Tenancy\Retrofit\TableRebuilder;
use Thallo\Tenancy\Retrofit\UniquenessPreflight;
use Thallo\Tenancy\System\SystemFlags;

final class TenancyServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            SystemFlags::class => [
                'class' => SystemFlags::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The unscoped system-settings channel IS SystemFlags — alias to the same shared
            // instance (factory, not a second `class` binding) so every consumer shares one cache.
            SystemChannel::class => [
                'factory' => [self::class, 'makeSystemChannel'],
                'shared' => true,
            ],
            RetrofitProgress::class => [
                'class' => RetrofitProgress::class,
                'shared' => true,
                'autowire' => true,
            ],
            SchemaIntrospector::class => [
                'class' => SchemaIntrospector::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Driver-strategy factory for the enable-time schema retrofit. Later retrofit tasks resolve
            // the concrete RetrofitDdl from this via for($connection->getDriverName()); registered here
            // as the pure, dependency-free seam so the container can hand it out.
            RetrofitDdlFactory::class => [
                'class' => RetrofitDdlFactory::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Enable-time duplicate detection: derives each owned table's business-key set (widened
            // unique minus tenant_uuid) and blocks the retrofit if two rows would collide once every
            // row shares the default tenant. Autowired: Connection + RetrofitDdlFactory.
            UniquenessPreflight::class => [
                'class' => UniquenessPreflight::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Operation-scoped default-tenant provisioning for the enable-time retrofit. Talks only to
            // the neutral TenantProvisioner contract (bound by glueful/tenancy); autowired with the
            // ApplicationContext + SystemFlags.
            DefaultTenant::class => [
                'class' => DefaultTenant::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The retrofit write-barrier state holder. SHARED so the interceptor, every
            // WriteBarrier-injected raw-write gate, and the orchestrator see one in-memory `active`
            // flag — a begin() in the orchestrator MUST be visible to the interceptor.
            RetrofitMaintenanceGuard::class => [
                'class' => RetrofitMaintenanceGuard::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Bind the neutral WriteBarrier contract to the SAME shared guard instance (factory, not a
            // second `class` binding) so raw-write gates share the guard's flag.
            WriteBarrier::class => [
                'factory' => [self::class, 'makeWriteBarrier'],
                'shared' => true,
            ],
            RetrofitWriteBarrierInterceptor::class => [
                'class' => RetrofitWriteBarrierInterceptor::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The additive per-table retrofit path (add tenant_uuid, backfill, NOT NULL, widen uniques).
            // Autowired: Connection + SchemaIntrospector + RetrofitProgress + DefaultTenant +
            // RetrofitDdlFactory (the concrete RetrofitDdl is derived from the live driver).
            AdditiveRetrofit::class => [
                'class' => AdditiveRetrofit::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The staged, recoverable copy-rebuild path for the three tables that cannot widen in place
            // (regions/settings PK, entry_redirects inline unique). Autowired: Connection +
            // SchemaIntrospector + RetrofitProgress + DefaultTenant + RetrofitDdlFactory.
            TableRebuilder::class => [
                'class' => TableRebuilder::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Reality-first diagnostics: reports per-table widening coherence and whether the persisted
            // schema_state agrees with the live schema. Autowired: Connection + SchemaIntrospector +
            // SystemFlags.
            RetrofitDiagnostics::class => [
                'class' => RetrofitDiagnostics::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The concrete driver-specific RetrofitDdl, derived from the live connection's driver via
            // RetrofitDdlFactory::for(). Registered as a resolvable seam (a factory, since the concrete
            // class depends on the runtime driver, not a static `class` binding).
            RetrofitDdl::class => [
                'factory' => [self::class, 'makeRetrofitDdl'],
                'shared' => true,
            ],
            // The enable-time schema-retrofit orchestrator: composes every engine piece in strict order
            // (driver gate → barrier up → provision → preflight → reconcile → widen → verify →
            // schema_state=widened → barrier stays up). Autowired from the shared engine services.
            SchemaRetrofit::class => [
                'class' => SchemaRetrofit::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeRetrofitDdl(ContainerInterface $container): RetrofitDdl
    {
        $connection = $container->get(Connection::class);
        $driver = (string) $connection->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME);

        return $container->get(RetrofitDdlFactory::class)->for($driver);
    }

    public static function makeSystemChannel(ContainerInterface $container): SystemChannel
    {
        return $container->get(SystemFlags::class);
    }

    public static function makeWriteBarrier(ContainerInterface $container): WriteBarrier
    {
        return $container->get(RetrofitMaintenanceGuard::class);
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge under a non-colliding key.
        $this->mergeConfig('thallo_tenancy', require __DIR__ . '/../config/tenancy.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'thallo.tenancy',
            label: 'Multi-tenancy',
            description: 'Tenant-owned content model + data, scoping, seed/sync and enablement.',
        ));

        // Migrations load unconditionally (outside any gate) so the system-channel table exists
        // for every install — the retrofit that adds tenant_uuid is NOT here (it is an
        // enable-time operation, spec §7.4).
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'thallo-tenancy',
        );

        // Register owned tables behind the compound gate (contract bound + scoping enabled).
        $this->registerTenantTables($context);

        // Retrofit write-barrier: read persisted state ONCE (before the interceptor exists, so this
        // read cannot recurse), then register the interceptor UNCONDITIONALLY (outside the tenancy
        // gate) — the barrier must refuse writes during an enable-time retrofit regardless of whether
        // scoping is currently on. When no retrofit is in progress the guard's active() is false, so
        // the interceptor is a no-op fast-path on every query.
        $guard = app($context, RetrofitMaintenanceGuard::class);
        $guard->refresh();
        QueryExecutor::addQueryInterceptor(app($context, RetrofitWriteBarrierInterceptor::class));
    }

    /**
     * Register Thallo's tenant-owned tables into the tenancy backstop — but ONLY when both:
     *   (a) the TenantTableRegistry contract is bound (the glueful/tenancy extension is active), and
     *   (b) SystemFlags says tenancy scoping is enabled.
     * A merely-installed-but-disabled extension never silently scopes a single-tenant site.
     *
     * The registry + flags are injectable seams (default to container lookups) so the gate is
     * unit-testable without mutating the process-shared container.
     */
    public function registerTenantTables(
        ApplicationContext $context,
        ?TenantTableRegistryContract $registry = null,
        ?SystemFlags $flags = null,
    ): bool {
        if ($registry === null) {
            $container = $context->getContainer();
            if (!$container->has(TenantTableRegistryContract::class)) {
                return false;
            }
            /** @var TenantTableRegistryContract $registry */
            $registry = $container->get(TenantTableRegistryContract::class);
        }

        $flags ??= app($context, SystemFlags::class);
        if (!$flags->tenancyEnabled()) {
            return false;
        }

        $registry->register(ThalloTenantTables::tableNames());

        return true;
    }
}
