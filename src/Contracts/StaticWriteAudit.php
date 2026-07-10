<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Contracts;

interface StaticWriteAudit
{
    public function available(): bool;

    /** @return array{available:bool,unclassified:list<string>,bucketViolations:list<string>,wrapperMismatches:list<string>} */
    public function run(): array;
}
