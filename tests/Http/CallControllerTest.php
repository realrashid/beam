<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Broadcasts\CallControl;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Events\CallAnswered;
use RealRashid\Beam\Events\CallDeclined;
use RealRashid\Beam\Events\CallEnded;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Http\Middleware\ValidateBeamToken;
use RealRashid\Beam\Jobs\SweepCalls;
use RealRashid\Beam\Support\CallRegistry;

beforeEach(function () {
    Event::fake([IncomingCall::class, CallControl::class, CallAnswered::class, CallDeclined::class, CallEnded::class]);
    Queue::fake();
    $this->agent = makeUser('Agent');
    $this->customer = makeUser('Customer');
    $this->stranger = makeUser('Stranger');
    $this->call = Beam::call($this->customer)->from($this->agent)->timeout(30)->ring();
});

it('heartbeat: refreshes the participant, and the first connected from either side answers the call once', function () {
    $this->actingAs($this->customer)->postJson("/beam/calls/{$this->call->id}/heartbeat", ['state' => 'connecting'])->assertNoContent();
    Event::assertNotDispatched(CallAnswered::class);

    $this->actingAs($this->customer)->postJson("/beam/calls/{$this->call->id}/heartbeat", ['state' => 'connected'])->assertNoContent();
    $this->actingAs($this->agent)->postJson("/beam/calls/{$this->call->id}/heartbeat", ['state' => 'connected'])->assertNoContent();

    Event::assertDispatchedTimes(CallAnswered::class, 1);
    Event::assertDispatched(CallAnswered::class, fn (CallAnswered $e) => $e->by->id === $this->customer->id && $e->call->answeredAt !== null);
    Queue::assertPushed(SweepCalls::class, 1);
    expect(app(CallRegistry::class)->participants($this->call->id))->toHaveKeys([(string) $this->customer->id, (string) $this->agent->id])
        ->and(Beam::find($this->call->id)->duration())->toBeInt();
});

it('heartbeat: refuses a guest, a bad state, a non-participant, and an unknown call', function () {
    // The guest request first: actingAs() persists on the test case for the rest of the test.
    $this->postJson("/beam/calls/{$this->call->id}/heartbeat", ['state' => 'connected'])->assertUnauthorized();
    $this->actingAs($this->customer)->postJson("/beam/calls/{$this->call->id}/heartbeat", ['state' => 'flying'])->assertUnprocessable();
    $this->actingAs($this->stranger)->postJson("/beam/calls/{$this->call->id}/heartbeat", ['state' => 'connected'])->assertForbidden();
    $this->actingAs($this->customer)->postJson('/beam/calls/call_nope/heartbeat', ['state' => 'connected'])->assertForbidden();
    Event::assertNotDispatched(CallAnswered::class);
});

it('decline: a participant declines with a reason, the other side is told, and the call is gone', function () {
    $this->actingAs($this->customer)->postJson("/beam/calls/{$this->call->id}/decline", ['reason' => 'busy'])->assertNoContent();

    Event::assertDispatched(CallControl::class, fn (CallControl $c) => $c->action === 'decline' && $c->reason === 'busy' && $c->by?->id === $this->customer->id);
    Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->reason === 'busy' && $e->by?->id === $this->customer->id);
    $this->actingAs($this->customer)->postJson("/beam/calls/{$this->call->id}/decline")->assertForbidden();   // already gone
});

it('end: a participant hangs up; a stranger cannot', function () {
    $this->actingAs($this->stranger)->postJson("/beam/calls/{$this->call->id}/end")->assertForbidden();
    Event::assertNotDispatched(CallEnded::class);

    $this->actingAs($this->agent)->postJson("/beam/calls/{$this->call->id}/end")->assertNoContent();
    Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->reason === 'hangup' && $e->by?->id === $this->agent->id);
});

it('timeout: only the caller may report it, and only while still ringing', function () {
    $this->actingAs($this->customer)->postJson("/beam/calls/{$this->call->id}/timeout")->assertForbidden();
    Event::assertNotDispatched(CallDeclined::class);

    $this->actingAs($this->agent)->postJson("/beam/calls/{$this->call->id}/timeout")->assertNoContent();
    Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->reason === 'timeout' && $e->by === null);
});

it('timeout: does nothing once the call was answered', function () {
    $this->actingAs($this->customer)->postJson("/beam/calls/{$this->call->id}/heartbeat", ['state' => 'connected']);
    $this->actingAs($this->agent)->postJson("/beam/calls/{$this->call->id}/timeout")->assertNoContent();

    Event::assertNotDispatched(CallDeclined::class);
    expect(fn () => Beam::find($this->call->id))->not->toThrow(Throwable::class);
});

it('heartbeat: a room participant is refreshed too, without an answer moment', function () {
    $alice = makeUser('Alice');
    $room = Beam::room('consult-88')->allow($alice)->create();
    $room->token($alice);

    $this->actingAs($alice)->postJson('/beam/calls/consult-88/heartbeat', ['state' => 'connected'])->assertNoContent();

    Event::assertNotDispatched(CallAnswered::class);
    expect(app(CallRegistry::class)->participants('consult-88'))->toHaveKey((string) $alice->id);
});

it('decline from the notification: the token is the proof, with no session and no CSRF token', function () {
    // What the push carried, and what the service worker posts back.
    $calleeToken = null;
    Event::assertDispatched(IncomingCall::class, function (IncomingCall $e) use (&$calleeToken) {
        $calleeToken = $e->calleeToken;

        return true;
    });

    // No actingAs(): a locked phone has no page open, and a worker has no CSRF token.
    $this->postJson("/beam/calls/{$this->call->id}/decline-by-token", ['beam_token' => $calleeToken, 'reason' => 'declined'])->assertNoContent();

    Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->call->id === $this->call->id && $e->reason === 'declined' && $e->by?->id === $this->customer->id);
    expect(app(CallRegistry::class)->get($this->call->id))->toBeNull();
});

it('decline from the notification: refuses no token, the caller\'s token, and a token for another call', function () {
    $other = Beam::call($this->stranger)->from($this->agent)->ring();

    $this->postJson("/beam/calls/{$this->call->id}/decline-by-token", [])->assertUnauthorized()->assertJsonPath('reason', 'MISSING_TOKEN');
    $this->postJson("/beam/calls/{$this->call->id}/decline-by-token", ['beam_token' => $this->call->token])->assertForbidden();   // the caller's own token: not theirs to decline

    $calleeTokens = [];
    Event::assertDispatched(IncomingCall::class, function (IncomingCall $e) use (&$calleeTokens) {
        $calleeTokens[$e->call->id] = $e->calleeToken;

        return true;
    });
    $this->postJson("/beam/calls/{$this->call->id}/decline-by-token", ['beam_token' => $calleeTokens[$other->id]])->assertForbidden();

    Event::assertNotDispatched(CallDeclined::class);
});

it('decline from the notification: says nothing went wrong when the ring is already over', function () {
    $calleeToken = null;
    Event::assertDispatched(IncomingCall::class, function (IncomingCall $e) use (&$calleeToken) {
        $calleeToken = $e->calleeToken;

        return true;
    });

    $this->actingAs($this->customer)->postJson("/beam/calls/{$this->call->id}/heartbeat", ['state' => 'connected'])->assertNoContent();   // answered on the laptop

    $this->postJson("/beam/calls/{$this->call->id}/decline-by-token", ['beam_token' => $calleeToken])->assertNoContent();

    Event::assertNotDispatched(CallDeclined::class);   // the tap lost the race; nothing to decline
});

it('the route a service worker calls is outside the session group, so no CSRF check can refuse it', function () {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'beam.calls.decline.token');

    expect($route->gatherMiddleware())->toBe([ValidateBeamToken::class])
        ->not->toContain('web');
});
