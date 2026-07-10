<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

/**
 * Resumable enablement machine (spec §9). Reaching `on` crosses TWO fresh-boot boundaries — one so the
 * newly-installed provider's autoloader/bindings appear, one so `tenancy.enabled` arms table registration:
 *   off → installing → [awaiting_install] → enabling_extension → awaiting_provider_boot
 *       → migrating_extension → awaiting_confirm → retrofitting → reloading → (fresh boot) finalizing → on
 * `awaiting_provider_boot` = the provider was written into config/extensions.php + activated, but is NOT
 * bound in THIS already-booted container; the next fresh request has it and may verify contracts + migrate.
 * `reloading` = retrofit done, tenancy.enabled=1, BARRIER STILL UP. `finalizing` = a fresh-process
 * finalize() CLAIMED the transition (barrier still up) and is verifying enforcement; a crash here is
 * recoverable. Only the final atomic step (lower barrier + set `on` in ONE system-channel transaction)
 * reaches `on`. Disable mirrors that discipline: on -> disabling -> disabled_widened, with the
 * persisted retrofit barrier distinguishing an awaiting-fresh-boot pair from a settled pair.
 */
enum EnablementStep: string
{
    case OFF = 'off';
    case INSTALLING = 'installing';
    case AWAITING_INSTALL = 'awaiting_install';
    case ENABLING_EXTENSION = 'enabling_extension';
    case AWAITING_PROVIDER_BOOT = 'awaiting_provider_boot';
    case MIGRATING_EXTENSION = 'migrating_extension';
    case AWAITING_CONFIRM = 'awaiting_confirm';
    case RETROFITTING = 'retrofitting';
    case RELOADING = 'reloading';
    case FINALIZING = 'finalizing';
    case ON = 'on';
    case DISABLING = 'disabling';
    case DISABLED_WIDENED = 'disabled_widened';
    case FAILED = 'failed';

    /** Steps that REQUIRE a fresh process before the machine can advance (CLI/HTTP must stop + re-request). */
    public function needsFreshBoot(): bool
    {
        return $this === self::AWAITING_PROVIDER_BOOT || $this === self::RELOADING;
    }

    public function progress(): int
    {
        return match ($this) {
            self::OFF => 0,
            self::INSTALLING, self::AWAITING_INSTALL => 10,
            self::ENABLING_EXTENSION => 20,
            self::AWAITING_PROVIDER_BOOT => 30,
            self::MIGRATING_EXTENSION => 40,
            self::AWAITING_CONFIRM => 50,
            self::RETROFITTING => 75,
            self::RELOADING => 90,
            self::FINALIZING => 95,
            self::ON => 100,
            self::DISABLING => 10,
            self::DISABLED_WIDENED => 100,
            self::FAILED => 0,
        };
    }
}
