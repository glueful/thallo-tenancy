<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class TenantMembershipController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?TenantAdministration $tenants = null,
    ) {
    }

    public function index(string $uuid): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        return Response::success(['members' => $this->tenants->listMembers($this->context, $uuid)]);
    }

    public function add(Request $request, string $uuid): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        $body = $this->body($request);
        return $this->mutate(function () use ($body, $uuid): void {
            $this->tenants->addMember(
                $this->context,
                $uuid,
                (string) ($body['user_uuid'] ?? ''),
                (string) ($body['role'] ?? '')
            );
        });
    }

    public function remove(string $uuid, string $userUuid): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        return $this->mutate(function () use ($uuid, $userUuid): void {
            $this->tenants->removeMember($this->context, $uuid, $userUuid);
        });
    }

    public function setRole(Request $request, string $uuid, string $userUuid): Response
    {
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        $body = $this->body($request);
        return $this->mutate(function () use ($body, $uuid, $userUuid): void {
            $this->tenants->setMemberRole(
                $this->context,
                $uuid,
                $userUuid,
                (string) ($body['role'] ?? '')
            );
        });
    }

    /** @param callable():void $operation */
    private function mutate(callable $operation): Response
    {
        try {
            $operation();
            return Response::success();
        } catch (\InvalidArgumentException | \RuntimeException | \DomainException $e) {
            return Response::validation(['membership' => $e->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        return is_array($body) ? $body : [];
    }

    private function unavailable(): Response
    {
        return Response::error(
            'Tenant membership administration is unavailable.',
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }
}
