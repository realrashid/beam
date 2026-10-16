<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RealRashid\Beam\Broadcasts\CallControl;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Events\CallDeclined;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Jobs\ExpireCall;
use RealRashid\Beam\Support\CallRegistry;
use RealRashid\Beam\Support\CallSweeper;

/**
 * The unanswered ring, and the ring answered a second before it would have
 * expired: `answeredAt` is only written when a browser reports `connected`,
 * which is seconds after the tap and longer through a relay, so the job has to
 * tell "nobody picked up" apart from "picking up right now".
 */
beforeEach(function () {
    Event::fake([IncomingCall::class, CallControl::class, CallDeclined::class]);
    $this->agent = makeUser('Agent');
    $this->customer = makeUser('Customer');
    $this->call = Beam::call($this->customer)->from($this->agent)->timeout(30)->ring();
});

it('declines a ring nobody answered', function () {
    (new ExpireCall($this->call->id))->handle(app(CallSweeper::class));

    Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->reason === 'timeout' && $e->by === null);
    expect(app(CallRegistry::class)->get($this->call->id))->toBeNull();
});

it('leaves a ring alone while the callee is negotiating, and looks again a heartbeat later', function () {
    Queue::fake();

    // The customer tapped Answer at second 29: their page is heartbeating, but
    // ICE has not completed, so nothing has written answeredAt yet.
    app(CallRegistry::class)->touch($this->call->id, $this->call->channel(), $this->customer->id, 'connecting', 30);

    (new ExpireCall($this->call->id))->handle(app(CallSweeper::class));

    Event::assertNotDispatched(CallDeclined::class);
    expect(app(CallRegistry::class)->get($this->call->id))->not->toBeNull();
    Queue::assertPushed(ExpireCall::class, fn (ExpireCall $job) => $job->callId === $this->call->id && $job->attempt === 2);
});

it('gives up on a callee who joined and then vanished', function () {
    Queue::fake();
    app(CallRegistry::class)->touch($this->call->id, $this->call->channel(), $this->customer->id, 'connecting', 30, now()->getTimestamp() - 200);

    (new ExpireCall($this->call->id, 2))->handle(app(CallSweeper::class));

    Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->reason === 'timeout');
    Queue::assertNotPushed(ExpireCall::class);
});

it('stops looking after the last attempt, so a wedged negotiation cannot queue for ever', function () {
    Queue::fake();
    app(CallRegistry::class)->touch($this->call->id, $this->call->channel(), $this->customer->id, 'connecting', 30);

    (new ExpireCall($this->call->id, ExpireCall::ATTEMPTS))->handle(app(CallSweeper::class));

    Queue::assertNotPushed(ExpireCall::class);
    Event::assertNotDispatched(CallDeclined::class);   // still answering; the sweeper's next pass ends it when they go quiet
});

it('does nothing to a call that was answered', function () {
    app(CallRegistry::class)->touch($this->call->id, $this->call->channel(), $this->customer->id, 'connected', 30);
    app(CallRegistry::class)->answered($this->call->id);

    (new ExpireCall($this->call->id))->handle(app(CallSweeper::class));

    Event::assertNotDispatched(CallDeclined::class);
});
