<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use RuntimeException;

/**
 * Thrown when the uniqueness preflight finds existing rows that would violate a widened unique once the
 * default tenant is stamped across the table. Carries the full {@see PreflightReport} so the caller can
 * surface exactly which tables and column sets are in conflict. Raised BEFORE any retrofit mutation, so
 * the operation aborts with the schema untouched.
 */
final class UniquenessPreflightException extends RuntimeException
{
    public function __construct(private readonly PreflightReport $report)
    {
        parent::__construct($report->summary());
    }

    public function report(): PreflightReport
    {
        return $this->report;
    }
}
