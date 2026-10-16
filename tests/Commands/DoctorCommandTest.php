<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Commands\DoctorCommand;
use RealRashid\Beam\Support\Doctor\Check;
use RealRashid\Beam\Support\Doctor\Result;
use RealRashid\Beam\Support\Doctor\StunCheck;

/**
 * The command on a fresh install: every check speaks once, in order, each
 * line saying what to do; a single fail turns the exit code red.
 */
// The test box may have no DNS; STUN resolution is proven in ChecksTest with an injected resolver.
beforeEach(fn () => $this->app->bind(StunCheck::class, fn () => new StunCheck(fn () => true)));

it('prints one line per check on a fresh install, each with a next step, and exits 1 because Broadcasting is off', function () {
    config()->set('broadcasting.default', 'log');
    config()->set('beam.push.vapid', ['public' => null, 'private' => null, 'subject' => null]);
    Http::fake();

    $this->artisan('beam:doctor')
        ->expectsOutputToContain('✗ Broadcasting:')
        ->expectsOutputToContain('→ Run php artisan install:broadcasting')
        ->expectsOutputToContain('! TURN:')
        ->expectsOutputToContain('! Authorization:')
        ->expectsOutputToContain('! Web Push keys:')
        ->expectsOutputToContain('✓ Service worker:')
        ->expectsOutputToContain('✓ Client version:')
        ->expectsOutputToContain('✓ Timeouts:')
        ->expectsOutputToContain('to fix before calls will work')
        ->assertFailed();
});

it('exits 0 with every check green or yellow', function () {
    config()->set('broadcasting.default', 'pusher');
    config()->set('cache.default', 'file');   // the suite runs on `array`, which the cache check fails on purpose
    config()->set('beam.push.vapid', ['public' => null, 'private' => null, 'subject' => null]);
    config()->set('beam.call.timeout.driver', 'browser');
    config()->set('app.url', 'http://localhost');

    $this->artisan('beam:doctor')
        ->expectsOutputToContain('✓ Broadcasting: Broadcasting via pusher.')
        ->expectsOutputToContain('✓ STUN:')
        ->expectsOutputToContain('! Cache:')
        ->expectsOutputToContain('✓ HTTPS:')
        ->expectsOutputToContain('Nothing is blocking a call.')
        ->assertSuccessful();
});

it('lets an application add its own check, and a check that throws is a fail, not a crash', function () {
    config()->set('broadcasting.default', 'pusher');
    config()->set('beam.call.timeout.driver', 'browser');

    $custom = new class implements Check
    {
        public function name(): string
        {
            return 'Custom';
        }

        public function run(): Result
        {
            throw new RuntimeException('boom');
        }
    };
    DoctorCommand::add($custom::class);

    try {
        $this->artisan('beam:doctor')
            ->expectsOutputToContain('✗ Custom: Custom could not be checked: boom')
            ->assertFailed();
    } finally {
        DoctorCommand::forget($custom::class);   // the list is static; the next test starts from the shipped twelve
    }
});

it('can be run from a web request through Artisan::call, for an admin page', function () {
    config()->set('broadcasting.default', 'pusher');
    config()->set('beam.call.timeout.driver', 'browser');
    Route::get('/doctor', function () {
        Artisan::call('beam:doctor');

        return Artisan::output();
    });

    $this->get('/doctor')->assertOk()->assertSee('Broadcasting via pusher');
});
