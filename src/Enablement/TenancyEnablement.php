<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\Retrofit\SchemaRetrofit;
use Thallo\Tenancy\System\SystemFlags;

/** Resumable SP1 enablement state machine. */
final class TenancyEnablement
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EnablementStore $store,
        private readonly EnablementLock $lock,
        private readonly SystemFlags $flags,
        private readonly ExtensionActivationContract $activation,
        private readonly FinalizationProbe $finalizationProbe,
        private readonly TenantRuntimeReadiness $readiness,
        private readonly RetrofitMaintenanceGuard $guard,
        private readonly CacheTransition $cacheTransition,
        private readonly Connection $connection,
        private readonly ?DisableGates $disableGates = null,
        private readonly ?DisableProbe $disableProbe = null,
        private readonly ?CacheStore $cache = null,
    ) {
    }

    public function status(): EnablementStatus
    {
        $step = $this->store->step();
        $cliFallback = $step === EnablementStep::AWAITING_INSTALL
            ? 'composer require ' . ExtensionActivation::PACKAGE . '   # then: php glueful tenancy:enable'
            : null;

        return new EnablementStatus(
            step: $step,
            enabled: $this->flags->tenancyEnabled(),
            schemaState: $this->flags->schemaState(),
            progress: $step->progress(),
            reloading: $step === EnablementStep::RELOADING
                || $step === EnablementStep::FINALIZING
                || ($step === EnablementStep::DISABLED_WIDENED && $this->guardPersistedActive()),
            mode: $this->readiness->mode($this->context),
            pendingSlug: $this->store->pendingSlug(),
            pendingName: $this->store->pendingName(),
            failure: $this->store->failure(),
            cliFallback: $cliFallback,
        );
    }

    public function begin(): EnablementStatus
    {
        return $this->lock->withLock(function (): EnablementStatus {
            $step = $this->store->step();

            if ($step === EnablementStep::DISABLED_WIDENED) {
                if (!$this->disabledPairSettled()) {
                    throw new EnablementException('Disabled-widened mode is awaiting fresh-boot verification.');
                }

                $this->guard->begin();
                try {
                    $this->cacheTransition->purge();
                    $this->connection->transaction(function (): void {
                        $this->flags->put('tenancy.enabled', '1');
                        if (
                            !$this->store->compareAndSet(
                                EnablementStep::DISABLED_WIDENED,
                                EnablementStep::RELOADING,
                            )
                        ) {
                            throw new EnablementException('Re-enable transition lost a CAS race.');
                        }
                    });
                } catch (\Throwable $exception) {
                    $this->flags->clearCache();
                    if (!$this->flags->tenancyEnabled()) {
                        $this->guard->end();
                        throw new EnablementException('Re-enable failed: ' . $exception->getMessage());
                    }
                    $this->store->recordFailure(EnablementStep::DISABLED_WIDENED, $exception->getMessage());
                }

                return $this->status();
            }

            if (
                ($step === EnablementStep::OFF || $step === EnablementStep::INSTALLING)
                && !$this->cacheTransition->supportsPatternPurge()
            ) {
                $this->store->recordFailure(
                    EnablementStep::OFF,
                    'Tenancy requires a cache driver that supports pattern purge.',
                );
                return $this->status();
            }

            if ($step === EnablementStep::OFF || $step === EnablementStep::INSTALLING) {
                $this->store->setStep(EnablementStep::INSTALLING);
                $install = $this->activation->install();
                if ($install['blocked']) {
                    $this->store->setStep(EnablementStep::AWAITING_INSTALL);
                    return $this->status();
                }

                $this->store->setStep(EnablementStep::ENABLING_EXTENSION);
                return $this->status();
            }

            if ($step === EnablementStep::AWAITING_INSTALL) {
                if (!$this->activation->isInstalled()) {
                    return $this->status();
                }

                $this->store->setStep(EnablementStep::ENABLING_EXTENSION);
                return $this->status();
            }

            if ($step === EnablementStep::ENABLING_EXTENSION) {
                if (!$this->activation->isActivated()) {
                    $this->activation->activate();
                }

                $this->store->setStep(EnablementStep::AWAITING_PROVIDER_BOOT);
                return $this->status();
            }

            if ($step === EnablementStep::AWAITING_PROVIDER_BOOT) {
                if (!$this->context->getContainer()->has(TenantProvisioner::class)) {
                    return $this->status();
                }

                $migration = $this->activation->migrate();
                if ($migration['failed'] !== []) {
                    $this->store->recordFailure(
                        EnablementStep::MIGRATING_EXTENSION,
                        'Extension migration failed: ' . implode(', ', $migration['failed']),
                    );
                    return $this->status();
                }

                $this->store->setStep(EnablementStep::AWAITING_CONFIRM);
                return $this->status();
            }

            if ($step === EnablementStep::MIGRATING_EXTENSION) {
                $migration = $this->activation->migrate();
                if ($migration['failed'] !== []) {
                    $this->store->recordFailure(
                        EnablementStep::MIGRATING_EXTENSION,
                        'Extension migration failed: ' . implode(', ', $migration['failed']),
                    );
                    return $this->status();
                }

                $this->store->setStep(EnablementStep::AWAITING_CONFIRM);
                return $this->status();
            }

            return $this->status();
        });
    }

    public function confirm(string $slug, string $name, string $ownerUserUuid): EnablementStatus
    {
        return $this->lock->withLock(function () use ($slug, $name, $ownerUserUuid): EnablementStatus {
            $step = $this->store->step();
            if ($step !== EnablementStep::AWAITING_CONFIRM && $step !== EnablementStep::RETROFITTING) {
                throw new StaleStateException(EnablementStep::AWAITING_CONFIRM, $step);
            }

            if ($this->hasCollections()) {
                throw new EnablementException(
                    'Enable blocked: collection definitions exist and collections tenancy is unsupported in SP1.',
                );
            }

            $retrofit = $this->resolveRetrofit();
            $this->store->setPendingTenant($slug, $name);
            $this->store->setStep(EnablementStep::RETROFITTING);

            try {
                $retrofit->run($slug, $name, $ownerUserUuid);
            } catch (\Throwable $exception) {
                $this->store->recordFailure(EnablementStep::RETROFITTING, $exception->getMessage());
                return $this->status();
            }

            $this->cacheTransition->purge();
            $this->flags->put('tenancy.enabled', '1');
            $this->store->setStep(EnablementStep::RELOADING);

            return $this->status();
        });
    }

    public function finalize(): EnablementStatus
    {
        return $this->lock->withLock(function (): EnablementStatus {
            $step = $this->store->step();
            if ($step === EnablementStep::ON) {
                return $this->status();
            }

            if ($step === EnablementStep::RELOADING) {
                if (!$this->store->compareAndSet(EnablementStep::RELOADING, EnablementStep::FINALIZING)) {
                    return $this->status();
                }
                $step = EnablementStep::FINALIZING;
            }

            if ($step !== EnablementStep::FINALIZING) {
                throw new StaleStateException(EnablementStep::RELOADING, $step);
            }

            if (!$this->finalizationProbe->passes($this->context)) {
                if (!$this->store->compareAndSet(EnablementStep::FINALIZING, EnablementStep::RELOADING)) {
                    $this->store->recordFailure(
                        $this->store->step(),
                        'Finalization probe failed and the retry-state transition lost a race.',
                    );
                }
                return $this->status();
            }

            try {
                $this->connection->transaction(function (): void {
                    $this->guard->end();
                    if (!$this->store->compareAndSet(EnablementStep::FINALIZING, EnablementStep::ON)) {
                        throw new EnablementException('Finalization state transition failed; transaction rolled back.');
                    }
                    $this->store->clearPending();
                });
            } catch (\Throwable $exception) {
                $this->guard->refresh();
                $this->store->recordFailure(EnablementStep::FINALIZING, $exception->getMessage());
            }

            return $this->status();
        });
    }

    public function retry(): EnablementStatus
    {
        return $this->lock->withLock(function (): EnablementStatus {
            if ($this->store->step() !== EnablementStep::FAILED) {
                throw new StaleStateException(EnablementStep::FAILED, $this->store->step());
            }

            $failedFrom = $this->store->failedFrom();
            if ($failedFrom === null) {
                throw new EnablementException('The failed enablement operation has no resumable step.');
            }

            $this->store->recordFailureCleared();
            $this->store->setStep($failedFrom);

            return $this->status();
        });
    }

    public function disable(): EnablementStatus
    {
        return $this->lock->withLock(function (): EnablementStatus {
            $step = $this->store->step();
            if ($step === EnablementStep::DISABLED_WIDENED) {
                if ($this->guardPersistedActive()) {
                    $this->settleDisable();
                }
                return $this->status();
            }

            if ($step === EnablementStep::ON) {
                if (!$this->store->compareAndSet(EnablementStep::ON, EnablementStep::DISABLING)) {
                    throw new EnablementException('Enablement state changed underneath disable().');
                }
                $step = EnablementStep::DISABLING;
            }
            if ($step !== EnablementStep::DISABLING) {
                throw new EnablementException('disable() requires ON or a resumable DISABLING state.');
            }
            if ($this->disableGates === null || $this->disableProbe === null || $this->cache === null) {
                throw new EnablementException('Disable services are unavailable in this process.');
            }

            if (!$this->guardPersistedActive()) {
                $this->guard->begin();
            }

            try {
                $this->disableGates->assertCanDisable();
            } catch (EnablementException $refusal) {
                try {
                    $this->connection->transaction(function (): void {
                        if (!$this->store->compareAndSet(EnablementStep::DISABLING, EnablementStep::ON)) {
                            throw new EnablementException('Refusal cleanup lost a CAS race.');
                        }
                        $this->store->clearSentinel();
                        $this->guard->end();
                    });
                } catch (\Throwable $exception) {
                    $this->guard->refresh();
                    $this->store->recordFailure(EnablementStep::DISABLING, $exception->getMessage());
                    return $this->status();
                }
                throw $refusal;
            }

            $sentinel = $this->store->sentinelKey();
            if ($sentinel === null) {
                $tenantUuid = $this->flags->defaultTenantUuid();
                if ($tenantUuid === null) {
                    throw new EnablementException('Disable requires a default tenant pointer.');
                }
                $sentinel = 'tenant:' . $tenantUuid . ':render:disable-sentinel:' . bin2hex(random_bytes(8));
                $this->store->setSentinelKey($sentinel);
            }
            $this->cache->set($sentinel, '1', 3600);
            $this->cacheTransition->purge();

            $this->connection->transaction(function (): void {
                $this->flags->put('tenancy.enabled', '0');
                if (
                    !$this->store->compareAndSet(
                        EnablementStep::DISABLING,
                        EnablementStep::DISABLED_WIDENED,
                    )
                ) {
                    throw new EnablementException('Disable flip lost a CAS race.');
                }
            });

            return $this->status();
        });
    }

    public function cancel(): EnablementStatus
    {
        return $this->lock->withLock(function (): EnablementStatus {
            $step = $this->store->step();
            $cancelable = [
                EnablementStep::INSTALLING,
                EnablementStep::AWAITING_INSTALL,
                EnablementStep::ENABLING_EXTENSION,
                EnablementStep::AWAITING_PROVIDER_BOOT,
                EnablementStep::MIGRATING_EXTENSION,
                EnablementStep::AWAITING_CONFIRM,
            ];

            if (!in_array($step, $cancelable, true)) {
                throw new EnablementException('Enablement can no longer be canceled from ' . $step->value . '.');
            }

            $this->store->recordFailureCleared();
            $this->store->clearPending();
            $this->store->setStep(EnablementStep::OFF);

            return $this->status();
        });
    }

    private function hasCollections(): bool
    {
        if (!$this->connection->getSchemaBuilder()->hasTable('collection_definitions')) {
            return false;
        }

        return $this->connection->table('collection_definitions')->count() > 0;
    }

    private function resolveRetrofit(): SchemaRetrofit
    {
        $container = $this->context->getContainer();
        if (!$container->has(TenantProvisioner::class)) {
            throw new RequestResolutionNotReadyException(
                'The tenancy extension is not booted in this process; retrofit cannot run yet.',
            );
        }

        return $container->get(SchemaRetrofit::class);
    }

    private function guardPersistedActive(): bool
    {
        $this->flags->clearCache();
        return $this->flags->get('tenancy.retrofit_active') === '1';
    }

    private function disabledPairSettled(): bool
    {
        return $this->store->step() === EnablementStep::DISABLED_WIDENED
            && !$this->guardPersistedActive();
    }

    private function settleDisable(): void
    {
        if ($this->disableProbe === null) {
            throw new EnablementException('The disabled-widened verification probe is unavailable.');
        }
        $report = $this->disableProbe->passes();
        if (!$report['ok']) {
            $this->store->recordFailure(
                EnablementStep::DISABLED_WIDENED,
                'Disabled-widened verification failed: ' . json_encode($report, JSON_THROW_ON_ERROR),
            );
            return;
        }

        try {
            $this->connection->transaction(function (): void {
                $this->store->clearSentinel();
                $this->guard->end();
            });
        } catch (\Throwable $exception) {
            $this->guard->refresh();
            $this->store->recordFailure(EnablementStep::DISABLED_WIDENED, $exception->getMessage());
        }
    }
}
