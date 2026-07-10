<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use InvalidArgumentException;

/**
 * Raised when the schema retrofit is asked to operate on a driver it does not support. The tenancy
 * retrofit is PostgreSQL-only; `pgsql` is the only supported driver and everything else is rejected.
 */
final class UnsupportedRetrofitDriverException extends InvalidArgumentException
{
    public function __construct(string $driver)
    {
        parent::__construct(sprintf(
            'Unsupported retrofit driver "%s". The tenancy schema retrofit is PostgreSQL-only (pgsql).',
            $driver,
        ));
    }
}
