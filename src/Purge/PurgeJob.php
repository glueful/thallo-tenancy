<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Helpers\Utils;
use Glueful\Queue\Job;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;

final class PurgeJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('PurgeJob requires an ApplicationContext.');
        }
        $runUuid = $this->getData()['run_uuid'] ?? null;
        if (!is_string($runUuid) || preg_match('/\A[0-9A-Za-z]{12}\z/', $runUuid) !== 1) {
            throw new \InvalidArgumentException('PurgeJob requires a valid run_uuid.');
        }

        $container = $context->getContainer();
        $runs = $container->get(PurgeRunRepository::class);
        $runner = $container->get(TenantContextRunner::class);
        $barrier = $container->get(WriteBarrier::class);
        $workerUuid = Utils::generateNanoID(12);

        $barrier->runWritable(function () use ($context, $runs, $runner, $runUuid, $workerUuid): void {
            if (!$runs->claimRun($context, $runUuid, $workerUuid)) {
                return;
            }
            $runner->runAsSystem(fn() => $this->executeRun($context, $runs, $runUuid, $workerUuid));
        });
    }

    private function executeRun(
        ApplicationContext $context,
        PurgeRunRepository $runs,
        string $runUuid,
        string $workerUuid
    ): void {
        $container = $context->getContainer();
        $registry = $container->get(PurgeResourceRegistry::class);
        $tenants = $container->get(TenantAdministration::class);
        $audit = $container->has(TenancyLifecycleAudit::class)
            ? $container->get(TenancyLifecycleAudit::class)
            : null;
        $run = $runs->find($context, $runUuid);
        $tenantUuid = (string) $run['tenant_uuid'];
        $artifacts = $this->decodeMap($run['artifacts'] ?? '{}');
        $handlerId = 'pipeline';
        $phase = 'prepare';

        try {
            foreach ($registry->ordered() as $handler) {
                $handlerId = $handler->id();
                if (!isset($artifacts[$handlerId]) || !is_array($artifacts[$handlerId])) {
                    $artifacts[$handlerId] = $handler->prepare($context, $tenantUuid);
                    $runs->putArtifacts(
                        $context,
                        $runUuid,
                        $workerUuid,
                        $handlerId,
                        $artifacts[$handlerId]
                    );
                }
                $runs->checkpoint($context, $runUuid, $workerUuid, $handlerId, 'prepared');
                $runs->renewLease($context, $runUuid, $workerUuid);
            }

            foreach ($registry->ordered() as $handler) {
                $handlerId = $handler->id();
                $phase = 'purge';
                $handler->purge($context, $tenantUuid, $artifacts[$handlerId] ?? []);
                $runs->checkpoint($context, $runUuid, $workerUuid, $handlerId, 'purged');
                $phase = 'verify';
                if (!$handler->verify($context, $tenantUuid, $artifacts[$handlerId] ?? [])) {
                    throw new \RuntimeException("Purge handler '{$handlerId}' did not verify cleanly.");
                }
                $runs->checkpoint($context, $runUuid, $workerUuid, $handlerId, 'verified');
                $runs->renewLease($context, $runUuid, $workerUuid);
            }

            $handlerId = 'engine.tenant';
            $phase = 'purge';
            if (!isset($artifacts[$handlerId])) {
                $statement = db($context)->getPDO()->prepare(
                    'SELECT host FROM tenant_domains WHERE tenant_uuid = ? ORDER BY host'
                );
                $statement->execute([$tenantUuid]);
                $artifacts[$handlerId] = ['hosts' => array_values($statement->fetchAll(\PDO::FETCH_COLUMN))];
                $runs->putArtifacts($context, $runUuid, $workerUuid, $handlerId, $artifacts[$handlerId]);
            }
            $lifecycle = $tenants->getTenantLifecycle($context, $tenantUuid);
            if ($lifecycle !== null) {
                if (($lifecycle['status'] ?? null) !== 'purging') {
                    throw new \RuntimeException('Final tenant purge requires purging status.');
                }
                $tenants->purgeTenantRecord($context, $tenantUuid);
            }
            if (!$runs->markCompleted($context, $runUuid, $workerUuid)) {
                throw new \RuntimeException('Purge run lease was lost before completion.');
            }
            if ($audit instanceof TenancyLifecycleAudit) {
                $audit->record('tenant.purge_completed', $run['requested_by_uuid'] ?? null, $tenantUuid, [
                    'run_uuid' => $runUuid,
                ]);
                foreach (($artifacts[$handlerId]['hosts'] ?? []) as $host) {
                    if (is_string($host)) {
                        $audit->record('host.released', $run['requested_by_uuid'] ?? null, $tenantUuid, [
                            'host' => $host,
                            'source' => 'tenant_purge',
                        ]);
                    }
                }
            }
        } catch (\Throwable $exception) {
            $recorded = $runs->markFailed($context, $runUuid, $workerUuid, $handlerId, $phase);
            if ($recorded && $audit instanceof TenancyLifecycleAudit) {
                $audit->record('tenant.purge_failed', $run['requested_by_uuid'] ?? null, $tenantUuid, [
                    'run_uuid' => $runUuid,
                    'handler' => $handlerId,
                    'phase' => $phase,
                ]);
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode(is_string($value) ? $value : '{}', true);
        return is_array($decoded) ? $decoded : [];
    }
}
