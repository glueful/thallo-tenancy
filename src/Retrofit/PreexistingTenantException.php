<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use RuntimeException;

/**
 * Thrown by {@see DefaultTenant::ensure()} when a fresh enablement finds tenant rows already present
 * (and no operation-scoped provisioning uuid recorded). The retrofit must NOT adopt an unrelated
 * existing tenant as its default — that would silently attach a single-tenant install's data to a
 * tenant the operator never chose. Raised before any provisioning, so state is untouched.
 */
final class PreexistingTenantException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Refusing to provision a default tenant: existing tenant rows were found on a fresh '
            . 'enablement. Resolve the pre-existing tenant data before enabling tenancy.'
        );
    }
}
