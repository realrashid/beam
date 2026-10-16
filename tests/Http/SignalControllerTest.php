<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Event;
use RealRashid\Beam\Broadcasts\Signal;
use RealRashid\Beam\Signaling\Envelope;

const CHANNEL = 'beam.room.phase1';

function offerPayload(string $channel = CHANNEL, array $overrides = []): array
{
    return array_replace_recursive([
        'channel' => $channel,
        'message' => ['type' => 'offer', 'sdp' => ['type' => 'offer', 'sdp' => 'v=0']],
    ], $overrides);
}

beforeEach(fn () => Event::fake([Signal::class]));

it('relays an offer from an authenticated participant with a valid token, setting from server-side', function () {
    $user = makeUser();
    $token = tokenFor($user, CHANNEL);

    $this->actingAs($user)
        ->postJson('/beam/signal', offerPayload(overrides: ['message' => ['from' => 'spoofed']]), ['X-Beam-Token' => $token])
        ->assertNoContent();

    Event::assertDispatched(Signal::class, fn (Signal $s) => $s->channel === CHANNEL
        && $s->message['type'] === 'offer'
        && $s->message['from'] === $user->getAuthIdentifier());
});

it('relays an SDP byte-for-byte, including the trailing CRLF the framework would trim', function () {
    // The rig found this: TrimStrings stripped the final \r\n and Chrome refused
    // the offer with "Failed to parse SessionDescription".
    $user = makeUser();
    $token = tokenFor($user, CHANNEL);
    $sdp = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=ssrc:1 msid:a b\r\n";

    $this->actingAs($user)
        ->withMiddleware(TrimStrings::class)
        ->postJson('/beam/signal', offerPayload(overrides: ['message' => ['sdp' => ['sdp' => $sdp]]]), ['X-Beam-Token' => $token])
        ->assertNoContent();

    Event::assertDispatched(Signal::class, fn (Signal $s) => $s->message['sdp']['sdp'] === $sdp);
});

it('relays a hello', function () {
    $user = makeUser();
    $token = tokenFor($user, CHANNEL);

    $this->actingAs($user)->postJson('/beam/signal', ['channel' => CHANNEL, 'message' => ['type' => 'hello']], ['X-Beam-Token' => $token])
        ->assertNoContent();

    Event::assertDispatched(Signal::class, fn (Signal $s) => $s->message === ['type' => 'hello', 'from' => $user->getAuthIdentifier()]);
});

it('relays an ICE batch and a bye', function () {
    $user = makeUser();
    $token = tokenFor($user, CHANNEL);

    $this->actingAs($user)->postJson('/beam/signal', [
        'channel' => CHANNEL,
        'message' => ['type' => 'ice', 'candidates' => [['candidate' => 'c1', 'sdpMid' => '0'], ['candidate' => 'c2', 'sdpMid' => '0']]],
    ], ['X-Beam-Token' => $token])->assertNoContent();

    $this->actingAs($user)->postJson('/beam/signal', [
        'channel' => CHANNEL,
        'message' => ['type' => 'bye', 'reason' => 'hangup'],
    ], ['X-Beam-Token' => $token])->assertNoContent();

    Event::assertDispatchedTimes(Signal::class, 2);
});

it('refuses an anonymous request', function () {
    $this->postJson('/beam/signal', offerPayload(), ['X-Beam-Token' => 'x'])->assertUnauthorized();
    Event::assertNotDispatched(Signal::class);
});

it('refuses a request with no token', function () {
    $user = makeUser();

    $this->actingAs($user)->postJson('/beam/signal', offerPayload())
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'MISSING_TOKEN');
    Event::assertNotDispatched(Signal::class);
});

it('refuses an expired token', function () {
    $user = makeUser();
    $token = tokenFor($user, CHANNEL, ttl: -10);

    $this->actingAs($user)->postJson('/beam/signal', offerPayload(), ['X-Beam-Token' => $token])
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'EXPIRED');
    Event::assertNotDispatched(Signal::class);
});

it('refuses a tampered token', function () {
    $user = makeUser();

    $this->actingAs($user)->postJson('/beam/signal', offerPayload(), ['X-Beam-Token' => 'eyJpdiI6ImZha2UifQ=='])
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'TAMPERED');
    Event::assertNotDispatched(Signal::class);
});

it("refuses another user's token", function () {
    $alice = makeUser('Alice');
    $bob = makeUser('Bob');
    $alicesToken = tokenFor($alice, CHANNEL);

    $this->actingAs($bob)->postJson('/beam/signal', offerPayload(), ['X-Beam-Token' => $alicesToken])
        ->assertForbidden();
    Event::assertNotDispatched(Signal::class);
});

it('refuses a token that names a different channel', function () {
    $user = makeUser();
    $token = tokenFor($user, 'beam.room.other');

    $this->actingAs($user)->postJson('/beam/signal', offerPayload(CHANNEL), ['X-Beam-Token' => $token])
        ->assertForbidden()
        ->assertJsonPath('reason', 'FORBIDDEN');
    Event::assertNotDispatched(Signal::class);
});

it('refuses a valid token whose participant grant is gone', function () {
    $user = makeUser();
    $token = tokenFor($user, CHANNEL, grant: false);

    $this->actingAs($user)->postJson('/beam/signal', offerPayload(), ['X-Beam-Token' => $token])
        ->assertForbidden();
    Event::assertNotDispatched(Signal::class);
});

it('refuses a channel outside the beam. namespace', function () {
    $user = makeUser();
    $token = tokenFor($user, 'chat.general');

    $this->actingAs($user)->postJson('/beam/signal', offerPayload('chat.general'), ['X-Beam-Token' => $token])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channel');
    Event::assertNotDispatched(Signal::class);
});

it('refuses an unknown message type and a malformed offer', function () {
    $user = makeUser();
    $token = tokenFor($user, CHANNEL);

    $this->actingAs($user)->postJson('/beam/signal', ['channel' => CHANNEL, 'message' => ['type' => 'nope']], ['X-Beam-Token' => $token])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message.type');

    $this->actingAs($user)->postJson('/beam/signal', ['channel' => CHANNEL, 'message' => ['type' => 'offer']], ['X-Beam-Token' => $token])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message.sdp');

    Event::assertNotDispatched(Signal::class);
});

it('gzips an offer the broadcaster would refuse for its size, and it inflates to the original byte for byte', function () {
    $user = makeUser();
    $token = tokenFor($user, CHANNEL);

    // What Chromium produces when a screen is shared alongside the camera: two
    // video sections, each carrying the whole codec list. Reverb's default cap is
    // 10,000 bytes and Pusher's 10 KB cannot be raised at all. The rig found this
    // as "Pusher error: Payload too large." and a 500 from this route, with the
    // screen never arriving.
    $sdp = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\n".str_repeat("a=rtpmap:96 VP8/90000\r\na=fmtp:96 max-fs=12288;max-fr=60\r\n", 300)."a=end\r\n";
    expect(strlen($sdp))->toBeGreaterThan(9000);

    $this->actingAs($user)
        ->withMiddleware(TrimStrings::class)
        ->postJson('/beam/signal', offerPayload(overrides: ['message' => ['sdp' => ['sdp' => $sdp]]]), ['X-Beam-Token' => $token])
        ->assertNoContent();

    Event::assertDispatched(Signal::class, function (Signal $s) use ($sdp, $user) {
        expect($s->message['type'])->toBe(Envelope::TYPE)
            // `from` rides outside the envelope, so a third participant's signal is
            // dropped by the client without inflating it.
            ->and($s->message['from'])->toBe($user->getAuthIdentifier())
            ->and(strlen((string) json_encode($s->message)))->toBeLessThan(9000);

        $inner = Envelope::read($s->message['payload']);

        expect($inner['type'])->toBe('offer')
            ->and($inner['from'])->toBe($user->getAuthIdentifier())
            ->and($inner['sdp']['sdp'])->toBe($sdp);   // every CRLF included

        return true;
    });
});

it('leaves a signal the broadcaster can carry exactly as it is, and can be turned off entirely', function () {
    $user = makeUser();
    $token = tokenFor($user, CHANNEL);

    $this->actingAs($user)->postJson('/beam/signal', offerPayload(), ['X-Beam-Token' => $token])->assertNoContent();
    Event::assertDispatched(Signal::class, fn (Signal $s) => $s->message['type'] === 'offer');

    // 0 is for a broadcaster with no cap of its own.
    config()->set('beam.signaling.max_payload', 0);
    $huge = "v=0\r\n".str_repeat("a=x\r\n", 4000);

    $this->actingAs($user)->postJson('/beam/signal', offerPayload(overrides: ['message' => ['sdp' => ['sdp' => $huge]]]), ['X-Beam-Token' => $token])->assertNoContent();

    Event::assertDispatched(Signal::class, fn (Signal $s) => $s->message['type'] === 'offer' && $s->message['sdp']['sdp'] === $huge);
});
