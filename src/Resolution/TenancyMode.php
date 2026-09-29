<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Resolution;

/** The three tenancy modes {@see TenancyModePolicy} reads off the system flags. */
enum TenancyMode: string
{
    /** (a) the schema is not widened: every row belongs to the single store, tenant ''. */
    case Sentinel = 'sentinel';

    /** (b) the schema is widened but enforcement is not active: the persisted default tenant. */
    case DefaultTenant = 'default';

    /** (c) enforcement is active: the request's tenant, from the shared resolver. */
    case Enforced = 'enforced';
}
