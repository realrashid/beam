<?php

declare(strict_types=1);

use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Turn\CoturnDriver;

/**
 * The vector below was computed outside PHP, with coturn's own scheme:
 *   printf '1700000000:42' | openssl dgst -sha1 -hmac 'beam-secret' -binary | base64
 * If this test ever fails, the driver has drifted from what coturn verifies.
 */
const COTURN_VECTOR = 'Cpjz70hQOC19Tma+fwXdbfQ9q58=';

it('matches the openssl-computed vector for coturn\'s use-auth-secret scheme', function () {
    $driver = new CoturnDriver(['turn:relay.test:3478'], 'beam-secret', ttl: 0);

    $creds = $driver->credentialsFor(42, now: 1700000000);

    expect($creds)->toBe([['urls' => ['turn:relay.test:3478'], 'username' => '1700000000:42', 'credential' => COTURN_VECTOR]]);
});

it('puts the expiry, not the issue time, in the username', function () {
    $creds = (new CoturnDriver(['turn:r:3478'], 's', ttl: 3600))->credentialsFor('u7', now: 1000);

    expect($creds[0]['username'])->toBe('4600:u7');
});

it('changes the credential when the secret changes', function () {
    $a = (new CoturnDriver(['turn:r:3478'], 'one'))->credentialsFor(1, now: 1000)[0]['credential'];
    $b = (new CoturnDriver(['turn:r:3478'], 'two'))->credentialsFor(1, now: 1000)[0]['credential'];

    expect($a)->not->toBe($b);
});

it('carries every configured url, turn and turns, in one entry', function () {
    $creds = CoturnDriver::fromConfig(['urls' => ['turn:r:3478', 'turns:r:5349'], 'secret' => 's'])->credentialsFor(1);

    expect($creds)->toHaveCount(1)->and($creds[0]['urls'])->toBe(['turn:r:3478', 'turns:r:5349']);
});

it('refuses to mint without a secret or without urls, naming the config key', function () {
    expect(fn () => CoturnDriver::fromConfig(['urls' => ['turn:r:3478']])->credentialsFor(1))
        ->toThrow(fn (BeamException $e) => expect($e->getMessage())->toContain('coturn.secret'));
    expect(fn () => CoturnDriver::fromConfig(['secret' => 's'])->credentialsFor(1))
        ->toThrow(fn (BeamException $e) => expect($e->getMessage())->toContain('coturn.urls'));
});

it('is selected by config and feeds the token', function () {
    config()->set('beam.ice.turn', ['default' => 'coturn', 'drivers' => ['coturn' => ['urls' => ['turn:r:3478'], 'secret' => 's', 'ttl' => 60]]]);

    $ice = beam()->ice(9);

    expect($ice->hasTurn())->toBeTrue()->and($ice->turn[0]['username'])->toEndWith(':9');
});
