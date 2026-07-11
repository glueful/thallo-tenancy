<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Cache\TenantHostCachePurger;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Contracts\Tenancy\HostCooldownException;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;

final class TenantDomainController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TenantHostCachePurger $cache,
        private readonly ?TenantDomainAdministration $domains = null,
        private readonly ?CurrentTenantResolver $resolver = null,
        private readonly ?TenancyLifecycleAudit $audit = null,
    ) {
    }

    public function index(string $uuid): Response
    {
        if (!$this->targetMatches($uuid)) {
            return $this->forbidden();
        }
        if ($this->domains === null) {
            return $this->unavailable();
        }
        return Response::success(['domains' => $this->domains->listDomains($this->context, $uuid)]);
    }

    public function create(Request $request, string $uuid): Response
    {
        if (!$this->targetMatches($uuid)) {
            return $this->forbidden();
        }
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
        } catch (HostCooldownException $e) {
            return Response::error('Host is in cooldown.', Response::HTTP_CONFLICT, [
                'code' => 'HOST_COOLDOWN',
                'available_after' => $e->availableAfter(),
            ]);
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

    public function reverify(Request $request, string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        $domain = $this->domains->getDomain($this->context, $uuid);
        if ($domain === null || !$this->targetMatches((string) $domain['tenant_uuid'])) {
            return Response::notFound('Tenant domain was not found.');
        }
        $this->audit?->record(
            'domain.reverification_requested',
            $this->actor($request),
            (string) $domain['tenant_uuid'],
            ['domain_uuid' => $uuid, 'host' => $domain['host']]
        );

        return $this->mutateDomain($uuid, function () use ($uuid): array {
            $result = $this->domains->reverifyDomain($this->context, $uuid);
            return [
                'outcome' => $result->outcome,
                'verification_status' => $result->verificationStatus,
                'transition' => $result->transition,
                'consecutive_failures' => $result->consecutiveFailures,
                'checked_at' => $result->checkedAt,
            ];
        });
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

    public function remove(Request $request, string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        $domain = $this->domains->getDomain($this->context, $uuid);
        return $this->mutateDomain($uuid, function () use ($request, $uuid, $domain): void {
            $this->domains->releaseDomain($this->context, $uuid);
            if (is_array($domain)) {
                $this->audit?->record(
                    'host.released',
                    $this->actor($request),
                    is_string($domain['tenant_uuid'] ?? null) ? $domain['tenant_uuid'] : null,
                    ['host' => $domain['host'] ?? null, 'source' => 'domain_removal']
                );
            }
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
        if (!$this->targetMatches((string) $domain['tenant_uuid'])) {
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

    private function targetMatches(string $tenantUuid): bool
    {
        return $this->resolver !== null
            && hash_equals($tenantUuid, $this->resolver->tenantUuid($this->context));
    }

    private function forbidden(): Response
    {
        return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
    }

    private function actor(Request $request): ?string
    {
        $identity = $request->attributes->get('auth.user');
        if ($identity instanceof UserIdentity) {
            return $identity->uuid();
        }
        $user = $request->attributes->get('user');
        return is_array($user) && is_string($user['uuid'] ?? null) ? $user['uuid'] : null;
    }
}
