<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Tenancy\Http\Controllers\TenancyEnablementController;
use Thallo\Tenancy\Http\Controllers\TenancyResolutionController;
use Thallo\Tenancy\Http\Controllers\TenantDirectoryController;
use Thallo\Tenancy\Http\Controllers\TenantManagementController;
use Thallo\Tenancy\Http\Controllers\TenantDomainController;
use Thallo\Tenancy\Http\Controllers\TenantMembershipController;

/** @var Router $router */
$router->group(['prefix' => '/v1/admin', 'middleware' => ['auth']], function (Router $router): void {
    $router->get('/tenancy/status', [TenancyEnablementController::class, 'status'])
        ->middleware('tenant_system')
        ->middleware('content_permission:system.access');

    foreach (['begin', 'confirm', 'retry', 'cancel', 'finalize', 'disable'] as $action) {
        $router->post('/tenancy/' . $action, [TenancyEnablementController::class, $action])
            ->middleware('tenant_system')
            ->middleware('content_permission:system.access');
    }

    $router->get('/tenancy/resolution', [TenancyResolutionController::class, 'status'])
        ->middleware('tenant_system')
        ->middleware('content_permission:system.access');
    $router->post('/tenancy/resolution/deactivate', [TenancyResolutionController::class, 'deactivate'])
        ->middleware('tenant_system')
        ->middleware('content_permission:system.access');
});

$router->group(
    ['prefix' => '/v1/admin/tenancy', 'middleware' => ['auth', 'tenant_system']],
    function (Router $router): void {
        $router->get('/my-tenants', [TenantDirectoryController::class, 'mine']);

        $router->group(['middleware' => ['content_permission:system.access']], function (Router $router): void {
            $router->get('/tenants', [TenantManagementController::class, 'index']);
            $router->post('/tenants', [TenantManagementController::class, 'create']);
            $router->post('/tenants/{uuid}/suspend', [TenantManagementController::class, 'suspend']);
            $router->post('/tenants/{uuid}/reactivate', [TenantManagementController::class, 'reactivate']);

            $router->get('/tenants/{uuid}/domains', [TenantDomainController::class, 'index']);
            $router->post('/tenants/{uuid}/domains', [TenantDomainController::class, 'create']);
            $router->post('/domains/{uuid}/verify', [TenantDomainController::class, 'verify']);
            $router->post('/domains/{uuid}/enable', [TenantDomainController::class, 'enable']);
            $router->post('/domains/{uuid}/disable', [TenantDomainController::class, 'disable']);
            $router->delete('/domains/{uuid}', [TenantDomainController::class, 'remove']);

            $router->get('/tenants/{uuid}/members', [TenantMembershipController::class, 'index']);
            $router->post('/tenants/{uuid}/members', [TenantMembershipController::class, 'add']);
            $router->delete(
                '/tenants/{uuid}/members/{userUuid}',
                [TenantMembershipController::class, 'remove']
            );
            $router->patch(
                '/tenants/{uuid}/members/{userUuid}',
                [TenantMembershipController::class, 'setRole']
            );
        });
    }
);
