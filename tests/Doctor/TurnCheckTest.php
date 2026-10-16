<?php

declare(strict_types=1);

use RealRashid\Beam\Support\Doctor\EchoClientEventsCheck;
use RealRashid\Beam\Support\Doctor\TurnCheck;

it('warns, with the one-in-five sentence, when no relay is configured', function () {
    $result = (new TurnCheck(beam()))->run();

    expect($result->status)->toBe('warn')->and($result->message)->toContain('one in five')->and($result->fix)->toContain('BEAM_TURN');
});

it('fails when the driver cannot mint', function () {
    config()->set('beam.ice.turn', ['default' => 'coturn', 'drivers' => ['coturn' => ['urls' => ['turn:r:3478']]]]);   // no secret

    $result = (new TurnCheck(beam()))->run();

    expect($result->status)->toBe('fail')->and($result->message)->toContain('coturn.secret');
});

it('fails naming the hosts that do not answer, and passes when they all do', function () {
    config()->set('beam.ice.turn', ['default' => 'coturn', 'drivers' => ['coturn' => ['urls' => ['turn:relay.test:3478', 'turns:relay.test:5349'], 'secret' => 's']]]);

    $down = (new TurnCheck(beam(), fn (string $host, int $port) => $port === 3478))->run();
    expect($down->status)->toBe('fail')->and($down->message)->toContain('relay.test:5349')->and($down->message)->not->toContain('relay.test:3478');

    $up = (new TurnCheck(beam(), fn () => true))->run();
    expect($up->status)->toBe('ok');
});

it('parses turn urls into host and port with the scheme defaults', function () {
    expect(TurnCheck::hostAndPort('turn:relay.test:3478?transport=udp'))->toBe(['relay.test', 3478])
        ->and(TurnCheck::hostAndPort('turns:relay.test'))->toBe(['relay.test', 5349])
        ->and(TurnCheck::hostAndPort('turn:relay.test'))->toBe(['relay.test', 3478])
        ->and(TurnCheck::hostAndPort('stun:x'))->toBe([null, 0]);
});

it('client events: irrelevant on the controller transport, checked on reverb, only advisable on pusher', function () {
    expect((new EchoClientEventsCheck)->run()->status)->toBe('ok');

    config()->set('beam.signaling.transport', 'whisper');
    config()->set('broadcasting.default', 'pusher');
    $pusher = (new EchoClientEventsCheck)->run();
    expect($pusher->status)->toBe('warn')->and($pusher->fix)->toContain('BEAM_SIGNALING=controller');

    config()->set('broadcasting.default', 'reverb');
    expect((new EchoClientEventsCheck)->run()->status)->toBe('ok');
});
