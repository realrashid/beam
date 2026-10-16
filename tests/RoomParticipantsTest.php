<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RealRashid\Beam\Events\ParticipantJoined;
use RealRashid\Beam\Events\ParticipantLeft;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Support\CallRegistry;

beforeEach(fn () => Event::fake([ParticipantJoined::class, ParticipantLeft::class]));

it('fires ParticipantJoined on a room member\'s first heartbeat, once, and ParticipantLeft with their time in the room when they end', function () {
    $doctor = makeUser('Doctor');
    $patient = makeUser('Patient');
    $room = Beam::room('consult-88')->allow($doctor, $patient)->create();
    $room->token($doctor);
    $room->token($patient);

    Carbon::setTestNow(Carbon::createFromTimestamp(1000));
    $this->actingAs($doctor)->postJson('/beam/calls/consult-88/heartbeat', ['state' => 'connecting'])->assertNoContent();
    $this->actingAs($doctor)->postJson('/beam/calls/consult-88/heartbeat', ['state' => 'connected'])->assertNoContent();
    $this->actingAs($patient)->postJson('/beam/calls/consult-88/heartbeat', ['state' => 'connected'])->assertNoContent();

    Event::assertDispatchedTimes(ParticipantJoined::class, 2);
    Event::assertDispatched(ParticipantJoined::class, fn (ParticipantJoined $e) => $e->room->name === 'consult-88' && $e->participant->id === $doctor->id);

    Carbon::setTestNow(Carbon::createFromTimestamp(1050));   // inside the 3 × heartbeat grant window
    $this->actingAs($patient)->postJson('/beam/calls/consult-88/end')->assertNoContent();

    Event::assertDispatched(ParticipantLeft::class, fn (ParticipantLeft $e) => $e->participant->id === $patient->id && $e->duration === 50);
    // The room is still open for the doctor.
    expect(app(CallRegistry::class)->isParticipant('beam.room.consult-88', $doctor->id))->toBeTrue()
        ->and(app(CallRegistry::class)->isParticipant('beam.room.consult-88', $patient->id))->toBeFalse()
        ->and(fn () => Beam::room('consult-88')->find())->not->toThrow(Throwable::class);
    Carbon::setTestNow();
});

it('refuses a leave from someone who is not in the room', function () {
    $room = Beam::room('consult-89')->allow(makeUser('Doctor'))->create();

    $this->actingAs(makeUser('Stranger'))->postJson('/beam/calls/consult-89/end')->assertForbidden();
    Event::assertNotDispatched(ParticipantLeft::class);
});

it('refuses the third person a token while two are live, and lets them in once one goes quiet', function () {
    $doctor = makeUser('Doctor');
    $patient = makeUser('Patient');
    $student = makeUser('Student');

    $room = Beam::room('consult-7')->create();   // no allow-list: the gate would decide, and there is none
    $registry = app(CallRegistry::class);

    expect($room->token($doctor))->toBeString()->and($room->token($patient))->toBeString();

    // Both are in the room and heartbeating.
    $registry->touch('consult-7', $room->channel(), $doctor->id, 'connected', 30);
    $registry->touch('consult-7', $room->channel(), $patient->id, 'connected', 30);

    // A room link forwarded to a third person: their browser would otherwise
    // renegotiate the pair's connection out from under them.
    expect(fn () => $room->token($student))
        ->toThrow(fn (BeamException $e) => expect($e->reason())->toBe('ROOM_FULL')->and($e->status())->toBe(409));

    // Reloading your own page is not a third person.
    expect($room->token($doctor))->toBeString();

    // And when the patient's tab has been gone for longer than the staleness
    // window, the seat is free again.
    $registry->touch('consult-7', $room->channel(), $patient->id, 'connected', 30, now()->getTimestamp() - 200);
    expect($room->token($student))->toBeString();
});

it('creating a room again keeps the people already in it', function () {
    $doctor = makeUser('Doctor');
    $room = Beam::room('consult-8')->video()->create();
    app(CallRegistry::class)->touch('consult-8', $room->channel(), $doctor->id, 'connected', 30);

    $joinedAt = app(CallRegistry::class)->participants('consult-8')[(string) $doctor->id]['joinedAt'];

    // The join page creates the room every time it is opened, which is the way
    // to survive a cache that was cleared between the booking and the call.
    $again = Beam::room('consult-8')->audio()->create();

    expect($again->media)->toBe('audio')                                      // the new intent wins
        ->and(app(CallRegistry::class)->participants('consult-8'))->toHaveKey((string) $doctor->id)
        ->and(app(CallRegistry::class)->participants('consult-8')[(string) $doctor->id]['joinedAt'])->toBe($joinedAt)
        ->and(app(CallRegistry::class)->hasSeen('consult-8', $doctor->id))->toBeTrue();   // so ParticipantJoined does not fire twice
});
