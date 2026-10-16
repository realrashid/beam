<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Encryption\Encrypter as ConcreteEncrypter;
use RealRashid\Beam\Data\IceServers;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Exceptions\ErrorCode;

function aToken(array $overrides = []): Token
{
    return new Token(...array_replace([
        'callId' => 'call_1',
        'channel' => 'beam.call.call_1',
        'role' => 'callee',
        'userId' => 7,
        'ice' => new IceServers(['stun:s.test:3478'], [['urls' => ['turn:t.test:3478'], 'username' => 'u', 'credential' => 'c']]),
        'expiresAt' => time() + 60,
        'meta' => ['about' => 'Order #1042', 'media' => 'video', 'transport' => 'controller'],
    ], $overrides));
}

it('round-trips every claim through sign and parse', function () {
    $parsed = Token::parse(aToken()->sign(app(Encrypter::class)), app(Encrypter::class));

    expect($parsed->callId)->toBe('call_1')
        ->and($parsed->channel)->toBe('beam.call.call_1')
        ->and($parsed->role)->toBe('callee')
        ->and($parsed->userId)->toBe(7)
        ->and($parsed->ice->stun)->toBe(['stun:s.test:3478'])
        ->and($parsed->ice->hasTurn())->toBeTrue()
        ->and($parsed->ice->turn[0]['credential'])->toBe('c')
        ->and($parsed->meta)->toBe(['about' => 'Order #1042', 'media' => 'video', 'transport' => 'controller'])
        ->and($parsed->isFor('beam.call.call_1'))->toBeTrue()
        ->and($parsed->isFor('beam.call.call_2'))->toBeFalse();
});

it('packs the opaque token beside plain claims the browser can read without the app key', function () {
    $packed = aToken()->pack(app(Encrypter::class));

    expect($packed)->not->toContain('+')->not->toContain('/')->not->toContain('=');

    $json = json_decode(base64_decode(strtr($packed, '-_', '+/')), true);

    expect($json)->toHaveKeys(['token', 'claims'])
        ->and($json['claims']['v'])->toBe(1)
        ->and($json['claims']['channel'])->toBe('beam.call.call_1')
        ->and($json['claims']['ice'][0]['urls'])->toBe(['stun:s.test:3478'])
        ->and($json['claims']['ice'][1]['credential'])->toBe('c')
        ->and($json['claims']['meta']['about'])->toBe('Order #1042');

    // and the opaque half still parses server-side
    expect(Token::parse($json['token'], app(Encrypter::class))->callId)->toBe('call_1');
});

it('encodes empty meta as an object, never a JSON array', function () {
    $packed = aToken(['meta' => []])->pack(app(Encrypter::class));
    $json = base64_decode(strtr($packed, '-_', '+/'));

    expect($json)->toContain('"meta":{}');
});

it('refuses an unknown role at construction', function () {
    expect(fn () => aToken(['role' => 'admin']))->toThrow(BeamException::class);
});

it('refuses an expired token', function () {
    $signed = aToken(['expiresAt' => time() - 1])->sign(app(Encrypter::class));

    expect(fn () => Token::parse($signed, app(Encrypter::class)))
        ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::Expired));
});

it('refuses garbage as tampered', function () {
    expect(fn () => Token::parse('not-a-token', app(Encrypter::class)))
        ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::Tampered));
});

it('refuses a token signed with a different application key as tampered', function () {
    $other = new ConcreteEncrypter(random_bytes(32), 'AES-256-CBC');

    expect(fn () => Token::parse(aToken()->sign($other), app(Encrypter::class)))
        ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::Tampered));
});

it('refuses a token from another protocol version with a message that says what to update', function () {
    $json = json_encode(['v' => 2, 'callId' => 'x', 'channel' => 'beam.call.x', 'role' => 'caller', 'sub' => 1, 'ice' => [], 'exp' => time() + 60]);
    $signed = app(Encrypter::class)->encrypt($json, serialize: false);

    expect(fn () => Token::parse($signed, app(Encrypter::class)))
        ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::ProtocolMismatch)
            ->and($e->getMessage())->toContain('@realrashid/beam'));
});

it('refuses a well-signed token with the wrong shape as tampered', function (array $claims) {
    $signed = app(Encrypter::class)->encrypt(json_encode(['v' => 1, 'exp' => time() + 60] + $claims), serialize: false);

    expect(fn () => Token::parse($signed, app(Encrypter::class)))
        ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::Tampered));
})->with([
    'no channel' => [['callId' => 'x', 'role' => 'caller', 'sub' => 1, 'ice' => []]],
    'no subject' => [['callId' => 'x', 'channel' => 'beam.call.x', 'role' => 'caller', 'ice' => []]],
    'bad role' => [['callId' => 'x', 'channel' => 'beam.call.x', 'role' => 'root', 'sub' => 1, 'ice' => []]],
    'no ice' => [['callId' => 'x', 'channel' => 'beam.call.x', 'role' => 'caller', 'sub' => 1]],
]);

it('never uses PHP serialisation', function () {
    $inside = app(Encrypter::class)->decrypt(aToken()->sign(app(Encrypter::class)), unserialize: false);

    expect($inside)->toStartWith('{')->and(json_decode($inside, true))->toBeArray();
});

it('reads a token whether it arrives opaque or packed', function () {
    $encrypter = app(Encrypter::class);
    $token = new Token('call_1', 'beam.call.call_1', 'callee', 7, IceServers::fromConfig([]), time() + 60);

    // The browser presents the opaque half; a service worker has only the packed
    // payload the push carried.
    $opaque = $token->sign($encrypter);

    expect(Token::parse($opaque, $encrypter)->callId)->toBe('call_1')
        ->and(Token::parse($token->pack($encrypter), $encrypter)->callId)->toBe('call_1')
        ->and(Token::opaque($opaque))->toBe($opaque);   // an opaque token is left alone

    expect(fn () => Token::parse('not-a-token', $encrypter))->toThrow(BeamException::class);
});
