<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\Enablement\EnablementLockedException;
use Thallo\Tenancy\Enablement\RequestResolutionNotReadyException;
use Thallo\Tenancy\Enablement\StaleStateException;
use Thallo\Tenancy\Enablement\TenancyEnablement;

final class TenancyEnablementController
{
    public function __construct(private readonly TenancyEnablement $enablement)
    {
    }

    public function status(): Response
    {
        return Response::success(['tenancy' => $this->enablement->status()->toArray()]);
    }

    public function begin(): Response
    {
        return $this->guarded(fn (): array => $this->enablement->begin()->toArray());
    }

    public function confirm(Request $request): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $slug = isset($body['slug']) && is_string($body['slug']) ? trim($body['slug']) : '';
        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        $owner = $this->ownerUuid($request);

        if ($slug === '' || preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $slug) !== 1) {
            return Response::validation(['slug' => 'Use lowercase letters, numbers, and hyphens.']);
        }
        if ($name === '') {
            return Response::validation(['name' => 'A tenant name is required.']);
        }
        if ($owner === null) {
            return Response::error('Could not resolve the current user.', Response::HTTP_UNAUTHORIZED);
        }

        return $this->guarded(
            fn (): array => $this->enablement->confirm($slug, $name, $owner)->toArray(),
        );
    }

    public function retry(): Response
    {
        return $this->guarded(fn (): array => $this->enablement->retry()->toArray());
    }

    public function cancel(): Response
    {
        return $this->guarded(fn (): array => $this->enablement->cancel()->toArray());
    }

    public function finalize(): Response
    {
        return $this->guarded(fn (): array => $this->enablement->finalize()->toArray());
    }

    /** @param callable(): array<string, bool|int|string|null> $operation */
    private function guarded(callable $operation): Response
    {
        try {
            return Response::success(['tenancy' => $operation()]);
        } catch (EnablementLockedException | StaleStateException | RequestResolutionNotReadyException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (EnablementException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function ownerUuid(Request $request): ?string
    {
        $identity = $request->attributes->get('auth.user');
        if ($identity instanceof \Glueful\Auth\UserIdentity) {
            $uuid = trim($identity->uuid());
            return $uuid === '' ? null : $uuid;
        }

        $user = $request->attributes->get('user');
        if (is_array($user) && isset($user['uuid']) && is_string($user['uuid'])) {
            $uuid = trim($user['uuid']);
            return $uuid === '' ? null : $uuid;
        }

        return null;
    }
}
