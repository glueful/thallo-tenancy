<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

final class EnablementStatus
{
    public function __construct(
        public readonly EnablementStep $step,
        public readonly bool $enabled,
        public readonly string $schemaState,
        public readonly int $progress,
        public readonly bool $reloading,
        public readonly string $mode,
        public readonly ?string $pendingSlug = null,
        public readonly ?string $pendingName = null,
        public readonly ?string $failure = null,
        public readonly ?string $cliFallback = null,
    ) {
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'step' => $this->step->value,
            'enabled' => $this->enabled,
            'schema_state' => $this->schemaState,
            'progress' => $this->progress,
            'reloading' => $this->reloading,
            'mode' => $this->mode,
            'pending_slug' => $this->pendingSlug,
            'pending_name' => $this->pendingName,
            'failure' => $this->failure,
            'cli_fallback' => $this->cliFallback,
        ];
    }
}
