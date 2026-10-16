<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RealRashid\Beam\Events\PushSubscribed;
use RealRashid\Beam\Push\Presence;
use RealRashid\Beam\Push\Subscriptions;

it('serves only the public key to a signed-in user, and a clear 503 when push is not configured', function () {
    $this->getJson('/beam/push/public-key')->assertUnauthorized();   // every Beam route sits behind the configured auth
    $user = makeUser();

    $this->actingAs($user)->getJson('/beam/push/public-key')->assertStatus(503)->assertJsonPath('reason', 'PUSH_UNAVAILABLE');

    config()->set('beam.push.vapid', ['public' => 'PUB', 'private' => 'PRIV', 'subject' => 'mailto:x@y.z']);
    $this->actingAs($user)->getJson('/beam/push/public-key')->assertOk()->assertExactJson(['key' => 'PUB']);
    expect($this->actingAs($user)->getJson('/beam/push/public-key')->getContent())->not->toContain('PRIV');
});

it('stores a subscription for the authenticated user and fires PushSubscribed', function () {
    Event::fake([PushSubscribed::class]);
    $user = makeUser();

    $this->actingAs($user)->postJson('/beam/push/subscribe', ['endpoint' => 'https://push.test/e1', 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertNoContent();

    expect(app(Subscriptions::class)->store()->for($user->id))->toHaveCount(1);
    Event::assertDispatched(PushSubscribed::class, fn (PushSubscribed $e) => $e->userId === $user->id && $e->subscription['endpoint'] === 'https://push.test/e1');
});

it('refuses a guest, a bad endpoint, and missing keys', function () {
    $this->postJson('/beam/push/subscribe', ['endpoint' => 'https://push.test/e', 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertUnauthorized();
    $user = makeUser();
    $this->actingAs($user)->postJson('/beam/push/subscribe', ['endpoint' => 'not-a-url', 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    $this->actingAs($user)->postJson('/beam/push/subscribe', ['endpoint' => 'https://push.test/e'])->assertUnprocessable()->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
});

it('removes a subscription by endpoint, only for the caller', function () {
    $alice = makeUser('Alice');
    $bob = makeUser('Bob');
    $store = app(Subscriptions::class)->store();
    $store->add($alice->id, ['endpoint' => 'https://push.test/a', 'keys' => ['p256dh' => 'P', 'auth' => 'A']]);

    $this->actingAs($bob)->deleteJson('/beam/push/subscribe', ['endpoint' => 'https://push.test/a'])->assertNoContent();
    expect($store->for($alice->id))->toHaveCount(1);   // Bob cannot remove Alice's

    $this->actingAs($alice)->deleteJson('/beam/push/subscribe', ['endpoint' => 'https://push.test/a'])->assertNoContent();
    expect($store->for($alice->id))->toBe([]);
});

it('presence: a heartbeat marks the user online for the window', function () {
    $this->postJson('/beam/presence')->assertUnauthorized();   // guest first: actingAs() persists for the rest of the test
    $user = makeUser();
    expect(app(Presence::class)->isOnline($user->id))->toBeFalse();

    $this->actingAs($user)->postJson('/beam/presence')->assertNoContent();

    expect(app(Presence::class)->isOnline($user->id))->toBeTrue();
});

it('refuses a subscription aimed at this network, or at a service the app does not allow', function () {
    $user = makeUser();

    // The URL is handed over by the browser, so it is somewhere an authenticated
    // user gets to point the server: every incoming call POSTs to it.
    foreach ([
        'http://push.test/plain-http',                                        // https only
        'https://169.254.169.254/latest/meta-data/',                          // link-local: cloud metadata
        'https://127.0.0.1/internal',
        'https://10.1.2.3/internal',
        'https://192.168.0.5:8080/internal',
        'https://[::1]/internal',
        'https://localhost/internal',
        'https://redis/internal',                                             // a single label: a service name on a private network
        'https://admin.internal/secrets',
    ] as $endpoint) {
        $this->actingAs($user)->postJson('/beam/push/subscribe', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'P', 'auth' => 'A']])
            ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    }

    expect(app(Subscriptions::class)->store()->for($user->id))->toBe([]);

    // The real services still work, unlisted, which is the default posture.
    foreach (['https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/abc', 'https://web.push.apple.com/abc', 'https://ABC.notify.windows.com/w/abc'] as $endpoint) {
        $this->actingAs($user)->postJson('/beam/push/subscribe', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertNoContent();
    }

    expect(app(Subscriptions::class)->store()->for($user->id))->toHaveCount(4);
});

it('honours an allow-list when the application sets one', function () {
    config()->set('beam.push.endpoints', ['fcm.googleapis.com', '*.notify.windows.com']);
    $user = makeUser();

    $this->actingAs($user)->postJson('/beam/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/a', 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertNoContent();
    $this->actingAs($user)->postJson('/beam/push/subscribe', ['endpoint' => 'https://db5.notify.windows.com/w/a', 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertNoContent();
    $this->actingAs($user)->postJson('/beam/push/subscribe', ['endpoint' => 'https://web.push.apple.com/a', 'keys' => ['p256dh' => 'P', 'auth' => 'A']])
        ->assertUnprocessable()->assertJsonValidationErrors('endpoint');

    expect(app(Subscriptions::class)->store()->for($user->id))->toHaveCount(2);
});

it('still lets a device unsubscribe an endpoint the rules would now refuse', function () {
    $user = makeUser();
    app(Subscriptions::class)->store()->add($user->id, ['endpoint' => 'https://10.0.0.1/left-over', 'keys' => ['p256dh' => 'P', 'auth' => 'A']]);

    $this->actingAs($user)->deleteJson('/beam/push/subscribe', ['endpoint' => 'https://10.0.0.1/left-over'])->assertNoContent();

    expect(app(Subscriptions::class)->store()->for($user->id))->toBe([]);
});
