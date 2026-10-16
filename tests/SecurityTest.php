<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Broadcasts\Signal;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Http\Controllers\AssetController;
use RealRashid\Beam\Signaling\Channels;
use RealRashid\Beam\Support\CallRegistry;

/**
 * The security review, as tests: every way a token, a channel or a payload
 * could leak or be misused, refused where it must be — so a change that
 * loosens any of it turns red here first.
 */
beforeEach(function () {
    Broadcast::routes(['middleware' => ['web']]);
    Event::fake([Signal::class, IncomingCall::class]);
});

it('does not let a token for call A authorise channel B, on the relay or on Broadcasting', function () {
    $user = makeUser();
    $tokenA = tokenFor($user, 'beam.call.call_A');
    app(CallRegistry::class)->allow('beam.call.call_B', makeUser('Other')->getAuthIdentifier(), 60);

    $this->actingAs($user)
        ->postJson('/beam/signal', ['channel' => 'beam.call.call_B', 'message' => ['type' => 'bye', 'reason' => 'x']], ['X-Beam-Token' => $tokenA])
        ->assertForbidden()->assertJson(['reason' => 'FORBIDDEN']);
    $this->actingAs($user)->postJson('/broadcasting/auth', ['channel_name' => 'private-beam.call.call_B', 'socket_id' => '1.1'])->assertForbidden();
    Event::assertNotDispatched(Signal::class);
});

it('refuses an expired token on the relay with 401 EXPIRED, and a grant that has lapsed on Broadcasting', function () {
    $user = makeUser();
    $token = tokenFor($user, 'beam.call.call_A', ttl: -10, grant: false);

    $this->actingAs($user)
        ->postJson('/beam/signal', ['channel' => 'beam.call.call_A', 'message' => ['type' => 'bye', 'reason' => 'x']], ['X-Beam-Token' => $token])
        ->assertStatus(401)->assertJson(['reason' => 'EXPIRED']);
    $this->actingAs($user)->postJson('/broadcasting/auth', ['channel_name' => 'private-beam.call.call_A', 'socket_id' => '1.1'])->assertForbidden();
});

it('refuses to relay for a user whose grant was revoked, even with a token that is still valid', function () {
    $user = makeUser();
    $token = tokenFor($user, 'beam.call.call_A');
    app(CallRegistry::class)->revoke('beam.call.call_A', $user->getAuthIdentifier());

    $this->actingAs($user)
        ->postJson('/beam/signal', ['channel' => 'beam.call.call_A', 'message' => ['type' => 'bye', 'reason' => 'x']], ['X-Beam-Token' => $token])
        ->assertForbidden();
    Event::assertNotDispatched(Signal::class);
});

it('gives a room token only to a user the room allows, and the room channel only to a token holder', function () {
    $doctor = makeUser('Doc');
    $patient = makeUser('Pat');
    $stranger = makeUser('Stranger');
    $room = Beam::room('consult-1')->allow($doctor, $patient)->create();

    expect($room->token($patient))->toBeString();
    try {
        $room->token($stranger);
        $this->fail('A stranger got a room token.');
    } catch (BeamException $e) {
        expect($e->reason())->toBe('FORBIDDEN');
    }

    $this->actingAs($patient)->postJson('/broadcasting/auth', ['channel_name' => 'private-'.Channels::room('consult-1'), 'socket_id' => '1.1'])->assertOk();
    $this->actingAs($stranger)->postJson('/broadcasting/auth', ['channel_name' => 'private-'.Channels::room('consult-1'), 'socket_id' => '1.1'])->assertForbidden();
});

it('broadcasts the callee\'s token in IncomingCall — never the caller\'s', function () {
    $caller = makeUser('Caller');
    $callee = makeUser('Callee');
    $call = Beam::call($callee)->from($caller)->video()->ring();

    Event::assertDispatched(IncomingCall::class, function (IncomingCall $e) use ($call, $callee, $caller) {
        $claims = unpackToken($e->broadcastWith()['token'])['claims'];

        return $claims['sub'] === $callee->getAuthIdentifier()
            && $claims['role'] === 'callee'
            && unpackToken($call->token)['claims']['sub'] === $caller->getAuthIdentifier()
            && $e->broadcastWith()['token'] !== $call->token;
    });
});

it('puts no relay secret, API key or VAPID private key in the client, the assets directive, or a broadcast payload', function () {
    config()->set('beam.ice.turn', ['default' => 'coturn', 'drivers' => ['coturn' => ['urls' => ['turn:relay.test:3478'], 'secret' => 'the-turn-secret', 'ttl' => 60]]]);
    config()->set('beam.push.vapid', ['public' => 'pub', 'private' => 'the-vapid-private', 'subject' => 'mailto:x@y.z']);
    $caller = makeUser('Caller');
    $callee = makeUser('Callee');
    $call = Beam::call($callee)->from($caller)->ring();

    $client = (string) file_get_contents(AssetController::dist('beam.js'));
    $directive = view('beam::assets', ['version' => AssetController::version()])->render();
    $payload = json_encode(Event::dispatched(IncomingCall::class)->first()[0]->broadcastWith());

    foreach (['the-turn-secret', 'the-vapid-private'] as $secret) {
        expect($client)->not->toContain($secret)
            ->and($directive)->not->toContain($secret)
            ->and($payload)->not->toContain($secret);
    }
    expect($directive)->not->toMatch('/turn:|credential|vapid/i');

    // The token itself carries a minted, per-user TURN credential — and nothing that could mint another.
    $ice = unpackToken($call->token)['claims']['ice'];
    $turn = collect($ice)->first(fn ($s) => str_starts_with((string) (is_array($s['urls']) ? $s['urls'][0] : $s['urls']), 'turn:'));
    expect($turn['credential'])->not->toBe('the-turn-secret')->and($turn['username'])->toEndWith(':'.$caller->getAuthIdentifier());
});

it('passes every log line in src/ through the Redactor', function () {
    $lines = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $n => $line) {
            if (preg_match('/\bLog::\w+\(/', $line)) {
                $lines[$file->getFilename().':'.($n + 1)] = trim($line);
            }
        }
    }
    expect($lines)->not->toBeEmpty();

    foreach ($lines as $where => $line) {
        // A message with context must scrub it; a bare message with no context carries nothing to leak.
        $hasContext = str_contains($line, ', [') || str_contains($line, ', $') || str_contains($line, 'Redactor');
        expect(! $hasContext || str_contains($line, 'Redactor::scrub'))->toBeTrue("{$where} logs context without Redactor::scrub(): {$line}");
    }
});

it('throttles the relay and the push routes by default, at the configured rate', function () {
    $signal = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'beam.signal');
    $subscribe = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'beam.push.subscribe');
    $assets = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'beam.assets');

    expect($signal->gatherMiddleware())->toContain('throttle:240,1')->toContain('auth')
        ->and($subscribe->gatherMiddleware())->toContain('throttle:240,1')->toContain('auth')
        // Public on purpose — fetched before sign-in — and not by subtracting
        // `auth` from the group: see tests/Http/AssetControllerTest.php.
        ->and($assets->gatherMiddleware())->toBe([]);
    $this->get('/beam/beam.js')->assertOk();   // as a guest
});
