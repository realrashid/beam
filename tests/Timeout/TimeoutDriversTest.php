<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RealRashid\Beam\Broadcasts\CallControl;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Events\CallDeclined;
use RealRashid\Beam\Events\CallEnded;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Jobs\ExpireCall;
use RealRashid\Beam\Jobs\SweepCalls;
use RealRashid\Beam\Support\CallRegistry;
use RealRashid\Beam\Support\CallSweeper;
use RealRashid\Beam\Timeout\BrowserDriver;

beforeEach(fn () => Event::fake([IncomingCall::class, CallControl::class, CallDeclined::class, CallEnded::class]));

function ring(int $timeout = 30)
{
    return Beam::call(makeUser('Customer'))->from(makeUser('Agent'))->timeout($timeout)->ring();
}

describe('queue driver', function () {
    it('dispatches ExpireCall delayed by the timeout, and the token says so', function () {
        Queue::fake();

        $call = ring(45);

        Queue::assertPushed(ExpireCall::class, fn (ExpireCall $job) => $job->callId === $call->id && (int) $job->delay === 45);
        expect(unpackToken($call->token)['claims']['meta']['timeoutDriver'])->toBe('queue');
    });

    it('ExpireCall declines a still-ringing call and leaves an answered one alone', function () {
        $ringing = ring();
        $answered = ring();
        app(CallRegistry::class)->answered($answered->id);

        (new ExpireCall($ringing->id))->handle(app(CallSweeper::class));
        (new ExpireCall($answered->id))->handle(app(CallSweeper::class));
        (new ExpireCall('call_gone'))->handle(app(CallSweeper::class));

        Event::assertDispatchedTimes(CallDeclined::class, 1);
        Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->call->id === $ringing->id && $e->reason === 'timeout');
    });

    it('SweepCalls ends a call whose browsers went silent, and re-schedules itself while one is alive', function () {
        Queue::fake();
        $call = ring();
        $registry = app(CallRegistry::class);
        $registry->answered($call->id, at: 1000);
        $registry->touch($call->id, $call->channel(), $call->caller->id, 'connected', 30, now: 1000);

        Carbon::setTestNow(Carbon::createFromTimestamp(1050));
        $registry->touch($call->id, $call->channel(), $call->caller->id, 'connected', 30, now: 1050);
        (new SweepCalls($call->id))->handle(app(CallSweeper::class), $registry);
        Event::assertNotDispatched(CallEnded::class);
        Queue::assertPushed(SweepCalls::class, 1);   // re-armed

        Carbon::setTestNow(Carbon::createFromTimestamp(1200));   // > 3 × 30 s of silence
        (new SweepCalls($call->id))->handle(app(CallSweeper::class), $registry);

        // The call ended when it was last heard from (1050), not when the
        // sweeper noticed (1200): the ninety seconds of silence it takes to
        // establish staleness are not talking time.
        Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->reason === 'connection-lost' && $e->by === null && $e->duration === 50);
        Carbon::setTestNow();
    });

    it('falls back to the browser driver on the sync queue and says why', function () {
        config()->set('queue.default', 'sync');
        Log::spy();
        Queue::fake();

        expect(beam()->timeouts())->toBeInstanceOf(BrowserDriver::class);
        $call = ring();

        Queue::assertNothingPushed();
        expect(unpackToken($call->token)['claims']['meta']['timeoutDriver'])->toBe('browser');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'beam:doctor'))->atLeast()->once();
    });
});

describe('browser driver', function () {
    it('dispatches nothing; the caller\'s page reports the timeout', function () {
        config()->set('beam.call.timeout.driver', 'browser');
        Queue::fake();

        $call = ring();

        Queue::assertNothingPushed();
        expect(unpackToken($call->token)['claims']['meta']['timeoutDriver'])->toBe('browser');
    });
});

describe('scheduler driver', function () {
    it('beam:sweep declines overdue rings and ends silent calls, and leaves the rest', function () {
        config()->set('beam.call.timeout.driver', 'scheduler');
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));
        $overdue = ring(30);
        $fresh = ring(30);
        $silent = ring(30);
        $alive = ring(30);
        $registry = app(CallRegistry::class);
        $registry->answered($silent->id, at: 1000);
        $registry->answered($alive->id, at: 1000);

        Carbon::setTestNow(Carbon::createFromTimestamp(1100));
        $registry->touch($fresh->id, $fresh->channel(), $fresh->caller->id, 'ringing', 30);   // refreshes nothing about createdAt; still overdue by time
        $registry->put($fresh->id, [...$registry->get($fresh->id), 'createdAt' => 1090], 90); // re-rung 10 s ago
        $registry->touch($alive->id, $alive->channel(), $alive->caller->id, 'connected', 30);

        $this->artisan('beam:sweep')->assertSuccessful();

        Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->call->id === $overdue->id);
        Event::assertNotDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->call->id === $fresh->id);
        Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->call->id === $silent->id && $e->reason === 'connection-lost');
        Event::assertNotDispatched(CallEnded::class, fn (CallEnded $e) => $e->call->id === $alive->id);
        Carbon::setTestNow();
    });
});

it('keeps a silent call\'s snapshot long enough for a sweeper to end it', function () {
    // The trap: a snapshot whose TTL equals the staleness window expires before
    // anything can notice the silence, and cache expiry fires nothing.
    Carbon::setTestNow(Carbon::createFromTimestamp(1000));
    $call = ring();
    $registry = app(CallRegistry::class);
    $registry->answered($call->id, at: 1000);
    $registry->touch($call->id, $call->channel(), $call->caller->id, 'connected', 30);

    Carbon::setTestNow(Carbon::createFromTimestamp(1000 + 20 * 60));   // twenty minutes of silence

    expect($registry->get($call->id))->not->toBeNull()
        ->and(app(CallSweeper::class)->endIfStale($call->id))->toBeTrue();
    Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->reason === 'connection-lost');
    Carbon::setTestNow();
});

it('refuses an unknown timeout driver, naming the valid ones', function () {
    config()->set('beam.call.timeout.driver', 'hourglass');

    expect(fn () => beam()->timeouts())
        ->toThrow(fn (BeamException $e) => expect($e->getMessage())->toContain('queue, browser, scheduler'));
});
