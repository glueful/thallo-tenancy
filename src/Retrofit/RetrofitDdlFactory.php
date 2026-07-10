<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

/**
 * Resolves the {@see RetrofitDdl} strategy for a database driver token. The tenancy retrofit is
 * PostgreSQL-only, so only `pgsql` is admitted; `mysql`, `sqlite` and any other driver raise
 * {@see UnsupportedRetrofitDriverException}. Registered as a service so later retrofit tasks can
 * derive the concrete strategy from the live connection's driver.
 */
final class RetrofitDdlFactory
{
    public function for(string $driver): RetrofitDdl
    {
        return match ($driver) {
            'pgsql' => new PostgresRetrofitDdl(),
            default => throw new UnsupportedRetrofitDriverException($driver),
        };
    }
}
