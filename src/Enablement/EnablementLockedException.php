<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

final class EnablementLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another tenancy enablement operation is already running.');
    }
}
