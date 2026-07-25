<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use PDO;

final class PurgeRunRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(ApplicationContext $context, string $tenantUuid, ?string $actorUuid): string
    {
        $uuid = Utils::generateNanoID(12);
        $statement = $this->connection->getPDO()->prepare(
            'INSERT INTO thallo_tenant_purge_runs '
            . '(uuid, tenant_uuid, requested_by_uuid, status, attempts, plan, artifacts, created_at, updated_at) '
            . "VALUES (?, ?, ?, 'requested', 0, '{}'::jsonb, '{}'::jsonb, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $statement->execute([$uuid, $tenantUuid, $actorUuid]);
        return $uuid;
    }

    /** @return array<string, mixed> */
    public function find(ApplicationContext $context, string $runUuid): array
    {
        $statement = $this->connection->getPDO()->prepare(
            'SELECT * FROM thallo_tenant_purge_runs WHERE uuid = ?'
        );
        $statement->execute([$runUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Purge run was not found.');
        }
        return $row;
    }

    /**
     * "Purging, but nothing is actually purging it": true when a run needs OPERATOR attention —
     * dispatch never landed (`requested`/`dispatch_failed`), the job failed, a worker died
     * mid-run (lease expired), or the run has sat `queued` untouched past the grace window
     * (no queue worker consuming — the dev-install classic). A fresh `queued` run inside the
     * window and a leased `running` run are healthy, not stalled.
     *
     * @param array<string, mixed> $run A `thallo_tenant_purge_runs` row
     */
    public static function isStalled(array $run, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $status = (string) ($run['status'] ?? '');

        if (in_array($status, ['requested', 'dispatch_failed', 'failed'], true)) {
            return true;
        }
        if (!in_array($status, ['queued', 'running'], true)) {
            return false;
        }

        $lease = $run['lease_expires_at'] ?? null;
        if (is_string($lease) && $lease !== '' && new \DateTimeImmutable($lease) < $now) {
            return true;
        }

        if ($status === 'queued' && (int) ($run['attempts'] ?? 0) === 0) {
            $created = $run['created_at'] ?? null;

            return is_string($created)
                && $created !== ''
                && new \DateTimeImmutable($created) <= $now->modify('-120 seconds');
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    public function findByTenant(ApplicationContext $context, string $tenantUuid): ?array
    {
        $statement = $this->connection->getPDO()->prepare(
            "SELECT * FROM thallo_tenant_purge_runs WHERE tenant_uuid = ? AND status <> 'completed' "
            . 'ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$tenantUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function recoverable(ApplicationContext $context): array
    {
        $statement = $this->connection->getPDO()->query(
            "SELECT * FROM thallo_tenant_purge_runs WHERE status IN ('requested','dispatch_failed','failed') "
            . "OR (status IN ('queued','running') AND lease_expires_at < CURRENT_TIMESTAMP) ORDER BY id"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function claimDispatch(ApplicationContext $context, string $runUuid): bool
    {
        $statement = $this->connection->getPDO()->prepare(
            "UPDATE thallo_tenant_purge_runs SET status='queued', lease_owner=NULL, "
            . "lease_expires_at=CURRENT_TIMESTAMP + INTERVAL '5 minutes', updated_at=CURRENT_TIMESTAMP "
            . "WHERE uuid=? AND (status IN ('requested','dispatch_failed','failed') OR "
            . "(status IN ('queued','running') AND lease_expires_at < CURRENT_TIMESTAMP))"
        );
        $statement->execute([$runUuid]);
        return $statement->rowCount() === 1;
    }

    public function claimRun(ApplicationContext $context, string $runUuid, string $workerUuid): bool
    {
        $statement = $this->connection->getPDO()->prepare(
            "UPDATE thallo_tenant_purge_runs SET status='running', lease_owner=?, attempts=attempts+1, "
            . "lease_expires_at=CURRENT_TIMESTAMP + INTERVAL '15 minutes', updated_at=CURRENT_TIMESTAMP "
            . "WHERE uuid=? AND (status IN ('queued','failed') OR "
            . "(status='running' AND lease_expires_at < CURRENT_TIMESTAMP))"
        );
        $statement->execute([$workerUuid, $runUuid]);
        return $statement->rowCount() === 1;
    }

    public function renewLease(ApplicationContext $context, string $runUuid, string $workerUuid): void
    {
        $statement = $this->connection->getPDO()->prepare(
            "UPDATE thallo_tenant_purge_runs SET lease_expires_at=CURRENT_TIMESTAMP + INTERVAL '15 minutes', "
            . "updated_at=CURRENT_TIMESTAMP WHERE uuid=? AND status='running' AND lease_owner=?"
        );
        $statement->execute([$runUuid, $workerUuid]);
        $this->assertOwned($statement->rowCount());
    }

    public function markDispatchFailed(ApplicationContext $context, string $runUuid): void
    {
        $statement = $this->connection->getPDO()->prepare(
            "UPDATE thallo_tenant_purge_runs SET status='dispatch_failed', lease_expires_at=NULL, "
            . 'updated_at=CURRENT_TIMESTAMP WHERE uuid=? AND status=\'queued\''
        );
        $statement->execute([$runUuid]);
    }

    public function markCompleted(ApplicationContext $context, string $runUuid, string $workerUuid): bool
    {
        return $this->terminal($runUuid, $workerUuid, 'completed', null, null);
    }

    public function markFailed(
        ApplicationContext $context,
        string $runUuid,
        string $workerUuid,
        string $handler,
        string $phase
    ): bool {
        return $this->terminal($runUuid, $workerUuid, 'failed', $handler, $phase);
    }

    public function checkpoint(
        ApplicationContext $context,
        string $runUuid,
        string $workerUuid,
        string $handlerId,
        string $phase
    ): void {
        $this->mergeJson($runUuid, $workerUuid, 'plan', $handlerId, $phase);
    }

    /** @param array<string, mixed> $artifacts */
    public function putArtifacts(
        ApplicationContext $context,
        string $runUuid,
        string $workerUuid,
        string $handlerId,
        array $artifacts
    ): void {
        $this->mergeJson($runUuid, $workerUuid, 'artifacts', $handlerId, $artifacts);
    }

    private function terminal(
        string $runUuid,
        string $workerUuid,
        string $status,
        ?string $handler,
        ?string $phase
    ): bool {
        $statement = $this->connection->getPDO()->prepare(
            'UPDATE thallo_tenant_purge_runs SET status=?, failed_handler=?, failed_phase=?, '
            . 'lease_owner=NULL, lease_expires_at=NULL, updated_at=CURRENT_TIMESTAMP '
            . "WHERE uuid=? AND status='running' AND lease_owner=?"
        );
        $statement->execute([$status, $handler, $phase, $runUuid, $workerUuid]);
        return $statement->rowCount() === 1;
    }

    private function mergeJson(
        string $runUuid,
        string $workerUuid,
        string $column,
        string $key,
        mixed $value
    ): void {
        $this->connection->transaction(function () use ($runUuid, $workerUuid, $column, $key, $value): void {
            $run = $this->findOwnedForUpdate($runUuid, $workerUuid);
            $decoded = json_decode((string) ($run[$column] ?? '{}'), true);
            $all = is_array($decoded) ? $decoded : [];
            $all[$key] = $value;
            $statement = $this->connection->getPDO()->prepare(
                "UPDATE thallo_tenant_purge_runs SET {$column}=?::jsonb, updated_at=CURRENT_TIMESTAMP "
                . "WHERE uuid=? AND status='running' AND lease_owner=?"
            );
            $statement->execute([json_encode($all, JSON_THROW_ON_ERROR), $runUuid, $workerUuid]);
            $this->assertOwned($statement->rowCount());
        });
    }

    /** @return array<string, mixed> */
    private function findOwnedForUpdate(string $runUuid, string $workerUuid): array
    {
        $statement = $this->connection->getPDO()->prepare(
            "SELECT * FROM thallo_tenant_purge_runs WHERE uuid=? AND status='running' AND lease_owner=? FOR UPDATE"
        );
        $statement->execute([$runUuid, $workerUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Purge run lease is not owned by this worker.');
        }
        return $row;
    }

    private function assertOwned(int $affected): void
    {
        if ($affected !== 1) {
            throw new \RuntimeException('Purge run lease is not owned by this worker.');
        }
    }
}
