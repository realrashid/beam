<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Log;
use RealRashid\Beam\BeamServiceProvider;

/**
 * Found by the rig: a fresh app with BROADCAST_CONNECTION=reverb and no Reverb
 * installed could not even run artisan, and the trace pointed at Beam. A
 * package registers channels only if it can; it never takes the app down.
 *
 * Channels are registered when something first resolves the broadcast manager —
 * a broadcast, or an Echo auth request — rather than at boot, so that a Pusher
 * client is not constructed on every request. Both moments are covered here.
 */
it('boots the application even when the broadcaster cannot be created', function () {
    config()->set('broadcasting.default', 'nope');
    config()->set('broadcasting.connections.nope', ['driver' => 'does-not-exist']);
    Log::spy();

    $provider = new BeamServiceProvider($this->app);

    // Boot touches no broadcaster at all: nothing is created until something asks.
    expect(fn () => $provider->boot())->not->toThrow(Throwable::class);
    expect(app()->resolved(BroadcastManager::class))->toBeFalse();
    Log::shouldNotHaveReceived('warning');

    // And the moment that does ask must not take the application down either.
    expect(fn () => app(BroadcastManager::class))->not->toThrow(Throwable::class);

    // Once per provider that booted — this test booted a second one over
    // Testbench's, and both are listening for the manager.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'beam:doctor'))
        ->atLeast()->once();
});
