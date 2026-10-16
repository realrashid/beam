<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Exceptions\ErrorCode;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Turn\CloudflareDriver;
use RealRashid\Beam\Turn\MeteredDriver;
use RealRashid\Beam\Turn\TwilioDriver;

describe('cloudflare', function () {
    it('posts the ttl with the bearer token to the verified endpoint and maps a 201', function () {
        Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['iceServers' => [
            ['urls' => ['stun:stun.cloudflare.com:3478']],
            ['urls' => ['turn:turn.cloudflare.com:3478?transport=udp', 'turns:turn.cloudflare.com:5349?transport=tcp'], 'username' => 'cf-user', 'credential' => 'cf-pass'],
        ]], 201)]);

        $creds = CloudflareDriver::fromConfig(['key_id' => 'key123', 'token' => 'tok', 'ttl' => 600])->credentialsFor(1);

        expect($creds)->toBe([['urls' => ['turn:turn.cloudflare.com:3478?transport=udp', 'turns:turn.cloudflare.com:5349?transport=tcp'], 'username' => 'cf-user', 'credential' => 'cf-pass']]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://rtc.live.cloudflare.com/v1/turn/keys/key123/credentials/generate-ice-servers'
            && $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer tok') && $r['ttl'] === 600);
    });

    it('reports TURN_UNAVAILABLE on an HTTP failure, after a second attempt', function () {
        Http::fake(['rtc.live.cloudflare.com/*' => Http::response('nope', 503)]);

        expect(fn () => CloudflareDriver::fromConfig(['key_id' => 'k', 'token' => 't'])->credentialsFor(1))
            ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::TurnUnavailable)->and($e->getMessage())->toContain('503'));
        Http::assertSentCount(2);   // retry(2) is two attempts in total
    });

    it('reports TURN_UNAVAILABLE when the response has no credentials', function () {
        Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['iceServers' => [['urls' => ['stun:only']]]], 201)]);

        expect(fn () => CloudflareDriver::fromConfig(['key_id' => 'k', 'token' => 't'])->credentialsFor(1))
            ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::TurnUnavailable));
    });

    it('refuses to mint without key_id and token, naming both', function () {
        expect(fn () => CloudflareDriver::fromConfig(['token' => 't'])->credentialsFor(1))
            ->toThrow(fn (BeamException $e) => expect($e->getMessage())->toContain('key_id'));
    });
});

describe('twilio', function () {
    it('posts Ttl as a form field with basic auth and tolerates url as well as urls', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['ice_servers' => [
            ['url' => 'stun:global.stun.twilio.com:3478'],
            ['url' => 'turn:global.turn.twilio.com:3478?transport=udp', 'username' => 'tw-user', 'credential' => 'tw-pass'],
            ['urls' => 'turn:global.turn.twilio.com:443?transport=tcp', 'username' => 'tw-user', 'credential' => 'tw-pass'],
        ]])]);

        $creds = TwilioDriver::fromConfig(['sid' => 'AC123', 'token' => 'auth', 'ttl' => 7200])->credentialsFor(1);

        expect($creds)->toHaveCount(2)
            ->and($creds[0]['urls'])->toBe(['turn:global.turn.twilio.com:3478?transport=udp'])
            ->and($creds[1]['urls'])->toBe(['turn:global.turn.twilio.com:443?transport=tcp']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Tokens.json'
            && $r->isForm() && (int) $r['Ttl'] === 7200 && str_starts_with($r->header('Authorization')[0], 'Basic '));
    });

    it('caps ttl at 24 hours, as Twilio does', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['ice_servers' => [['urls' => 'turn:t', 'username' => 'u', 'credential' => 'c']]])]);

        TwilioDriver::fromConfig(['sid' => 'AC1', 'token' => 't', 'ttl' => 999999])->credentialsFor(1);

        Http::assertSent(fn (Request $r) => (int) $r['Ttl'] === 86400);
    });

    it('reports TURN_UNAVAILABLE on failure', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'unauthorized'], 401)]);

        expect(fn () => TwilioDriver::fromConfig(['sid' => 'AC1', 'token' => 'bad'])->credentialsFor(1))
            ->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::TurnUnavailable));
    });
});

describe('metered', function () {
    it('gets credentials from the app subdomain with the api key, dropping STUN-only entries', function () {
        Http::fake(['myapp.metered.live/*' => Http::response([
            ['urls' => 'stun:stun.relay.metered.ca:80'],
            ['urls' => 'turn:global.relay.metered.ca:80', 'username' => 'm-user', 'credential' => 'm-pass'],
            ['urls' => 'turns:global.relay.metered.ca:443?transport=tcp', 'username' => 'm-user', 'credential' => 'm-pass'],
        ])]);

        $creds = MeteredDriver::fromConfig(['app' => 'myapp', 'api_key' => 'key'])->credentialsFor(1);

        expect($creds)->toHaveCount(2)->and($creds[0]['username'])->toBe('m-user');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://myapp.metered.live/api/v1/turn/credentials?') && $r['apiKey'] === 'key');
    });

    it('refuses without the app subdomain, naming it', function () {
        expect(fn () => MeteredDriver::fromConfig(['api_key' => 'k'])->credentialsFor(1))
            ->toThrow(fn (BeamException $e) => expect($e->getMessage())->toContain('metered.app'));
    });
});

it('switching drivers is one config line: ring() goes through whichever is set, and survives a vendor outage', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['ice_servers' => [['urls' => 'turn:tw', 'username' => 'u', 'credential' => 'c']]])]);

    config()->set('beam.ice.turn', ['default' => 'twilio', 'drivers' => ['twilio' => ['sid' => 'AC1', 'token' => 't']]]);
    expect(beam()->ice(1)->turn[0]['urls'])->toBe(['turn:tw']);

    config()->set('beam.ice.turn', ['default' => 'cloudflare', 'drivers' => ['cloudflare' => ['key_id' => 'k', 'token' => 't']]]);
    Http::fake(['rtc.live.cloudflare.com/*' => Http::response('down', 500)]);
    expect(fn () => beam()->ice(1))->toThrow(fn (BeamException $e) => expect($e->errorCode)->toBe(ErrorCode::TurnUnavailable));
});

it('mints a vendor credential once and keeps it for a quarter of its life', function () {
    Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['iceServers' => [
        ['urls' => ['stun:stun.cloudflare.com:3478']],
        ['urls' => ['turn:turn.cloudflare.com:3478'], 'username' => 'u', 'credential' => 'c'],
    ]], 201)]);
    config()->set('beam.ice.turn', ['default' => 'cloudflare', 'cache' => true, 'drivers' => ['cloudflare' => ['key_id' => 'k', 'token' => 't', 'ttl' => 3600]]]);

    // Two participants ring per call, and the same pair may ring again a minute later.
    $first = Beam::ice(7)->turn;
    $second = Beam::ice(7)->turn;
    $other = Beam::ice(8)->turn;

    expect($first)->toBe($second)->and($first[0]['username'])->toBe('u');
    Http::assertSentCount(2);   // once for user 7, once for user 8 — not four times

    expect($other)->toBe($first);
});

it('mints every time when the cache is off, and never caches a rotated key\'s credentials', function () {
    Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['iceServers' => [['urls' => ['turn:t'], 'username' => 'u', 'credential' => 'c']]], 201)]);
    config()->set('beam.ice.turn', ['default' => 'cloudflare', 'cache' => false, 'drivers' => ['cloudflare' => ['key_id' => 'k', 'token' => 't', 'ttl' => 3600]]]);

    Beam::ice(7);
    Beam::ice(7);
    Http::assertSentCount(2);

    // Rotating the API token must not serve what the old one minted.
    config()->set('beam.ice.turn.cache', true);
    Beam::ice(7);
    config()->set('beam.ice.turn.drivers.cloudflare.token', 'rotated');
    Beam::ice(7);
    Http::assertSentCount(4);
});

it('does not cache coturn, whose credential is an HMAC and is cheaper than a cache read', function () {
    config()->set('beam.ice.turn', ['default' => 'coturn', 'cache' => true, 'drivers' => ['coturn' => ['urls' => ['turn:r:3478'], 'secret' => 's', 'ttl' => 3600]]]);

    $first = Beam::ice(7)->turn[0]['username'];
    $this->travel(2)->seconds();
    $second = Beam::ice(7)->turn[0]['username'];

    expect($second)->not->toBe($first);   // minted fresh: a new expiry each time
});
