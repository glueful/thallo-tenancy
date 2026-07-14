<?php

declare(strict_types=1);

namespace Thallo\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantResolutionProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobCreatedHook;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\ServiceProvider;
use PDO;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Contracts\Tenancy\TenantWriteScope;
use Thallo\Tenancy\Compat\CompatWriteScope;
use Thallo\Tenancy\Retrofit\AdditiveRetrofit;
use Thallo\Tenancy\Retrofit\DefaultTenant;
use Thallo\Tenancy\Retrofit\MediaOwnershipBackfill;
use Thallo\Tenancy\Retrofit\MutationBoundaryLock;
use Thallo\Tenancy\Retrofit\MutationQuiescenceWrapper;
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
use Thallo\Tenancy\PublicOrigin\PublicOriginService;
use Thallo\Tenancy\PublicOrigin\PublicOriginStore;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;
use Thallo\Tenancy\Runtime\TenancyRuntimeReadiness as CompositeTenantRuntimeReadiness;
use Thallo\Tenancy\Runtime\BootstrapDefaultTenantMiddleware;
use Thallo\Tenancy\Runtime\BootstrapTenantCreationGuard;
use Thallo\Tenancy\ApiKeyBinding\TenantApiKeyBindingRepository;
use Thallo\Tenancy\Http\Middleware\CollectionsTenantBindingMiddleware;
use Thallo\Tenancy\Runtime\TenantSystemMiddleware;
use Thallo\Tenancy\Runtime\TenantProfileMiddleware;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\Cache\TenantHostCachePurger;
use Thallo\Tenancy\Enablement\ExtensionActivation;
use Thallo\Tenancy\Enablement\ExtensionActivationContract;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Enablement\DisableGates;
use Thallo\Tenancy\Enablement\DisableProbe;
use Thallo\Tenancy\Enablement\TenancyDiagnostics;
use Thallo\Tenancy\Http\Controllers\PublicOriginController;
use Thallo\Tenancy\Http\Controllers\TenancyEnablementController;
use Thallo\Tenancy\Http\Controllers\TenancyResolutionController;
use Thallo\Tenancy\Http\Controllers\TenantDirectoryController;
use Thallo\Tenancy\Http\Controllers\TenantManagementController;
use Thallo\Tenancy\Http\Controllers\TenantManagementServices;
use Thallo\Tenancy\Http\Controllers\TenantDomainController;
use Thallo\Tenancy\Http\Controllers\TenantMembershipController;
use Thallo\Tenancy\Resolution\ThalloFullResolutionReadiness;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;
use Thallo\Tenancy\Resolution\FullResolutionActivation;
use Thallo\Tenancy\Purge\Handlers\CachePurgeHandler;
use Thallo\Tenancy\Purge\Handlers\MediaPurgeHandler;
use Thallo\Tenancy\Purge\Handlers\TablesPurgeHandler;
use Thallo\Tenancy\Purge\PurgeResourceRegistry;
use Thallo\Tenancy\Purge\PurgeRunRepository;
use Thallo\Tenancy\Purge\PurgeCoordinator;
use Thallo\Tenancy\Reverification\DomainReverificationSweep;
use Thallo\Tenancy\Reverification\DomainReverificationSweepLock;
use Thallo\Tenancy\Reverification\DomainReverificationAuditListener;

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
            SingleStoreTenant::class => [
                'class' => SingleStoreTenant::class,
                'shared' => true,
                'autowire' => true,
            ],
            CompatWriteScope::class => [
                'factory' => [self::class, 'makeCompatWriteScope'],
                'shared' => true,
            ],
            TenantWriteScope::class => [
                'factory' => [self::class, 'makeTenantWriteScope'],
                'shared' => true,
            ],
            // The unscoped system-settings channel IS SystemFlags — alias to the same shared
            // instance (factory, not a second `class` binding) so every consumer shares one cache.
            SystemChannel::class => [
                'factory' => [self::class, 'makeSystemChannel'],
                'shared' => true,
            ],
            TenantRuntimeReadiness::class => [
                'factory' => [self::class, 'makeReadiness'],
                'shared' => true,
            ],
            FullTenantResolutionReadiness::class => [
                'factory' => [self::class, 'makeFullResolutionReadiness'],
                'shared' => true,
            ],
            ResolutionActivationStore::class => [
                'class' => ResolutionActivationStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublicOriginStore::class => [
                'class' => PublicOriginStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublicOriginService::class => [
                'class' => PublicOriginService::class,
                'shared' => true,
                'autowire' => true,
            ],
            FullResolutionActivation::class => [
                'factory' => [self::class, 'makeFullResolutionActivation'],
                'shared' => true,
            ],
            TenantCacheSegment::class => [
                'factory' => [self::class, 'makeCacheSegment'],
                'shared' => true,
            ],
            CacheTransition::class => [
                'class' => CacheTransition::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantHostCachePurger::class => [
                'class' => TenantHostCachePurger::class,
                'shared' => true,
                'autowire' => true,
            ],
            ExtensionActivation::class => [
                'class' => ExtensionActivation::class,
                'shared' => true,
                'autowire' => true,
            ],
            ExtensionActivationContract::class => [
                'factory' => [self::class, 'makeExtensionActivation'],
                'shared' => true,
            ],
            EnablementStore::class => [
                'class' => EnablementStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            EnablementLock::class => [
                'class' => EnablementLock::class,
                'shared' => true,
                'autowire' => true,
            ],
            DisableGates::class => [
                'class' => DisableGates::class,
                'shared' => true,
                'autowire' => true,
            ],
            DisableProbe::class => [
                'class' => DisableProbe::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenancyDiagnostics::class => [
                'class' => TenancyDiagnostics::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenancyEnablement::class => [
                'class' => TenancyEnablement::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenancyEnablementController::class => [
                'class' => TenancyEnablementController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenancyResolutionController::class => [
                'class' => TenancyResolutionController::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublicOriginController::class => [
                'class' => PublicOriginController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantDirectoryController::class => [
                'class' => TenantDirectoryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantManagementController::class => [
                'factory' => [self::class, 'makeTenantManagementController'],
                'shared' => true,
            ],
            TenantDomainController::class => [
                'class' => TenantDomainController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantMembershipController::class => [
                'class' => TenantMembershipController::class,
                'shared' => true,
                'autowire' => true,
            ],
            FinalizationProbe::class => [
                'factory' => [self::class, 'makeFinalizationProbe'],
                'shared' => true,
            ],
            BootstrapDefaultTenantMiddleware::class => [
                'factory' => [self::class, 'makeBootstrapMiddleware'],
                'shared' => true,
                'alias' => ['tenant_bootstrap'],
            ],
            BootstrapTenantCreationGuard::class => [
                'class' => BootstrapTenantCreationGuard::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantApiKeyBindingRepository::class => [
                'class' => TenantApiKeyBindingRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            CollectionsTenantBindingMiddleware::class => [
                'class' => CollectionsTenantBindingMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['collections_tenant_binding'],
            ],
            TenantSystemMiddleware::class => [
                'class' => TenantSystemMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['tenant_system'],
            ],
            TenantProfileMiddleware::class => [
                'class' => TenantProfileMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['tenant_profile'],
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
            MediaOwnershipBackfill::class => [
                'class' => MediaOwnershipBackfill::class,
                'shared' => true,
                'autowire' => true,
            ],
            MutationBoundaryLock::class => [
                'class' => MutationBoundaryLock::class,
                'shared' => true,
                'autowire' => true,
            ],
            MutationQuiescenceWrapper::class => [
                'class' => MutationQuiescenceWrapper::class,
                'shared' => true,
                'autowire' => true,
            ],
            PurgeRunRepository::class => [
                'class' => PurgeRunRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            TablesPurgeHandler::class => [
                'class' => TablesPurgeHandler::class,
                'shared' => true,
                'autowire' => true,
            ],
            MediaPurgeHandler::class => [
                'class' => MediaPurgeHandler::class,
                'shared' => true,
                'autowire' => true,
            ],
            CachePurgeHandler::class => [
                'class' => CachePurgeHandler::class,
                'shared' => true,
                'autowire' => true,
            ],
            PurgeResourceRegistry::class => [
                'factory' => [self::class, 'makePurgeResourceRegistry'],
                'shared' => true,
            ],
            PurgeCoordinator::class => [
                'class' => PurgeCoordinator::class,
                'shared' => true,
                'autowire' => true,
            ],
            DomainReverificationSweep::class => [
                'class' => DomainReverificationSweep::class,
                'shared' => true,
                'autowire' => true,
            ],
            DomainReverificationSweepLock::class => [
                'class' => DomainReverificationSweepLock::class,
                'shared' => true,
                'autowire' => true,
            ],
            DomainReverificationAuditListener::class => [
                'class' => DomainReverificationAuditListener::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makePurgeResourceRegistry(ContainerInterface $container): PurgeResourceRegistry
    {
        $registry = new PurgeResourceRegistry();
        $registry->register($container->get(MediaPurgeHandler::class));
        $registry->register($container->get(TablesPurgeHandler::class));
        $registry->register($container->get(CachePurgeHandler::class));
        if ($container->has('thallo.collections.purge_handler')) {
            $handler = $container->get('thallo.collections.purge_handler');
            if ($handler instanceof \Thallo\Tenancy\Purge\PurgeHandler) {
                $registry->register($handler);
            }
        }
        return $registry;
    }

    public static function makeTenantManagementController(
        ContainerInterface $container
    ): TenantManagementController {
        return new TenantManagementController(
            $container->get(ApplicationContext::class),
            $container->get(BootstrapTenantCreationGuard::class),
            services: new TenantManagementServices($container),
        );
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

    public static function makeCompatWriteScope(ContainerInterface $container): CompatWriteScope
    {
        $flags = $container->get(SystemFlags::class);
        return new CompatWriteScope(
            $flags->tenancyEnabled(),
            $flags->schemaState(),
            $flags->defaultTenantUuid(),
        );
    }

    public static function makeTenantWriteScope(ContainerInterface $container): TenantWriteScope
    {
        return $container->get(CompatWriteScope::class);
    }

    public static function makeExtensionActivation(ContainerInterface $container): ExtensionActivationContract
    {
        return $container->get(ExtensionActivation::class);
    }

    public static function makeWriteBarrier(ContainerInterface $container): WriteBarrier
    {
        return $container->get(RetrofitMaintenanceGuard::class);
    }

    public static function makeReadiness(ContainerInterface $container): TenantRuntimeReadiness
    {
        $runner = $container->has(TenantContextRunner::class)
            ? $container->get(TenantContextRunner::class)
            : null;

        return new CompositeTenantRuntimeReadiness(
            $container->get(SystemFlags::class),
            $container->get(Connection::class),
            $runner,
        );
    }

    public static function makeCacheSegment(ContainerInterface $container): TenantCacheSegment
    {
        $resolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : null;

        return new TenantCacheSegment($container->get(SystemFlags::class), $resolver);
    }

    public static function makeFullResolutionActivation(ContainerInterface $container): FullResolutionActivation
    {
        // The domain/probe/tenant-admin contracts are bound only by the enabled tenancy
        // extension; soft-resolve them so the read-only status endpoint works while off.
        $domains = $container->has(TenantDomainAdministration::class)
            ? $container->get(TenantDomainAdministration::class)
            : null;
        $probe = $container->has(TenantResolutionProbe::class)
            ? $container->get(TenantResolutionProbe::class)
            : null;
        $tenants = $container->has(TenantAdministration::class)
            ? $container->get(TenantAdministration::class)
            : null;

        return new FullResolutionActivation(
            $container->get(ApplicationContext::class),
            $container->get(ResolutionActivationStore::class),
            $container->get(EnablementLock::class),
            $container->get(SystemFlags::class),
            $domains,
            $probe,
            $container->get(TenantRuntimeReadiness::class),
            $tenants,
            $container->get(PublicOriginStore::class),
        );
    }

    public static function makeFullResolutionReadiness(
        ContainerInterface $container
    ): FullTenantResolutionReadiness {
        $domains = $container->has(TenantDomainAdministration::class)
            ? $container->get(TenantDomainAdministration::class)
            : null;

        return new ThalloFullResolutionReadiness(
            $container->get(SystemFlags::class),
            $domains,
        );
    }

    public static function makeBootstrapMiddleware(ContainerInterface $container): BootstrapDefaultTenantMiddleware
    {
        $resolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : null;
        $runner = $container->has(TenantContextRunner::class)
            ? $container->get(TenantContextRunner::class)
            : null;

        return new BootstrapDefaultTenantMiddleware(
            $container->get(ApplicationContext::class),
            $container->get(SystemFlags::class),
            $container->get(TenantRuntimeReadiness::class),
            $resolver,
            $runner,
        );
    }

    public static function makeFinalizationProbe(ContainerInterface $container): FinalizationProbe
    {
        return new FinalizationProbe(
            $container->get(SystemFlags::class),
            $container->get(Connection::class),
            $container->get(TenantRuntimeReadiness::class),
            $container->get(TenantCacheSegment::class),
            $container->has(TenantContextRunner::class) ? $container->get(TenantContextRunner::class) : null,
            $container->has(TenantEnforcementProbe::class) ? $container->get(TenantEnforcementProbe::class) : null,
            $container->has(BlobCreatedHook::class) ? $container->get(BlobCreatedHook::class) : null,
            $container->has(BlobAccessPolicy::class) ? $container->get(BlobAccessPolicy::class) : null,
        );
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

        // Hydrate the admin-set public origin (base domain + default hosts) from SystemFlags over
        // config, before the lazy request-time resolver chain is built. Boot-only: overrideConfig()
        // runs inside the framework boot window (this provider boot() always precedes markBooted()).
        // Unset persisted values leave file/env config untouched.
        $context->getContainer()->get(PublicOriginStore::class)->hydrate();

        // Register owned tables behind the compound gate (contract bound + scoping enabled).
        $this->registerTenantTables($context);

        // Retrofit write-barrier: read persisted state ONCE (before the interceptor exists, so this
        // read cannot recurse), then register the interceptor UNCONDITIONALLY (outside the tenancy
        // gate) — the barrier must refuse writes during an enable-time retrofit regardless of whether
        // scoping is currently on. When no retrofit is in progress the guard's active() is false, so
        // the interceptor is a no-op fast-path on every query.
        $guard = app($context, RetrofitMaintenanceGuard::class);
        $guard->refresh();
        $compat = app($context, CompatWriteScope::class);
        if ($compat->mode() === 'compat') {
            Connection::addInsertHook(
                static fn(string $table, array $row): array => $compat->stampIfMissing($table, $row)
            );
        }
        QueryExecutor::addQueryInterceptor(app($context, RetrofitWriteBarrierInterceptor::class));
        QueryExecutor::addExecutionWrapper(app($context, MutationQuiescenceWrapper::class));

        $this->loadRoutesFrom(__DIR__ . '/../routes/enablement.php');
        $this->discoverCommands('Thallo\\Tenancy\\Console', __DIR__ . '/Console');
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
