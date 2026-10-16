# <img src="/icons/check.svg" class="h1-icon" alt=""> Testing

Two halves. PHP — who rang whom, with what — is tested with a fake and no browser. Media — that two browsers connect and frames flow — cannot be faked and is tested in the repository's two-browser rig, which you can also run against your own app.

## `Beam::fake()`

```php
use RealRashid\Beam\Facades\Beam;

it('rings the customer when the ticket is escalated', function () {
    Beam::fake();

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/call")->assertOk();

    Beam::assertRang($ticket->customer)
        ->from($agent)
        ->withVideo()
        ->about("Ticket #{$ticket->id}")
        ->timeout(30);
});
```

With the fake in place, `ring()` and `create()` still return a real `Call` or `Room` with a real token — your view gets what it would in production — but nothing is broadcast, no notification is sent, no gate is skipped, and nothing is scheduled.

```php
Beam::assertRang($user);          // returns an assertion you can refine: from(), withVideo(), withAudio(), about(), timeout()
Beam::assertNotRang($user);
Beam::assertNothingRang();
Beam::assertRoomCreated('consult-42');
```

Gates still run under the fake, so a test that expects `FORBIDDEN` gets it.

## Events and notifications

The rest is Laravel's own fakes:

```php
Event::fake([CallEnded::class]);
Notification::fake();

// … ring, answer, end through your own routes …

Event::assertDispatched(CallEnded::class, fn ($e) => $e->duration > 0);
Notification::assertSentTo($customer, IncomingCallNotification::class);
```

## HTTP

The signal relay and call control are plain routes. To exercise them, mint a token with the fake and post to them as the participant; `RealRashid\Beam\Data\Token::parse()` is public if you want to inspect one.

## Real browsers

The repository's `tests/Browser/` is a Playwright rig: two browser contexts, fake cameras, a real Reverb server, a real coturn relay in a container, and specs for everything on the [Browser Support](/guide/browser-support) page — connect, ring, controls, screen share, glare, reconnect, TURN, push, transfer, the adapters and the copied stubs. Clone the repository and:

```bash
npm ci && npm run build
npm run rig:setup                       # builds the rig's Laravel app beside the package, from a fresh skeleton
npx playwright install --with-deps chromium
npx playwright test --project=chromium
```

Do not try to mock `RTCPeerConnection` in a unit test; it proves nothing about a call. Point a browser at it.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
