<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate as LaravelGate;
use RealRashid\Beam\Broadcasts\CallControl;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Exceptions\ErrorCode;
use RealRashid\Beam\Room;
use RealRashid\Beam\Support\CallRegistry;

it('issues a participant token to an allowed user and registers them', function () {
    $alice = makeUser('Alice');
    $room = new Room('consult-88', allowed: [$alice->id], media: 'audio');

    $packed = $room->token($alice);
    $json = json_decode(base64_decode(strtr($packed, '-_', '+/')), true);
    $token = Token::parse($json['token'], app(Encrypter::class));

    expect($token->channel)->toBe('beam.room.consult-88')
        ->and($token->role)->toBe('participant')
        ->and($token->callId)->toBe('consult-88')
        ->and($token->userId)->toBe($alice->id)
        ->and($token->meta['media'])->toBe('audio')
        ->and($token->meta['transport'])->toBe('controller')
        ->and($token->ice->stun)->not->toBeEmpty()
        ->and(app(CallRegistry::class)->isParticipant('beam.room.consult-88', $alice->id))->toBeTrue();
});

it('refuses a token to a user outside the allow-list', function () {
    $bob = makeUser('Bob');
    $room = new Room('consult-88', allowed: [makeUser('Alice')->id]);

    expect(fn () => $room->token($bob))
        ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::Forbidden));
    expect(app(CallRegistry::class)->isParticipant('beam.room.consult-88', $bob->id))->toBeFalse();
});

it('refuses a token once the room has expired', function () {
    $alice = makeUser('Alice');
    $room = new Room('old', expiresAt: now()->subMinute());

    expect(fn () => $room->token($alice))
        ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::Expired));
});

it('bounds the token by the room expiry, not the default ttl', function () {
    $alice = makeUser('Alice');
    $room = new Room('soon', expiresAt: now()->addMinutes(5));

    $json = json_decode(base64_decode(strtr($room->token($alice), '-_', '+/')), true);

    expect($json['claims']['exp'])->toBeLessThanOrEqual(time() + 300)->toBeGreaterThan(time() + 290);
});

it('lets beam.join override the allow-list', function () {
    $bob = makeUser('Bob');
    LaravelGate::define('beam.join', fn ($user, Room $room) => true);

    expect(fn () => (new Room('private', allowed: [999]))->token($bob))->not->toThrow(BeamException::class);
});

it('closes: tells the channel and revokes everyone', function () {
    Event::fake([CallControl::class]);
    $alice = makeUser('Alice');
    $room = new Room('consult-88', allowed: [$alice->id]);
    $room->token($alice);

    $room->close('Appointment over');

    Event::assertDispatched(CallControl::class, fn (CallControl $c) => $c->channel === 'beam.room.consult-88' && $c->action === 'close' && $c->reason === 'Appointment over');
    expect(app(CallRegistry::class)->isParticipant('beam.room.consult-88', $alice->id))->toBeFalse();
});
