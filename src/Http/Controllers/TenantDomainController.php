<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Cache\TenantHostCachePurger;

final class TenantDomainController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TenantHostCachePurger $cache,
        private readonly ?TenantDomainAdministration $domains = null,
    ) {
    }

    public function index(string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        return Response::success(['domains' => $this->domains->listDomains($this->context, $uuid)]);
    }

    public function create(Request $request, string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        $body = json_decode((string) $request->getContent(), true);
        $host = is_array($body) && is_string($body['host'] ?? null) ? trim($body['host']) : '';
        if ($host === '') {
            return Response::validation(['host' => 'A host is required.']);
        }
        try {
            $domain = $this->domains->addDomain($this->context, $uuid, $host);
            $this->cache->purgeForTenant($uuid);
            return Response::created($domain + ['txt_record' => '_thallo-verify.' . strtolower($host)]);
        } catch (\InvalidArgumentException | \DomainException $e) {
            return Response::validation(['host' => $e->getMessage()]);
        }
    }

    public function verify(string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        return $this->mutateDomain($uuid, fn (): string => $this->domains->verifyDomain($this->context, $uuid));
    }

    public function enable(string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        return $this->mutateDomain($uuid, function () use ($uuid): void {
            $this->domains->enableDomain($this->context, $uuid);
        });
    }

    public function disable(string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        return $this->mutateDomain($uuid, function () use ($uuid): void {
            $this->domains->disableDomain($this->context, $uuid);
        });
    }

    public function remove(string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        return $this->mutateDomain($uuid, function () use ($uuid): void {
            $this->domains->removeDomain($this->context, $uuid);
        });
    }

    /** @param callable():mixed $operation */
    private function mutate(callable $operation): Response
    {
        try {
            return Response::success(['result' => $operation()]);
        } catch (\DomainException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return Response::validation(['domain' => $e->getMessage()]);
        }
    }

    /** @param callable():mixed $operation */
    private function mutateDomain(string $domainUuid, callable $operation): Response
    {
        $domain = $this->domains->getDomain($this->context, $domainUuid);
        if ($domain === null) {
            return Response::notFound('Tenant domain was not found.');
        }
        $response = $this->mutate($operation);
        if ($response->getStatusCode() < 400) {
            $this->cache->purgeForTenant($domain['tenant_uuid']);
        }

        return $response;
    }

    private function unavailable(): Response
    {
        return Response::error(
            'Tenant domain administration is unavailable.',
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }
}
