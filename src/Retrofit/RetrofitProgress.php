<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use InvalidArgumentException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Persisted per-table phase ladder for the enable-time schema retrofit.
 *
 * The retrofit advances each owned table through a ranked sequence of phases and records how far
 * each table has come so an interrupted run can resume exactly where it stopped. The whole
 * {table: phase} map lives as a single JSON blob in one {@see SystemFlags} key, so progress
 * survives a crash and is visible to a fresh process.
 *
 * {@see mark()} is monotonic: a later mark at a lower rank never downgrades a table — the highest
 * rank reached always wins.
 */
final class RetrofitProgress
{
    // Additive path (per-table column widen).
    public const COLUMN_ADDED = 'column_added';
    public const BACKFILLED = 'backfilled';
    public const NOT_NULL = 'not_null';
    public const NARROW_UNIQUE_DROPPED = 'narrow_unique_dropped';
    public const WIDENED_UNIQUE_ADDED = 'widened_unique_added';

    // Rebuild path (copy-rebuild for PK / inline-unique tables).
    public const REBUILD_CREATED = 'rebuild_created';
    public const REBUILD_SWAPPED = 'rebuild_swapped';
    public const REBUILT = 'rebuilt';

    /** SystemFlags key holding the JSON-encoded {table: phase} map. */
    private const KEY = 'tenancy.retrofit_progress';

    /**
     * The ranked ladder, low → high. A phase's position is its rank; comparisons in
     * {@see reached()} and the monotonic guard in {@see mark()} use these indices.
     *
     * @var list<string>
     */
    private const ORDER = [
        self::COLUMN_ADDED,
        self::BACKFILLED,
        self::NOT_NULL,
        self::NARROW_UNIQUE_DROPPED,
        self::WIDENED_UNIQUE_ADDED,
        self::REBUILD_CREATED,
        self::REBUILD_SWAPPED,
        self::REBUILT,
    ];

    public function __construct(private readonly SystemFlags $flags)
    {
    }

    /** Current phase for a table, or null if none recorded. */
    public function phaseOf(string $table): ?string
    {
        return $this->snapshot()[$table] ?? null;
    }

    /** True if the table's recorded phase is at-or-past $phase in the ranked order. */
    public function reached(string $table, string $phase): bool
    {
        $target = $this->rank($phase);
        $current = $this->phaseOf($table);
        if ($current === null) {
            return false;
        }

        return $this->rank($current) >= $target;
    }

    /**
     * Record that a table reached $phase. Monotonic: if the table is already at an equal-or-higher
     * rank, the mark is ignored (never moves the table backward).
     */
    public function mark(string $table, string $phase): void
    {
        $rank = $this->rank($phase);
        $map = $this->snapshot();

        $current = $map[$table] ?? null;
        if ($current !== null && $this->rank($current) >= $rank) {
            return;
        }

        $map[$table] = $phase;
        $this->persist($map);
    }

    /** Clear a table's entry (no-op if it has none). */
    public function reset(string $table): void
    {
        $map = $this->snapshot();
        if (!array_key_exists($table, $map)) {
            return;
        }

        unset($map[$table]);
        $this->persist($map);
    }

    /**
     * The whole {table: phase} map. Unknown/malformed entries are dropped defensively.
     *
     * @return array<string,string>
     */
    public function snapshot(): array
    {
        $raw = $this->flags->get(self::KEY);
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $table => $phase) {
            if (is_string($table) && is_string($phase) && in_array($phase, self::ORDER, true)) {
                $out[$table] = $phase;
            }
        }

        return $out;
    }

    /** @param array<string,string> $map */
    private function persist(array $map): void
    {
        if ($map === []) {
            $this->flags->forget(self::KEY);
            return;
        }

        $this->flags->put(self::KEY, (string) json_encode($map));
    }

    private function rank(string $phase): int
    {
        $rank = array_search($phase, self::ORDER, true);
        if ($rank === false) {
            throw new InvalidArgumentException("Unknown retrofit phase: {$phase}");
        }

        return $rank;
    }
}
