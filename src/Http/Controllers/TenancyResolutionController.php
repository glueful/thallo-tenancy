<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\Enablement\EnablementLockedException;
use Thallo\Tenancy\Resolution\FullResolutionActivation;

final class TenancyResolutionController
{
    public function __construct(private readonly FullResolutionActivation $activation)
    {
    }

    public function status(): Response
    {
        return Response::success(['resolution' => $this->activation->status()]);
    }

    public function activate(Request $request): Response
    {
        try {
            $body = json_decode((string) $request->getContent(), true);
            $body = is_array($body) ? $body : [];
            $resolution = ($body['retry'] ?? false) === true
                ? $this->activation->retry()
                : $this->activation->advance();

            if (($resolution['step'] ?? null) === 'failed') {
                return Response::error(
                    (string) ($resolution['failure'] ?? 'Resolution activation failed.'),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['resolution' => $resolution],
                );
            }

            return Response::success(['resolution' => $resolution]);
        } catch (EnablementLockedException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (EnablementException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function deactivate(): Response
    {
        try {
            return Response::success(['resolution' => $this->activation->deactivate()]);
        } catch (EnablementLockedException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (EnablementException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
