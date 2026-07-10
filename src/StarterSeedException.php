<?php

declare(strict_types=1);

namespace Thallo\Tenancy;

final class StarterSeedException extends \RuntimeException
{
    public function __construct(
        public readonly string $definitionLabel,
        \Throwable $previous,
    ) {
        parent::__construct("Starter seed failed at {$definitionLabel}: {$previous->getMessage()}", 0, $previous);
    }
}
