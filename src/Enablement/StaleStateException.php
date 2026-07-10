<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use RuntimeException;

/**
 * Thrown when an enablement operation required the state machine to be at a specific step (or observed
 * a lost {@see EnablementStore::compareAndSet()} race) but the machine was actually at a different step.
 * Carries both the expected and the actually-observed step so callers/logs can report exactly what raced.
 */
final class StaleStateException extends RuntimeException
{
    public function __construct(public readonly EnablementStep $expected, public readonly EnablementStep $actual)
    {
        parent::__construct(sprintf(
            'Enablement state is stale: expected step "%s" but observed "%s".',
            $expected->value,
            $actual->value,
        ));
    }
}
