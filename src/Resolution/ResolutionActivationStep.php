<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Resolution;

enum ResolutionActivationStep: string
{
    case INACTIVE = 'inactive';
    case MAPPING_HOSTS = 'mapping_hosts';
    case VERIFYING_WIRING = 'verifying_wiring';
    case REBUILDING_ROUTES = 'rebuilding_routes';
    case AWAITING_FRESH_BOOT = 'awaiting_fresh_boot';
    case FULL = 'full';
    case FAILED = 'failed';
}
