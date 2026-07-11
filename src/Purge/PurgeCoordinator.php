<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Queue\QueueManager;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;

final class PurgeCoordinator
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly TenantAdministration $tenants,
        private readonly PurgeRunRepository $runs,
        private readonly ?TenancyLifecycleAudit $audit = null,
    ) {
    }

    public function request(string $tenantUuid, ?string $actorUuid): string
    {
        return $this->connection->transaction(function () use ($tenantUuid, $actorUuid): string {
            $this->lockTenant($tenantUuid);
            $existing = $this->runs->findByTenant($this->context, $tenantUuid);
            if ($existing !== null) {
                return (string) $existing['uuid'];
            }

            $runUuid = $this->runs->create($this->context, $tenantUuid, $actorUuid);
            $this->tenants->beginPurge($this->context, $tenantUuid);
            $this->connection->afterCommit(function () use ($runUuid, $tenantUuid, $actorUuid): void {
                $this->audit?->record('tenant.purge_requested', $actorUuid, $tenantUuid, ['run_uuid' => $runUuid]);
                try {
                    $this->dispatch($runUuid);
                } catch (\Throwable) {
                    // The accepted request is durable as dispatch_failed; the recovery command retries it.
                }
            });
            return $runUuid;
        });
    }

    public function dispatch(string $runUuid): bool
    {
        if (!$this->runs->claimDispatch($this->context, $runUuid)) {
            return false;
        }
        try {
            QueueManager::setContext($this->context);
            QueueManager::createDefault()->push(PurgeJob::class, ['run_uuid' => $runUuid], 'tenancy-purge');
            return true;
        } catch (\Throwable $exception) {
            $this->runs->markDispatchFailed($this->context, $runUuid);
            throw $exception;
        }
    }

    public function recover(): int
    {
        $dispatched = 0;
        foreach ($this->runs->recoverable($this->context) as $run) {
            try {
                if ($this->dispatch((string) $run['uuid'])) {
                    $dispatched++;
                }
            } catch (\Throwable) {
                // Keep sweeping; this run remains dispatch_failed and recoverable.
            }
        }
        return $dispatched;
    }

    private function lockTenant(string $tenantUuid): void
    {
        $statement = $this->connection->getPDO()->prepare(
            "SELECT pg_advisory_xact_lock(hashtextextended(?, 0))"
        );
        $statement->execute(['thallo:tenant-purge:' . $tenantUuid]);
    }
}
