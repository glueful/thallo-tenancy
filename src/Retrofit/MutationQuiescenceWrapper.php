<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Execution\ExecutionWrapperInterface;
use PDOStatement;
use Thallo\Tenancy\ThalloTenantTables;

/** Holds the shared mutation lock across execution of every owned-table mutation. */
final class MutationQuiescenceWrapper implements ExecutionWrapperInterface
{
    /** @var list<string>|null */
    private static ?array $owned = null;

    public function __construct(private readonly MutationBoundaryLock $lock)
    {
    }

    public function around(string $sql, array $bindings, callable $proceed): PDOStatement
    {
        if (!$this->isOwnedMutation($sql)) {
            return $proceed();
        }

        if (!$this->lock->tryShared()) {
            throw new RetrofitInProgressException('A tenancy schema change is in progress.');
        }

        try {
            return $proceed();
        } finally {
            $this->lock->releaseShared();
        }
    }

    private function isOwnedMutation(string $sql): bool
    {
        $lower = strtolower(ltrim($sql));
        if (
            !str_starts_with($lower, 'insert')
            && !str_starts_with($lower, 'update')
            && !str_starts_with($lower, 'delete')
        ) {
            return false;
        }

        $padded = ' ' . $lower . ' ';
        foreach (self::owned() as $table) {
            if (preg_match('/[\s"`\']' . preg_quote($table, '/') . '[\s"`\'(]/', $padded) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function owned(): array
    {
        return self::$owned ??= array_map('strtolower', ThalloTenantTables::tableNames());
    }
}
