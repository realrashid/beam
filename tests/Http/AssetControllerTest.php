<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use RealRashid\Beam\BeamServiceProvider;
use RealRashid\Beam\Http\Controllers\AssetController;

it('serves the compiled client publicly, immutable, as JavaScript', function () {
    $response = $this->get('/beam/beam.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    expect($response->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable');
    expect(file_get_contents($response->getFile()->getPathname()))->toContain('PROTOCOL_VERSION');   // a BinaryFileResponse has no getContent()
});

it('serves the service worker with the scope widened and never cached', function () {
    $response = $this->get('/beam/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('no-cache');
});

it('@beamAssets prints the user, the prefix, the CSRF token and the versioned script — and nothing secret', function () {
    config()->set('beam.push.vapid', ['public' => 'PUB', 'private' => 'PRIVATE-KEY']);
    config()->set('beam.ice.turn', ['default' => 'coturn', 'drivers' => ['coturn' => ['urls' => ['turn:r'], 'secret' => 'TURN-SECRET']]]);
    $user = makeUser();
    $this->actingAs($user);

    $html = Blade::render('@beamAssets');

    expect($html)->toContain('<meta name="beam-user" content="'.$user->id.'">')
        ->toContain('<meta name="beam-prefix" content="http://localhost/beam">')
        ->toContain('name="csrf-token"')
        ->toContain('<meta name="beam-transfer-max" content="'.(int) config('beam.transfer.max_bytes').'">')
        ->toMatch('#src="http://localhost/beam/beam\.js\?v='.AssetController::version().'"#')
        ->not->toContain('PRIVATE-KEY')->not->toContain('TURN-SECRET')->not->toContain('turn:');
});

it('the compiled client and worker carry no literal secret', function () {
    foreach (['beam.js', 'beam-sw.js'] as $file) {
        expect(file_get_contents(AssetController::dist($file)))->not->toMatch('/(vapid|turn)[_-]?(private|secret)\s*[:=]\s*["\'][^"\']{8,}/i');
    }
});

it('keeps the client and the worker public even when the application names its guard', function (string $guard) {
    // What an application's HTTP kernel registers, which Testbench does not.
    Route::aliasMiddleware('auth', Authenticate::class);
    Route::aliasMiddleware('throttle', ThrottleRequests::class);
    Route::middlewareGroup('web', [AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, SubstituteBindings::class]);

    // Beam used to make these two public by subtracting 'auth' from the group.
    // Laravel compares resolved middleware names, and `auth:sanctum` resolves to
    // the string "Authenticate:sanctum" — not the class, so never excluded. The
    // client was then served as a login redirect on any page a guest could see.
    config()->set('beam.routes.middleware', ['web', $guard]);
    app()->register(BeamServiceProvider::class, force: true);

    foreach (['beam.assets', 'beam.sw'] as $name) {
        $route = collect(Route::getRoutes()->getRoutes())->last(fn ($r) => $r->getName() === $name);

        expect(app('router')->gatherRouteMiddleware($route))->not->toContain(Authenticate::class, Authenticate::class.':web', Authenticate::class.':sanctum', 'auth', $guard);
    }

    $this->get('/beam/beam.js')->assertOk();
    $this->get('/beam/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/');
})->with(['auth', 'auth:web', 'auth:sanctum']);

it('puts an application\'s own middleware on the assets when it asks, and the throttle on nothing public', function () {
    config()->set('beam.routes.asset_middleware', ['web']);
    app()->register(BeamServiceProvider::class, force: true);

    $assets = collect(Route::getRoutes()->getRoutes())->last(fn ($r) => $r->getName() === 'beam.assets');

    expect($assets->gatherMiddleware())->toContain('web')->not->toContain('throttle:240,1');
});
