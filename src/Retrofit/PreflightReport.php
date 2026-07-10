<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

/**
 * Immutable result of the enable-time uniqueness preflight: the set of owned tables whose existing rows
 * would collide once every row is stamped with the same default tenant (while a single tenant exists,
 * the widened unique reduces to its business-key columns). No violations means the retrofit can widen
 * every unique without introducing a constraint violation.
 *
 * @phpstan-type Violation array{
 *   table: string,
 *   columns: list<string>,
 *   groups: int,
 *   examples: list<array{values: array<string, scalar|null>, count: int}>
 * }
 */
final class PreflightReport
{
    /** @param list<Violation> $violations */
    public function __construct(private readonly array $violations)
    {
    }

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    /** @return list<Violation> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** Throw {@see UniquenessPreflightException} carrying this report when any violation exists. */
    public function throwIfFailed(): void
    {
        if ($this->hasViolations()) {
            throw new UniquenessPreflightException($this);
        }
    }

    /** Human-readable one-line summary, used as the exception message. */
    public function summary(): string
    {
        if ($this->violations === []) {
            return 'Uniqueness preflight passed: no duplicate business keys found.';
        }

        $parts = [];
        foreach ($this->violations as $violation) {
            $parts[] = $violation['table'] . '(' . implode(', ', $violation['columns']) . '): '
                . $violation['groups'] . ' duplicate group(s)';
        }

        return 'Uniqueness preflight failed — ' . count($this->violations)
            . ' unique(s) would collide once tenancy is enabled: ' . implode('; ', $parts) . '.';
    }
}
