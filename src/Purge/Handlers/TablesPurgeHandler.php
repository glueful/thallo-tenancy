<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Purge\Handlers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use PDO;
use Thallo\Tenancy\Purge\PurgeHandler;
use Thallo\Tenancy\ThalloTenantTables;

final class TablesPurgeHandler implements PurgeHandler
{
    private const SPECIALIZED = [
        'media_assets',
        'media_meta',
        'media_usage',
        'collection_definitions',
        'collection_schema_changes',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function id(): string
    {
        return 'thallo.tables';
    }

    public function dependsOn(): array
    {
        return ['thallo.media'];
    }

    public function prepare(ApplicationContext $context, string $tenantUuid): array
    {
        $tables = array_values(array_filter($this->targetTables(), fn(string $table): bool => $this->exists($table)));
        if ($tables === []) {
            return ['tables' => []];
        }

        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $statement = $this->connection->getPDO()->prepare(
            'SELECT child.relname AS child, parent.relname AS parent FROM pg_constraint c '
            . 'JOIN pg_class child ON child.oid = c.conrelid '
            . 'JOIN pg_class parent ON parent.oid = c.confrelid '
            . "WHERE c.contype='f' AND child.relname IN ({$placeholders}) "
            . "AND parent.relname IN ({$placeholders})"
        );
        $statement->execute([...$tables, ...$tables]);
        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new \RuntimeException('Generic tenant-table purge has unresolved foreign-key dependencies.');
        }
        return ['tables' => $tables];
    }

    public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void
    {
        foreach ($this->tablesFromArtifacts($artifacts) as $table) {
            $this->connection->table($table)->where('tenant_uuid', $tenantUuid)->forceDelete();
        }
    }

    public function verify(ApplicationContext $context, string $tenantUuid, array $artifacts): bool
    {
        foreach ($this->targetTables() as $table) {
            if (!$this->exists($table)) {
                continue;
            }
            $statement = $this->connection->getPDO()->prepare(
                "SELECT 1 FROM {$table} WHERE tenant_uuid = ? LIMIT 1"
            );
            $statement->execute([$tenantUuid]);
            if ($statement->fetchColumn() !== false) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private function targetTables(): array
    {
        return array_values(array_diff(ThalloTenantTables::tableNames(), self::SPECIALIZED));
    }

    /** @param array<string, mixed> $artifacts @return list<string> */
    private function tablesFromArtifacts(array $artifacts): array
    {
        $allowed = array_flip($this->targetTables());
        $tables = $artifacts['tables'] ?? [];
        if (!is_array($tables)) {
            throw new \RuntimeException('Invalid table purge manifest.');
        }
        foreach ($tables as $table) {
            if (!is_string($table) || !isset($allowed[$table])) {
                throw new \RuntimeException('Unsafe table in purge manifest.');
            }
        }
        return array_values($tables);
    }

    private function exists(string $table): bool
    {
        $statement = $this->connection->getPDO()->prepare('SELECT to_regclass(?)');
        $statement->execute([$table]);
        return $statement->fetchColumn() !== null;
    }
}
