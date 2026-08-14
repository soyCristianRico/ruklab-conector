<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ruklab\Connector\Http\AuthenticateConnector;
use Ruklab\Connector\Http\Controllers\ConnectorController;

/*
 * Every route behind the token, and none of them destructive. There is no
 * DELETE here and there will not be one: if something has to go, it goes by
 * hand from the site's own admin, where whoever does it is looking at what
 * they are removing.
 */
Route::prefix('ruklab/v1')
    ->middleware(['api', AuthenticateConnector::class])
    ->group(function (): void {
        Route::get('info', [ConnectorController::class, 'info']);

        Route::get('content/{type}', [ConnectorController::class, 'list']);
        Route::post('content/{type}', [ConnectorController::class, 'store']);
        Route::get('content/{type}/{id}', [ConnectorController::class, 'show']);
        Route::post('content/{type}/{id}', [ConnectorController::class, 'update']);
        Route::post('content/{type}/{id}/media', [ConnectorController::class, 'storeMedia']);

        Route::get('menus', [ConnectorController::class, 'menus']);
        Route::post('menu-items', [ConnectorController::class, 'storeMenuItem']);
        Route::post('menu-items/{id}', [ConnectorController::class, 'updateMenuItem']);
        Route::post('menus/reorder', [ConnectorController::class, 'reorderMenu']);

        Route::get('redirects', [ConnectorController::class, 'redirects']);
        Route::post('redirects', [ConnectorController::class, 'storeRedirect']);
        Route::post('redirects/{id}', [ConnectorController::class, 'updateRedirect']);

        Route::get('snapshots/{type}/{id}', [ConnectorController::class, 'snapshots']);
        Route::post('rollback', [ConnectorController::class, 'rollback']);
    });
