<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Resolution;

use Closure;
use Glueful\Database\Connection;
use Thallo\Tenancy\System\SystemFlags;

final class ResolutionActivationStore
{
    private const KEY_STEP = 'tenancy.resolution_step';
    private const KEY_FAILURE = 'tenancy.resolution_failure';
    private const KEY_AWAITING_BOOT = 'tenancy.resolution_awaiting_boot';
    private readonly string $bootId;

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly Connection $connection,
        private readonly ?Closure $afterResolutionWrite = null,
        ?string $bootId = null,
    ) {
        $this->bootId = $bootId ?? bin2hex(random_bytes(16));
    }

    public function step(): ResolutionActivationStep
    {
        return ResolutionActivationStep::tryFrom((string) $this->flags->get(self::KEY_STEP))
            ?? ResolutionActivationStep::INACTIVE;
    }

    public function compareAndSet(ResolutionActivationStep $expected, ResolutionActivationStep $next): bool
    {
        if ($this->step() !== $expected) {
            return false;
        }
        $this->flags->put(self::KEY_STEP, $next->value);

        return true;
    }

    public function recordFailure(ResolutionActivationStep $from, string $message): void
    {
        $this->flags->put(self::KEY_FAILURE, $message);
        $this->flags->put('tenancy.resolution_failed_from', $from->value);
        $this->flags->put(self::KEY_STEP, ResolutionActivationStep::FAILED->value);
    }

    public function failure(): ?string
    {
        return $this->flags->get(self::KEY_FAILURE);
    }

    public function markAwaitingFreshBoot(ResolutionActivationStep $expected): bool
    {
        if ($this->step() !== $expected) {
            return false;
        }
        $this->connection->transaction(function (): void {
            $this->flags->put(self::KEY_AWAITING_BOOT, $this->bootId);
            $this->flags->put(
                self::KEY_STEP,
                ResolutionActivationStep::AWAITING_FRESH_BOOT->value
            );
        });

        return true;
    }

    public function retry(): bool
    {
        $from = ResolutionActivationStep::tryFrom(
            (string) $this->flags->get('tenancy.resolution_failed_from')
        );
        if ($this->step() !== ResolutionActivationStep::FAILED || $from === null) {
            return false;
        }
        $this->flags->forget(self::KEY_FAILURE);
        $this->flags->forget('tenancy.resolution_failed_from');
        $this->flags->put(self::KEY_STEP, $from->value);

        return true;
    }

    /** Atomically exposes the full flag and FULL state. */
    public function completeFull(ResolutionActivationStep $expected): bool
    {
        if (
            $this->step() !== $expected
            || hash_equals($this->bootId, (string) $this->flags->get(self::KEY_AWAITING_BOOT))
        ) {
            return false;
        }

        $this->connection->transaction(function (): void {
            $this->flags->put('tenancy.resolution', 'full');
            if ($this->afterResolutionWrite !== null) {
                ($this->afterResolutionWrite)();
            }
            $this->flags->put(self::KEY_STEP, ResolutionActivationStep::FULL->value);
            $this->flags->forget(self::KEY_AWAITING_BOOT);
        });
        $this->flags->clearCache();

        return true;
    }

    /** Atomically removes full resolution and returns the activation machine to INACTIVE. */
    public function deactivate(ResolutionActivationStep $expected): bool
    {
        if ($this->step() !== $expected) {
            return false;
        }

        $this->connection->transaction(function (): void {
            $this->flags->forget('tenancy.resolution');
            $this->flags->put(self::KEY_STEP, ResolutionActivationStep::INACTIVE->value);
            $this->flags->forget(self::KEY_FAILURE);
            $this->flags->forget('tenancy.resolution_failed_from');
            $this->flags->forget(self::KEY_AWAITING_BOOT);
        });
        $this->flags->clearCache();

        return true;
    }
}
