<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Tenancy\Http\Controllers\TenancyEnablementController;

/** @var Router $router */
$router->group(['prefix' => '/v1/admin', 'middleware' => ['auth']], function (Router $router): void {
    $router->get('/tenancy/status', [TenancyEnablementController::class, 'status'])
        ->middleware('tenant_system')
        ->middleware('content_permission:system.access');

    foreach (['begin', 'confirm', 'retry', 'cancel', 'finalize'] as $action) {
        $router->post('/tenancy/' . $action, [TenancyEnablementController::class, $action])
            ->middleware('tenant_system')
            ->middleware('content_permission:system.access');
    }
});
