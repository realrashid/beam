<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Http\Controllers\AssetController;
use RealRashid\Beam\Http\Controllers\CallController;
use RealRashid\Beam\Http\Middleware\ValidateBeamToken;

/*
|--------------------------------------------------------------------------
| The routes a browser reaches without a session
|--------------------------------------------------------------------------
|
| Registered by the service provider under config('beam.routes.prefix') and
| config('beam.routes.asset_middleware') — empty by default.
|
| The client and the worker are public on purpose: a page needs the client
| before anyone is signed in (a login screen may ring), and the browser
| fetches the worker with no session at all. They are their own group rather
| than the authenticated group minus `auth`, because subtracting middleware
| cannot match a middleware with a parameter: an application whose routes are
| behind `auth:sanctum` or `auth:web` would keep the guard on both of these
| and serve a redirect where the browser expects JavaScript.
|
| Decline is here for a different reason. It is the one endpoint a service
| worker calls, from a locked phone, when someone taps Decline on the
| notification — and a worker has no CSRF token to send and may have no live
| session to rely on. So it is proved by the Beam token the push carried:
| encrypted with the application key, issued to that callee, for that one
| call, and expiring. `web` is not in this group, so no CSRF check applies.
|
*/

Route::get('beam.js', [AssetController::class, 'client'])->name('beam.assets');
Route::get('sw.js', [AssetController::class, 'serviceWorker'])->name('beam.sw');

Route::post('calls/{id}/decline-by-token', [CallController::class, 'declineFromNotification'])
    ->middleware(ValidateBeamToken::class)
    ->name('beam.calls.decline.token');
