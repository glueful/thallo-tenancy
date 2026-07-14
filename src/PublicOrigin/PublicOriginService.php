<?php

declare(strict_types=1);

namespace Thallo\Tenancy\PublicOrigin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Resolution\ResolutionActivationStep;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;

final class PublicOriginService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PublicOriginStore $store,
        private readonly ResolutionActivationStore $activation,
        private readonly EnablementLock $lock,
    ) {
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        // Refresh persisted flags before deriving desired values/source; applied values remain the
        // immutable boot snapshot until restart.
        $stale = $this->store->isStale();
        $base = $this->store->desiredBaseDomain();
        $hosts = $this->store->desiredHosts();

        return [
            'base_domain' => is_string($base) && $base !== '' ? $base : null,
            'default_hosts' => $hosts,
            'applied_base_domain' => $this->store->appliedBaseDomain(),
            'applied_default_hosts' => $this->store->appliedHosts(),
            'base_domain_source' => $this->source($this->store->persistedBaseDomain() !== null, $base),
            'default_hosts_source' => $this->source($this->store->persistedHosts() !== [], $hosts),
            'step' => $this->activation->step()->value,
            'origin_restart_required' => $stale,
        ];
    }

    /**
     * @param list<string> $hosts
     * @return array<string,mixed>
     */
    public function save(?string $baseDomain, array $hosts): array
    {
        $normalizedBase = $this->normalizeBase($baseDomain);
        $proposedBase = $normalizedBase ?? $this->normalizeBase($this->store->fallbackBaseDomain());
        $reserved = $this->context->getConfig('tenancy.public_origin.reserved_labels', []);
        $proposed = ['base_domain' => $proposedBase, 'reserved_labels' => $reserved];
        $normalizedHosts = $this->normalizeHosts($hosts, $proposed);

        return $this->lock->withLock(function () use ($normalizedBase, $normalizedHosts): array {
            if ($this->activation->step() !== ResolutionActivationStep::INACTIVE) {
                throw new PublicOriginWriteConflict(
                    'Public origin cannot be changed while workspace resolution is activating or active.'
                );
            }
            $this->store->writeChanged($normalizedBase, $normalizedHosts);

            return $this->status();
        });
    }

    private function source(bool $fromFlag, mixed $effective): string
    {
        if ($fromFlag) {
            return 'flag';
        }

        return ($effective === null || $effective === '' || $effective === []) ? 'unset' : 'config';
    }

    private function normalizeBase(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }
        if (str_contains($input, ':')) {
            throw new PublicOriginValidationException(['base_domain' => 'A port or address form is not allowed.']);
        }
        try {
            return HostNormalizer::normalize($input);
        } catch (InvalidHostException $e) {
            throw new PublicOriginValidationException(['base_domain' => $e->getMessage()]);
        }
    }

    /**
     * @param list<string> $inputs
     * @param array<string,mixed> $proposedOrigin
     * @return list<string>
     */
    private function normalizeHosts(array $inputs, array $proposedOrigin): array
    {
        $normalized = [];
        foreach ($inputs as $input) {
            if (!is_string($input) || trim($input) === '') {
                continue;
            }
            if (str_contains($input, ':')) {
                throw new PublicOriginValidationException(
                    ['default_hosts' => "A port or address form is not allowed: {$input}"]
                );
            }
            try {
                $host = HostNormalizer::normalize($input);
                HostNormalizer::validateForRegistration($host, $proposedOrigin, true);
            } catch (InvalidHostException $e) {
                throw new PublicOriginValidationException(['default_hosts' => $e->getMessage()]);
            }
            $normalized[$host] = true;
        }
        $hosts = array_keys($normalized);
        if ($hosts === []) {
            throw new PublicOriginValidationException(['default_hosts' => 'At least one host is required.']);
        }

        sort($hosts);
        return $hosts;
    }
}
