<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Execution\QueryInterceptorInterface;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Barrier at the single mutation chokepoint (QueryExecutor::executeStatement → runInterceptors). Fires
 * for INSERT/UPDATE/DELETE; SELECTs and non-owned mutations return immediately WITHOUT consulting the
 * guard. When active and the statement mutates an owned table, it throws — refusing every builder
 * writer uniformly. The retrofit's own raw PDO bypasses QueryExecutor, so the engine is unaffected.
 *
 * Owned-table matching models {@see \Glueful\Extensions\Tenancy\Query\TenantQueryGuard}'s approach:
 * lowercase the SQL, then look for each owned table name at a token boundary. The owned check runs
 * BEFORE active() so non-owned mutations skip the guard entirely.
 */
final class RetrofitWriteBarrierInterceptor implements QueryInterceptorInterface
{
    /** @var list<string>|null */
    private static ?array $owned = null;

    public function __construct(private readonly RetrofitMaintenanceGuard $guard)
    {
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function before(string $sql, array $bindings): void
    {
        $lower = strtolower(ltrim($sql));
        $isMutation = str_starts_with($lower, 'insert')
            || str_starts_with($lower, 'update')
            || str_starts_with($lower, 'delete');
        if (!$isMutation) {
            return; // SELECT / DDL-through-builder: never touch active()
        }

        $padded = ' ' . $lower . ' ';
        foreach (self::owned() as $table) {
            // Owned-table check BEFORE active() so non-owned mutations skip the guard entirely.
            if (preg_match('/[\s"`\']' . preg_quote($table, '/') . '[\s"`\'(]/', $padded) === 1) {
                if ($this->guard->active()) {
                    throw new RetrofitInProgressException();
                }
                return;
            }
        }
    }

    /** @return list<string> */
    private static function owned(): array
    {
        return self::$owned ??= array_map('strtolower', ThalloTenantTables::tableNames());
    }
}
