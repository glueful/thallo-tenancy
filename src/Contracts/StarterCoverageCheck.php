<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Contracts;

interface StarterCoverageCheck
{
    /** @return list<string> */
    public function coverageViolations(): array;
}
