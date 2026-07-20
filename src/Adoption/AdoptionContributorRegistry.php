<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Adoption;

/** Mirrors {@see \Thallo\Tenancy\Purge\PurgeResourceRegistry}'s registration shape. */
final class AdoptionContributorRegistry
{
    /** @var array<string, AdoptionContributor> */
    private array $contributors = [];

    public function register(AdoptionContributor $contributor): void
    {
        if (isset($this->contributors[$contributor->id()])) {
            throw new \LogicException("Duplicate adoption contributor '{$contributor->id()}'.");
        }
        $this->contributors[$contributor->id()] = $contributor;
    }

    /** @return list<AdoptionContributor> */
    public function all(): array
    {
        return array_values($this->contributors);
    }
}
