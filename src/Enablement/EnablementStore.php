<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Thallo\Tenancy\System\SystemFlags;

/**
 * Persists the resumable enablement state machine's current step plus failure and pending-tenant
 * metadata in the unscoped `thallo_system_flags` channel (via {@see SystemFlags}), so it survives the
 * fresh-boot boundaries the machine requires. Deliberately thin: it knows nothing about which
 * transitions are legal — TenancyEnablement owns that; this store only reads/writes rows and offers a
 * checked compare-and-set primitive so callers can detect a lost race instead of blindly overwriting.
 */
final class EnablementStore
{
    private const KEY_STEP = 'tenancy.enable_step';
    private const KEY_FAILURE = 'tenancy.enable_failure';
    private const KEY_FAILED_FROM = 'tenancy.enable_failed_from';
    private const KEY_PENDING_SLUG = 'tenancy.enable_pending_slug';
    private const KEY_PENDING_NAME = 'tenancy.enable_pending_name';

    public function __construct(private readonly SystemFlags $flags)
    {
    }

    /** No row yet (fresh install / never started) reads as OFF. */
    public function step(): EnablementStep
    {
        $value = $this->flags->get(self::KEY_STEP);
        return $value === null ? EnablementStep::OFF : (EnablementStep::tryFrom($value) ?? EnablementStep::OFF);
    }

    public function setStep(EnablementStep $step): void
    {
        $this->flags->put(self::KEY_STEP, $step->value);
    }

    /**
     * Compare-and-set: writes $next ONLY if the current step is exactly $expected, and reports whether
     * it did. Callers MUST check the result — a lost race (a concurrent actor already moved the step)
     * means $next was never written, and silently proceeding as if it had would clobber the machine.
     */
    public function compareAndSet(EnablementStep $expected, EnablementStep $next): bool
    {
        if ($this->step() !== $expected) {
            return false;
        }
        $this->setStep($next);
        return true;
    }

    /**
     * Records a failure and moves the machine to FAILED, remembering the step it failed FROM so
     * {@see EnablementStore::failedFrom()} lets a later retry() resume where it left off.
     */
    public function recordFailure(EnablementStep $from, string $message): void
    {
        $this->flags->put(self::KEY_FAILURE, $message);
        $this->flags->put(self::KEY_FAILED_FROM, $from->value);
        $this->setStep(EnablementStep::FAILED);
    }

    /** Forgets the recorded failure + failed-from step. Does NOT touch the current step. */
    public function recordFailureCleared(): void
    {
        $this->flags->forget(self::KEY_FAILURE);
        $this->flags->forget(self::KEY_FAILED_FROM);
    }

    public function failure(): ?string
    {
        return $this->flags->get(self::KEY_FAILURE);
    }

    public function failedFrom(): ?EnablementStep
    {
        $value = $this->flags->get(self::KEY_FAILED_FROM);
        return $value === null ? null : EnablementStep::tryFrom($value);
    }

    public function setPendingTenant(string $slug, string $name): void
    {
        $this->flags->put(self::KEY_PENDING_SLUG, $slug);
        $this->flags->put(self::KEY_PENDING_NAME, $name);
    }

    public function pendingSlug(): ?string
    {
        return $this->flags->get(self::KEY_PENDING_SLUG);
    }

    public function pendingName(): ?string
    {
        return $this->flags->get(self::KEY_PENDING_NAME);
    }

    public function clearPending(): void
    {
        $this->flags->forget(self::KEY_PENDING_SLUG);
        $this->flags->forget(self::KEY_PENDING_NAME);
    }
}
