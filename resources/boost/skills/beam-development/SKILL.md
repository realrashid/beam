---
name: beam-development
description: Use the Beam package to add audio/video calls, screen sharing and peer-to-peer file transfer to Laravel applications, with Blade, jQuery, Livewire, Vue or React frontends.
---

# Beam Development

## When to use this skill

Use this skill when you are:

- adding a one-to-one audio or video call between two users
- creating a room with a stable link, such as a consultation or a support session
- sending a file directly from one browser to another without uploading it
- deciding who may call whom, or who may join a room
- configuring STUN and TURN, or diagnosing a call that will not connect
- wiring the browser client into Blade, jQuery, Livewire, Inertia + Vue or Inertia + React
- making a call ring when the page is closed, through Web Push or a native app's FCM/APNs
- showing or recording call duration
- recording call history from events

## Core usage patterns

- **Builders act only on the terminal method.** `Beam::call($user)` describes; `->ring()` rings. `Beam::room($name)` describes; `->create()` creates. Nothing is broadcast, no token is minted and no gate is checked until then.
- **Hand the browser one token, nothing else.** `$call->token` (for the caller) and `$room->token($user)` (for a participant) contain the call id, the participant's role, the Broadcasting channel, the ICE servers with minted TURN credentials, and a protocol version. The browser needs no other configuration.
- Use `Beam::call()` when a specific person is being rung. Use `Beam::room()` when people arrive at an address.
- `->video()` or `->audio()` chooses the media. `->about('…')` is shown on the incoming-call screen. `->timeout(30)` is how long to ring before `CallDeclined` fires with a timeout reason.
- Every builder has `when()`, `unless()` and `macro()`.

## The token is the boundary

```php
// CORRECT — the browser gets a token and nothing else
$call = Beam::call($customer)->video()->ring();
return view('calls.show', ['token' => $call->token]);
```

```blade
{{-- WRONG — never configure ICE, TURN or channels in JavaScript --}}
<script>
    Beam.join(token, { iceServers: [{ urls: 'turn:…', credential: '{{ $secret }}' }] });
</script>
```

The second example puts a TURN secret in page source. Beam mints per-participant, time-limited credentials on the server and folds them into the token so this is never necessary.

## Authorisation

Define gates before going to production. With none defined, any authenticated user may call any other.

```php
Gate::define('beam.call', fn (User $caller, User $callee) =>
    $caller->isAgent() || $caller->hasOpenTicketWith($callee));

Gate::define('beam.join', fn (User $user, Room $room) => $room->allows($user));
```

`ring()` throws `BeamException` with reason `FORBIDDEN` when a gate refuses. `beam:doctor` warns when no gate exists in production.

## STUN and TURN

- STUN ships working with no configuration, using Google's public servers. It connects most calls.
- TURN relays media when a direct connection is impossible — symmetric NAT, corporate networks, most mobile carriers. Roughly one call in five needs it. Without a TURN driver those calls fail.
- Configure one driver in `config/beam.php` under `ice.turn`: `coturn` (self-hosted, one shared secret), `cloudflare`, `twilio` or `metered`. Switching drivers changes one config line and nothing else.
- When a user reports "the call connects on my laptop but not on my phone", the answer is almost always a missing TURN driver. Point them at `php artisan beam:doctor`, which checks STUN reachability, TURN configuration and whether TURN answers.
- TURN costs bandwidth, and therefore money. The docs say so on the first page; do not present it as optional polish.

## Frontend integration

The browser client is one class, framework-free. The adapters wrap it in each framework's idiom and are each about forty lines.

### Blade with no build step

```blade
@beamAssets

<script>
    Beam.join(@js($token)).then(call => call.attach({ local: '#me', remote: '#them' }));
</script>
```

### jQuery

```js
$.beam.join(token).then(function (call) {
    call.attach({ local: $('#me'), remote: $('#them') });
    $('#mute').on('click', call.mute);
    $(call).on('reconnecting', function () { $('#banner').show(); });
});
```

### Livewire

```php
public function call(): void
{
    $this->dispatch('beam:join', token: Beam::call($this->customer)->video()->ring()->token);
}
```

```blade
@script
<script>
    $wire.on('beam:join', async ({ token }) => {
        const call = await Beam.join(token);
        call.attach({ local: '#me', remote: '#them' });
    });
</script>
@endscript
```

### Inertia + Vue

```vue
<script setup>
import { useBeam } from '@realrashid/beam/vue';
const { local, remote, state, mute, shareScreen, hangUp } = useBeam(props.token);
</script>

<template>
    <video ref="local" autoplay muted playsinline />
    <video ref="remote" autoplay playsinline />
    <p v-if="state === 'reconnecting'">Reconnecting…</p>
</template>
```

### Inertia + React

```jsx
import { useBeam } from '@realrashid/beam/react';

const { local, remote, state, mute, hangUp } = useBeam(token);
```

## Ringing a closed tab

Broadcasting only reaches an open page. `ring()` also sends
`IncomingCallNotification`, and Web Push is the built-in channel.

```php
// .env, generated by beam:install
BEAM_VAPID_PUBLIC=…
BEAM_VAPID_PRIVATE=…
```

```js
// Once per device, from a click. Registers the service worker, stores the subscription.
await Beam.push.subscribe();
```

Tapping **Answer** on the notification answers in an open tab, or opens the app at `$call->link()` (a route named `beam.join`) with `?beam=<token>&answer=1`, where `await Beam.answerFromNotification()` joins. **Decline** declines from the worker. Ignoring it lets `timeout()` fire `CallDeclined`. On iPhone, push exists only for a web app on the Home Screen (a manifest with `display: standalone`; `beam:doctor` checks). The callee model must use `Notifiable`.

For a native mobile app, route the same notification through whatever channel
you already use — it is a standard Laravel Notification:

```php
public function routeNotificationForBeam(): array
{
    return ['webpush', 'fcm'];
}
```

Beam ships nothing native-specific. FCM and APNs come from the channel
packages the application already has.

## Call duration

Measured on the server between `CallAnswered` and `CallEnded`. A browser
cannot inflate it, and it is correct even if one side's tab crashes.

```php
Beam::find($callId)->duration();        // seconds, during and after
Event::listen(CallEnded::class, fn ($e) => $log->update(['seconds' => $e->duration]));
```

```js
call.duration;                           // live, in seconds
call.on('tick', ({ seconds }) => timer.textContent = format(seconds));
```

Do not measure duration in JavaScript. Use the server's figure.

## Receiving calls

`Beam.onIncoming()` listens on the private channel Echo already holds for the authenticated user. Render whatever UI you like; the package ships none.

```js
Beam.onIncoming((incoming) => {
    ringtone.play();
    modal.show(incoming.from.name, incoming.about);
    acceptBtn.onclick  = () => incoming.answer({ video: true });
    declineBtn.onclick = () => incoming.decline();
});
```

## File transfer

Browser to browser over a data channel. The server authorises; the bytes never touch it.

```js
const transfer = Beam.send(file).to(userId);
transfer.on('progress', ({ percent, bytesPerSecond }) => …);
transfer.on('done', () => …);
await transfer.start();
```

```js
Beam.onFileOffered((offer) => {
    if (confirm(`Accept ${offer.name}?`)) {
        offer.accept().then(blob => download(blob, offer.name));
    }
});
```

The recipient must accept. Nothing can be pushed at someone. Transfers resume after a dropped connection.

## Controls and browser quirks

```js
call.toggleMute();  call.cameraOff();  await call.switchCamera();
await call.shareScreen();  await call.stopSharing();
await call.setSpeaker(deviceId);   // rejects with reason 'unsupported' on Safari
call.on('muted' | 'camera' | 'sharing', (on) => …);   // every control fires an event, so the UI follows the call
```

Every browser quirk Beam works around lives in `platform.ts` and is applied once: H.264 first on Safari, `autoplay-blocked` reported and retried on the next tap, an ICE restart when a backgrounded tab returns, `shareScreen()` refused by name on Android and iOS, camera switching by facing on phones. Ask the same questions from an app's UI:

```js
if (!Beam.platform.supportsDisplayMedia()) shareButton.hidden = true;
if (Beam.platform.isIOS && !Beam.platform.supportsPush()) showAddToHomeScreenHint();
```

## Diagnosing

`php artisan beam:doctor` prints eleven lines — Broadcasting, STUN, TURN, the gate, VAPID keys, the service worker, the web app manifest, the client version, HTTPS, client events, the queue — each with what to do, and exits 1 on a fail so it can gate a deploy. When a user reports a call that will not connect, run it first. An application adds its own line with `DoctorCommand::add(MyCheck::class)`.

## Call state and events in the browser

```js
call.state;   // 'ringing' | 'connecting' | 'connected' | 'reconnecting' | 'ended'

call.on('connected',    () => …);
call.on('reconnecting', () => …);   // ICE restart in progress; not a dead call
call.on('quality',      ({ rtt, packetLoss }) => …);
call.on('error',        ({ reason }) => …);   // e.g. 'permission-denied', 'turn-unavailable'
call.on('ended',        ({ reason, duration }) => …);
```

`reconnecting` is normal on a Wi-Fi to mobile handover. Show a banner; do not end the call.

## Recording history

Beam stores nothing. Listen for events and write to your own tables:

```php
Event::listen(CallEnded::class, function (CallEnded $e) {
    CallLog::create([
        'caller_id' => $e->call->caller->id,
        'callee_id' => $e->call->callee->id,
        'seconds'   => $e->duration,
        'ended_by'  => $e->endedBy?->id,   // null when nobody hung up — both browsers vanished
    ]);
});
```

Events: `CallRinging`, `CallAnswered`, `CallDeclined`, `CallEnded`, `ParticipantJoined`, `ParticipantLeft`, `FileOffered`, `FileAccepted`, `FileDeclined`, `FileTransferred`.

## Testing

```php
Beam::fake();

$this->actingAs($agent)->post(route('tickets.call', $ticket));

Beam::assertRang($ticket->customer)->from($agent)->withVideo();
Beam::assertNotRang($stranger);
```

Media cannot be tested in Pest. Do not mock `RTCPeerConnection`. The package repository has a two-browser Playwright rig under `tests/Browser/` that opens two contexts with fake cameras in Chromium, WebKit and Firefox; copy that approach for end-to-end coverage.

## Common mistakes

- **Starting a call on page load.** Browsers require a user gesture to open a camera. Start from a click.
- **Configuring ICE servers in JavaScript.** They are in the token. Anything set client-side is ignored and, if it contains a secret, has leaked it.
- **Treating `reconnecting` as `ended`.** It is a network change being handled. Wait for `connected` or `ended`.
- **Skipping TURN.** Works in testing (two tabs on one network always connect directly), fails for a fifth of customers behind carriers and firewalls. `beam:doctor` will say so.
- **Forgetting `Beam.push.subscribe()`.** Without it a call only rings while the page is open. Call it once per device, from a click.
- **Timing calls in the browser.** The server's `duration` is authoritative; a browser timer stops when the tab dies.
- **Expecting group calls in v1.** A room is one-to-one in v1. Group calls need a media server and arrive in v2 behind the same `Beam::room()` API.
- **Expecting a shipped UI.** There is none. `beam:install --views` copies a starting point into the application.
