<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Http\Controllers\CallController;
use RealRashid\Beam\Http\Controllers\PushController;
use RealRashid\Beam\Http\Controllers\SignalController;
use RealRashid\Beam\Http\Middleware\ValidateBeamToken;

/*
|--------------------------------------------------------------------------
| Beam routes
|--------------------------------------------------------------------------
|
| Registered by the service provider under config('beam.routes.prefix') and
| config('beam.routes.middleware'), with the configured throttle. Every route
| here needs an authenticated user; the public client and worker live in
| routes/assets.php, which has its own middleware.
|
*/

Route::post('signal', SignalController::class)
    ->middleware(ValidateBeamToken::class)
    ->name('beam.signal');

Route::post('calls/{id}/heartbeat', [CallController::class, 'heartbeat'])->name('beam.calls.heartbeat');
Route::post('calls/{id}/decline', [CallController::class, 'decline'])->name('beam.calls.decline');
Route::post('calls/{id}/end', [CallController::class, 'end'])->name('beam.calls.end');
Route::post('calls/{id}/timeout', [CallController::class, 'timeout'])->name('beam.calls.timeout');
Route::post('transfers', [CallController::class, 'transfer'])->name('beam.transfers');

Route::get('push/public-key', [PushController::class, 'publicKey'])->name('beam.push.key');
Route::post('push/subscribe', [PushController::class, 'subscribe'])->name('beam.push.subscribe');
Route::delete('push/subscribe', [PushController::class, 'unsubscribe'])->name('beam.push.unsubscribe');
Route::post('presence', [PushController::class, 'presence'])->name('beam.presence');
