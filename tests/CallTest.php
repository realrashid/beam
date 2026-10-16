<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Broadcasts\CallControl;
use RealRashid\Beam\Call;
use RealRashid\Beam\Data\Participant;
use RealRashid\Beam\Events\CallDeclined;
use RealRashid\Beam\Events\CallEnded;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Support\CallRegistry;

function aCall(): Call
{
    $call = new Call('call_1', new Participant(1, 'Agent'), new Participant(2, 'Customer'), 'video', 'Order #1042', 30, 'packed', time());
    $registry = app(CallRegistry::class);
    $registry->allow($call->channel(), 1, 60);
    $registry->allow($call->channel(), 2, 60);
    $registry->put($call->id, $call->toArray(), 60);

    return $call;
}

beforeEach(fn () => Event::fake([CallControl::class, CallDeclined::class, CallEnded::class]));

it('declines: tells the channel, fires CallDeclined, and releases both participants', function () {
    $call = aCall();

    $call->decline('Agent unavailable');

    Event::assertDispatched(CallControl::class, fn (CallControl $c) => $c->channel === 'beam.call.call_1' && $c->action === 'decline' && $c->reason === 'Agent unavailable');
    Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->call->id === 'call_1' && $e->reason === 'Agent unavailable' && $e->by === null);
    expect(app(CallRegistry::class)->isParticipant('beam.call.call_1', 1))->toBeFalse()
        ->and(app(CallRegistry::class)->isParticipant('beam.call.call_1', 2))->toBeFalse()
        ->and(app(CallRegistry::class)->get('call_1'))->toBeNull();
});

it('ends: tells the channel, fires CallEnded with who ended it, and releases', function () {
    $call = aCall();
    $agent = $call->caller;

    $call->end(by: $agent);

    Event::assertDispatched(CallControl::class, fn (CallControl $c) => $c->action === 'end' && $c->by?->id === 1);
    Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->call->id === 'call_1' && $e->by?->id === 1 && $e->reason === 'server' && $e->duration === null);
    expect(app(CallRegistry::class)->get('call_1'))->toBeNull();
});

it('knows its participants', function () {
    $call = aCall();

    expect($call->isParticipant(1))->toBeTrue()
        ->and($call->isParticipant('2'))->toBeTrue()
        ->and($call->isParticipant(3))->toBeFalse()
        ->and($call->isParticipant(tap(makeUser(), fn () => makeUser())))->toBeTrue()   // the fresh db hands out id 1 — the caller
        ->and($call->isParticipant(makeUser()))->toBeFalse();                          // id 3
});

it('measures duration on the server, from answer to end or to now', function () {
    $never = aCall();
    $live = new Call('c', new Participant(1, 'A'), new Participant(2, 'B'), 'audio', null, 30, answeredAt: 1000);
    $ended = new Call('c', new Participant(1, 'A'), new Participant(2, 'B'), 'audio', null, 30, answeredAt: 1000, endedAt: 1042);

    expect($never->duration())->toBeNull()
        ->and($live->duration(now: 1010))->toBe(10)
        ->and($ended->duration())->toBe(42)
        ->and(Call::fromArray($ended->toArray())->duration())->toBe(42);
});

it('round-trips through toArray and fromArray without the token', function () {
    $back = Call::fromArray(aCall()->toArray());

    expect($back->id)->toBe('call_1')->and($back->callee->name)->toBe('Customer')->and($back->token)->toBe('');
});

it('links to the application\'s beam.join route, and says so when there is none', function () {
    $call = aCall();

    expect(fn () => $call->link())->toThrow(fn (BeamException $e) => expect($e->getMessage())->toContain('beam.join'));

    Route::get('/calls/{call}', fn () => 'x')->name('beam.join');
    Route::getRoutes()->refreshNameLookups();   // names added after boot are not indexed until refreshed

    expect($call->link())->toEndWith('/calls/call_1');
});
