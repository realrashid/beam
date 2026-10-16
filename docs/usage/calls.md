# <img src="/icons/phone.svg" class="h1-icon" alt=""> Calls

`Beam::call($user)` describes a call to one person; `ring()` makes it happen. Between the two, every chained method is optional and every default is sensible.

## Ringing

```php
use RealRashid\Beam\Facades\Beam;

$call = Beam::call($customer)
    ->video()                       // or ->audio(); video is the default
    ->about("Ticket #{$ticket->id}")  // shown on the incoming-call screen
    ->timeout(30)                   // seconds to ring; config call.default_timeout otherwise
    ->ring();
```

`ring()` does five things, in order: asks the [`beam.call` gate](/usage/authorization) whether the signed-in user may call this person; mints two tokens (the caller's and the callee's) with the ICE servers and any [TURN](/guide/turn) credentials; records the call in the live registry; broadcasts `IncomingCall` on the callee's private user channel so every open tab rings, and sends [`IncomingCallNotification`](/usage/push) unless a tab is known to be listening; and schedules the timeout through the configured driver. Then it fires `CallRinging` and returns the `Call`.

The caller is the authenticated user unless you say otherwise:

```php
Beam::call($customer)->from($agent)->ring();
```

The builder is `Conditionable` and `Macroable`, so `when()`, `unless()` and your own `macro()`s work on it.

## What you get back

```php
$call->id;          // call_01j…  (a ULID with a call_ prefix)
$call->token;       // the caller's packed token — hand it to the browser
$call->caller;      // Participant: id, name, avatar
$call->callee;
$call->media;       // 'audio' | 'video'
$call->about;
$call->timeout;
$call->createdAt;   // unix seconds
```

```php
return view('calls.show', ['token' => $call->token]);
```

`$call->link()` returns the application's page for this call, if a route named `beam.join` with a `{call}` parameter exists — the push notification opens it.

## The callee's side

The callee's browser gets the ring through `Beam.onIncoming()`; the callee's token travels inside the broadcast and the notification, so their page needs nothing from you:

```js
Beam.onIncoming((incoming) => {
    // incoming.callId, incoming.from {id, name, avatar}, incoming.about, incoming.media, incoming.timeout
    answer.onclick  = async () => { const call = await incoming.answer({ media: { audio: true, video: incoming.media === 'video' } }); call.attach({ local: '#me', remote: '#them' }); };
    decline.onclick = () => incoming.decline();
});
```

`answer()` joins and returns the same `Call` the caller has. When the caller's timeout passes with no answer, the ring ends with `CallDeclined` (reason `timeout`) on your server and `ended` (reason `timeout`) in the caller's browser.

## During the call

```js
call.state;                 // 'ringing' | 'connecting' | 'connected' | 'reconnecting' | 'ended'
call.on('connected', () => …);
call.on('reconnecting', () => banner.show());   // a network drop; the call is not over
call.on('quality', ({ rtt, packetLoss }) => …); // every 2 s while connected
call.on('tick', ({ seconds }) => …);            // once a second — for display; the server measures the real duration

call.mute();  call.unmute();  call.toggleMute();     call.isMuted
call.cameraOff();  call.cameraOn();                  call.isCameraOn
await call.enableVideo();           // on a call that started audio-only: open the camera and upgrade to video
await call.switchCamera();          // the next camera; on a phone, the other facing
await call.switchCamera(deviceId);  // a specific one
await call.shareScreen();  await call.stopSharing();  call.isSharing
await call.shareScreen({ alongside: true });   // the screen as a second video track, the camera still flowing
await call.setSpeaker(deviceId);    // where the browser allows it
await call.hangUp();
```

**Audio to video, mid-call.** A call that began with `->audio()` — or was joined with `{ media: { audio: true, video: false } }` — can become a video call: `enableVideo()` opens the camera, adds it to the connection and renegotiates; `cameraOn()` does the same when there is no camera yet. The other side's `remotetrack` fires and its remote `<video>` lights up without anyone rejoining.

**Screen alongside the camera.** `shareScreen()` replaces the camera in the one video track, which every UI already handles. `shareScreen({ alongside: true })` sends the screen as a second track instead, so the other side can show both: it arrives as a `remotetrack` with its own stream, and `attach()` leaves the camera where it was.

```js
const screen = document.querySelector('#their-screen');
call.on('remotetrack', (track, stream) => {
    if (track.kind === 'video' && stream !== call.remoteStream) { screen.srcObject = stream; screen.play(); }
});
```

Every control fires an event when it changes (`muted`, `camera`, `sharing`), so a UI can follow the call rather than the click.

## Ending a call

From the browser, `call.hangUp()`. From the server:

```php
Beam::find($id)?->end();                // null when the call is over; CallEnded fires with the duration
Beam::findOrFail($id)->decline('busy'); // or the exception, when you would rather have one
```

A call whose browsers both vanish — a crash, a closed laptop — ends by itself: each side heartbeats every `call.heartbeat` seconds, and the timeout driver ends a call nothing has heartbeated for three of those intervals (ninety seconds with the defaults). `CallEnded` fires either way, with `reason` `hangup`, `connection-lost`, or whatever was passed to `end()`, and the duration counts to the last heartbeat rather than to the moment the sweeper noticed.

## Rings and transactions

`ring()` broadcasts immediately — `IncomingCall` is `ShouldBroadcastNow`, because a queued offer would add a worker's latency to every step of connecting a call. So a ring inside a transaction has already left, and a rollback cannot take it back:

```php
DB::transaction(function () use ($ticket) {
    $ticket->save();
    Beam::call($ticket->customer)->ring();     // gone, even if this transaction rolls back
});
```

Ring after the commit, or hand it to `DB::afterCommit()`:

```php
DB::transaction(fn () => $ticket->save());
Beam::call($ticket->customer)->about("Ticket #{$ticket->id}")->ring();
```

The same is true of `$room->close()` and `$call->end()`: both tell the browsers at once.

## When something is wrong

`ring()` throws `RealRashid\Beam\Exceptions\BeamException` — `FORBIDDEN` when the gate refuses, `INVALID_CONFIGURATION` when a TURN driver is misconfigured. In the browser, `Beam.join()` rejects with a `BeamError` whose `reason` names the cause (`permission-denied`, `expired`, `protocol-mismatch`, …). Every one is listed on the [Errors](/usage/errors) page.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
