<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Http\Exceptions\Server\ServiceUnavailableException;

/**
 * Raised by the write-barrier while the enable-time schema retrofit is in progress. Extends the
 * framework 503 so it is surfaced as a Service Unavailable (with a Retry-After) by the existing HTTP
 * exception handler — no handler edit required.
 */
final class RetrofitInProgressException extends ServiceUnavailableException
{
    public function __construct()
    {
        parent::__construct('Tenancy retrofit in progress — writes are temporarily unavailable.', retryAfter: 30);
    }
}
