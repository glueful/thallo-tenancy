<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Http\Response;
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
