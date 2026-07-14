<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleConflictException;

final class TenantMembershipController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?TenantAdministration $tenants = null,
        private readonly ?CurrentTenantResolver $resolver = null,
    ) {
    }

    public function index(string $uuid): Response
    {
        if (!$this->targetMatches($uuid)) {
            return $this->forbidden();
        }
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        $members = $this->tenants->listMembers($this->context, $uuid);
        return Response::success(['members' => $this->withUserIdentities($members)]);
    }

    public function add(Request $request, string $uuid): Response
    {
        if (!$this->targetMatches($uuid)) {
            return $this->forbidden();
        }
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        $body = $this->body($request);
        $email = trim((string) ($body['email'] ?? ''));
        if ($email === '') {
            return Response::validation(['email' => 'An email address is required.']);
        }
        $userUuid = $this->users()?->findByLogin($email)?->uuid();
        if ($userUuid === null || $userUuid === '') {
            return Response::validation(['email' => 'No user found with that email address.']);
        }
        return $this->mutate(function () use ($userUuid, $body, $uuid): void {
            $this->tenants->addMember(
                $this->context,
                $uuid,
                $userUuid,
                (string) ($body['role'] ?? '')
            );
        });
    }

    public function remove(string $uuid, string $userUuid): Response
    {
        if (!$this->targetMatches($uuid)) {
            return $this->forbidden();
        }
        if ($this->tenants === null) {
            return $this->unavailable();
        }
        return $this->mutate(function () use ($uuid, $userUuid): void {
            $this->tenants->removeMember($this->context, $uuid, $userUuid);
        });
    }

    public function setRole(Request $request, string $uuid, string $userUuid): Response
    {
        if (!$this->targetMatches($uuid)) {
            return $this->forbidden();
        }
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

    /**
     * Attach a friendly full name / email to each membership row so the UI can show a human label
     * instead of the raw user UUID (which stays in the payload only as the mutation key). Best-effort:
     * an unresolved user simply keeps null name/email.
     *
     * @param list<array<string,mixed>> $members
     * @return list<array<string,mixed>>
     */
    private function withUserIdentities(array $members): array
    {
        $provider = $this->users();
        $profiles = $this->profiles();
        foreach ($members as $i => $member) {
            $userUuid = (string) ($member['user_uuid'] ?? '');
            $identity = $userUuid !== '' ? $provider?->findByUuid($userUuid) : null;
            $profile = $userUuid !== '' ? $profiles?->getProfile($userUuid) : null;
            $name = trim(sprintf(
                '%s %s',
                (string) ($profile['first_name'] ?? ''),
                (string) ($profile['last_name'] ?? '')
            ));
            $members[$i]['name'] = $name !== '' ? $name : null;
            $members[$i]['email'] = $identity?->email();
            $members[$i]['username'] = $identity?->username();
        }
        return $members;
    }

    private function users(): ?UserProviderInterface
    {
        try {
            return app($this->context, UserProviderInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function profiles(): ?UserRepository
    {
        try {
            return app($this->context, UserRepository::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param callable():void $operation */
    private function mutate(callable $operation): Response
    {
        try {
            $operation();
            return Response::success();
        } catch (MembershipRoleConflictException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_CONFLICT, [
                'code' => 'MEMBERSHIP_ROLE_CONFLICT',
            ]);
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

    private function targetMatches(string $tenantUuid): bool
    {
        return $this->resolver !== null
            && hash_equals($tenantUuid, $this->resolver->tenantUuid($this->context));
    }

    private function forbidden(): Response
    {
        return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
    }
}
