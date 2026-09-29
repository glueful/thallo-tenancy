<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Adoption;

/** The adoption flip could not close the gate: single-store work is still running elsewhere. */
final class AdoptionGateBusyException extends \RuntimeException
{
    public function __construct(int $timeoutMs)
    {
        parent::__construct(
            "Payment work that started before tenancy is still running in another process after {$timeoutMs} ms "
            . '(for example, a request mid-checkout or a queue worker). Let it finish, or stop queue '
            . 'workers, then retry.'
        );
    }
}
