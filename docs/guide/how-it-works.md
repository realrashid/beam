# <img src="/icons/search.svg" class="h1-icon" alt=""> How It Works

A call is two browsers sending media straight to each other. Laravel's part is small and precise: decide who may call whom, tell the callee, carry a few messages while the browsers find each other, and mint the credentials a relay needs. This page is that part, end to end.

## The model is Laravel Echo

Echo made Broadcasting feel native: a private channel is authorised by PHP, and the browser subscribes with one line. Beam does the same for calls. PHP authorises the call and hands the browser one token; the browser joins with one line.

```php
$call = Beam::call($customer)->video()->ring();

return view('calls.show', ['token' => $call->token]);
```

```js
const call = await Beam.join(token);
call.attach({ local: '#me', remote: '#them' });
```

## The token is the whole browser contract

`$call->token` (and `$room->token($user)`) is an encrypted, signed blob carrying the call id, the participant's role, the Broadcasting channel, the STUN and TURN servers with credentials minted for this participant, an expiry, and a protocol version. The page needs no other configuration — no channel name, no ICE server, no secret. The token authorises joining; after that, being a participant in the live call is what authorises each signal.

The protocol version in the token must match the one the client speaks. They ship together, `beam:doctor` checks the compiled client in the package against PHP, and the script tag `@beamAssets` prints carries a hash of that file — so an update cannot leave a browser on an old client, and a mismatch says so instead of hanging.

## Signaling: what carries the offer

WebRTC needs the two browsers to exchange an offer, an answer and a stream of ICE candidates. Beam carries them over Laravel Broadcasting on a private channel per call, on one of two transports:

- **`controller`** (default) — the browser POSTs each message to `/beam/signal`; PHP checks the sender is a participant in a live call and broadcasts it to the other side. PHP sees every message; a failure is an HTTP status. A signal too large for the broadcaster's event cap — Pusher's 10 KB, which cannot be raised — crosses gzipped and is inflated by the client, because an offer carrying two video tracks is over that cap on its own.
- **`whisper`** — the browser sends a client event on the channel; PHP is not in the path. Lowest latency, but Pusher caps client events at ten per second and drops the rest silently.

Either way, candidates are batched for ~100 ms, and the first offer waits for a `hello` handshake so it is never sent before the other side is subscribed.

## STUN, TURN and why one call in five needs a relay

STUN lets a browser learn its public address; with it, most pairs of browsers connect directly. Behind symmetric NAT, corporate firewalls and most mobile carriers, a direct path does not exist and the media must bounce off a **TURN relay**. That is roughly one call in five — and you will never see one of them while testing, because two browsers on the same network as each other always find a direct path. Beam supports four TURN drivers and mints a per-participant, time-limited credential on the server for each call, so a relay's secret never reaches a browser. [TURN Costs Money](/guide/turn) says what it costs.

## What the browser does

The client owns the `RTCPeerConnection` and everything every hand-rolled implementation gets wrong once:

- **Perfect negotiation** — when both sides offer at once (glare), the polite side yields; the caller is impolite, the callee polite.
- **Candidates before the remote description** are queued and flushed.
- **A network drop is `reconnecting`, never `ended`** — an ICE restart is offered and answered; the call gives up only after `reconnect_timeout`, with reason `connection-lost`. A tab coming back to the foreground with a dead pair restarts at once.
- **A heartbeat** every `heartbeat` seconds keeps the server's registry alive; when both sides stop, the server ends the call and fires `CallEnded` with the duration it measured.
- **A control data channel** is negotiated in the first offer, so file transfers open in-band with no renegotiation.

## What the server keeps, and for how long

Beam stores nothing durable about calls. A cache-held registry knows which calls are live and who is in them, refreshed by the heartbeat, swept by the timeout driver. Call history is yours to record from the [events](/usage/events): `CallRinging`, `CallAnswered`, `CallDeclined`, `CallEnded` (with duration), the room and file events. The only table Beam ships is `beam_push_subscriptions`, and only when `push.store` is `database`.

That registry is the cache, and it is load-bearing: every authorisation after the token reads it — which channel a browser may subscribe to, whether a signal may be relayed, who is in a room. So the store has to be one that keeps things and one that every app server shares:

- **`CACHE_STORE=array` cannot work.** It forgets at the end of the request, so every join is refused with no clue why. `beam:doctor` fails on it.
- **`file` works on one server** and not behind a load balancer: a caller on one node and a callee on another never see each other as participants. The doctor warns.
- **`redis`, `memcached` and `database` are the ones to use in production**, and `php artisan cache:clear` in a deploy ends every call in progress. Live calls recover the way a dropped network does; a room that was created weeks ahead does not, which is why a room is best created by the page that opens it.

## Ringing a phone that is not on the page

`ring()` broadcasts `IncomingCall` to the callee's user channel — that rings every open tab — and, unless a tab is known to be listening, also sends `IncomingCallNotification`: Web Push out of the box (a service worker at `/beam/sw.js`), or whatever channels `routeNotificationForBeam()` names, such as FCM or APNs through your own channel. The notification carries the callee's token; tapping **Answer** opens the app with it and `Beam.answerFromNotification()` joins.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
