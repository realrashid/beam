<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Notification;
use RealRashid\Beam\BeamManager;
use RealRashid\Beam\BeamServiceProvider;
use RealRashid\Beam\Contracts\PushStore;
use RealRashid\Beam\Contracts\TimeoutDriver;
use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Notifications\IncomingCallNotification;
use RealRashid\Beam\Push\Stores\CacheStore;
use RealRashid\Beam\Testing\BeamFake;
use RealRashid\Beam\Timeout\BrowserDriver;
use RealRashid\Beam\Turn\CoturnDriver;

/**
 * The conventions a Laravel package is expected to follow. Each of these is
 * something a user reaches for by habit, so each one is worth a test rather
 * than a good intention.
 */
it('answers php artisan about with what this application is configured to do', function () {
    config()->set('beam.signaling.transport', 'whisper');
    config()->set('beam.ice.turn.default', 'coturn');
    config()->set('beam.call.timeout.driver', 'scheduler');

    $this->artisan('about')
        ->expectsOutputToContain('Beam')
        ->expectsOutputToContain('whisper')
        ->expectsOutputToContain('coturn')
        ->expectsOutputToContain('scheduler')
        ->assertSuccessful();
});

it('says the push store is not configured until VAPID keys exist, rather than naming a store that cannot send', function () {
    $this->artisan('about')->expectsOutputToContain('not configured')->assertSuccessful();

    config()->set('beam.push.vapid', ['public' => 'PUB', 'private' => 'PRIV', 'subject' => 'mailto:x@y.z']);
    config()->set('beam.push.store', 'database');

    $this->artisan('about')->expectsOutputToContain('database')->assertSuccessful();
});

it('binds the driver contracts, so an application can typehint one', function () {
    config()->set('beam.ice.turn', ['default' => 'coturn', 'drivers' => ['coturn' => ['urls' => ['turn:r:3478'], 'secret' => 's']]]);
    config()->set('beam.call.timeout.driver', 'browser');
    config()->set('beam.push.store', 'cache');

    expect(app(TurnDriver::class))->toBeInstanceOf(CoturnDriver::class)
        ->and(app(TimeoutDriver::class))->toBeInstanceOf(BrowserDriver::class)
        ->and(app(PushStore::class))->toBeInstanceOf(CacheStore::class);
});

it('does not construct a broadcaster until something broadcasts', function () {
    // Broadcast::channel() goes to the driver, so registering channels at boot
    // would build a Pusher or Reverb client on every request. Beam waits.
    expect(app()->resolved(BroadcastManager::class))->toBeFalse();

    app(BroadcastManager::class);   // what a broadcast, or an Echo auth request, does

    expect(app()->resolved(BroadcastManager::class))->toBeTrue();
});

it('registers its channels once the broadcast manager is resolved', function () {
    $channels = collect(app(BroadcastManager::class)->connection()->getChannels())->keys()->all();

    expect($channels)->toContain('beam.user.{id}', 'beam.call.{id}', 'beam.room.{name}');
});

it('rings in the language the application sets', function () {
    Notification::fake();
    config()->set('beam.push.vapid', ['public' => 'PUB', 'private' => 'PRIV', 'subject' => 'mailto:x@y.z']);

    $agent = makeUser('Ada');
    $customer = makeUser('Grace');
    $call = Beam::call($customer)->from($agent)->video()->ring();

    $english = (new IncomingCallNotification($call, 'token'))->toWebPush($customer);

    expect($english['title'])->toBe('Ada is calling')
        ->and($english['body'])->toBe('Video call')
        ->and($english['actions'][0]['title'])->toBe('Answer');

    // Every one of those comes from lang/en/beam.php, so an application that
    // publishes it — or translates it — changes what the phone shows.
    app('translator')->addLines([
        'beam.notification.calling' => ':name aap ko call kar rahe hain',
        'beam.notification.video' => 'Video call',
        'beam.notification.answer' => 'Jawab dein',
        'beam.notification.decline' => 'Mana karein',
    ], 'ur', 'beam');
    app()->setLocale('ur');

    $urdu = (new IncomingCallNotification($call, 'token'))->toWebPush($customer);

    expect($urdu['title'])->toBe('Ada aap ko call kar rahe hain')
        ->and($urdu['actions'][0]['title'])->toBe('Jawab dein')
        ->and($urdu['actions'][1]['title'])->toBe('Mana karein');

    app()->setLocale('en');
});

it('offers every publish tag a user will look for', function () {
    $groups = (fn () => BeamServiceProvider::$publishGroups)->call(new BeamServiceProvider(app()));

    expect(array_keys($groups))->toContain('beam-config', 'beam-migrations', 'beam-lang', 'beam-views');
});

it('exposes the manager through the beam() helper and the facade alike', function () {
    expect(beam())->toBeInstanceOf(BeamManager::class)
        ->and(app('beam'))->toBe(beam())
        ->and(Beam::getFacadeRoot())->toBe(beam());
});

it('annotates every public method of the manager and the fake on the facade', function () {
    // The facade is how every line of PHP against Beam starts, so an IDE and a
    // static analyser have to know what is on it. This test is what keeps the
    // docblock from drifting the first time a method is added.
    $block = (string) (new ReflectionClass(Beam::class))->getDocComment();

    foreach ([BeamManager::class, BeamFake::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->class !== $class) {
                continue;
            }

            // recordRing/recordRoom are how the fake's own builders report back,
            // not something anyone calls through the facade; __call is Macroable's.
            if (str_starts_with($method->getName(), 'record') || str_starts_with($method->getName(), '__')) {
                continue;
            }

            // toContain() takes many needles, so the message goes on toBeTrue().
            expect(str_contains($block, ' '.$method->getName().'('))
                ->toBeTrue("Beam's facade does not annotate {$method->getName()}(); add it to the @method block.");
        }
    }
});
