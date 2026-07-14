<?php

declare(strict_types=1);

namespace Thallo\Tenancy\PublicOrigin;

final class PublicOriginValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Public origin validation failed.');
    }
}
