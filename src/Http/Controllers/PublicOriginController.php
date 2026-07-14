<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Enablement\EnablementLockedException;
use Thallo\Tenancy\PublicOrigin\PublicOriginService;
use Thallo\Tenancy\PublicOrigin\PublicOriginValidationException;
use Thallo\Tenancy\PublicOrigin\PublicOriginWriteConflict;

final class PublicOriginController
{
    public function __construct(private readonly PublicOriginService $service)
    {
    }

    public function show(): Response
    {
        return Response::success(['public_origin' => $this->service->status()]);
    }

    public function update(Request $request): Response
    {
        $decoded = json_decode((string) $request->getContent(), true);
        if (!is_array($decoded)) {
            return Response::validation(['body' => 'A JSON object is required.']);
        }
        /** @var array<string,mixed> $body */
        $body = $decoded;
        if (
            !array_key_exists('base_domain', $body)
            || (!is_string($body['base_domain']) && $body['base_domain'] !== null)
        ) {
            return Response::validation(['base_domain' => 'Must be a hostname or null.']);
        }
        if (!array_key_exists('default_hosts', $body) || !is_array($body['default_hosts'])) {
            return Response::validation(['default_hosts' => 'Must be a list of hostnames.']);
        }
        foreach ($body['default_hosts'] as $host) {
            if (!is_string($host)) {
                return Response::validation(['default_hosts' => 'Every host must be a string.']);
            }
        }
        $base = $body['base_domain'];
        /** @var list<string> $hosts */
        $hosts = array_values($body['default_hosts']);

        try {
            $status = $this->service->save($base, $hosts);
        } catch (PublicOriginValidationException $e) {
            return Response::validation($e->errors);
        } catch (PublicOriginWriteConflict $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (EnablementLockedException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
        }

        return Response::success(['public_origin' => $status]);
    }
}
