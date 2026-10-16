# <img src="/icons/clock.svg" class="h1-icon" alt=""> Call Duration

How long a call lasted is measured on the server, between `CallAnswered` and `CallEnded`, so a crashed tab, a closed laptop or a phone that lost signal still produces a correct number. The browser's timer is for display.

## Where it appears

```php
Event::listen(CallEnded::class, fn (CallEnded $e) => $e->duration);   // seconds, or null if never answered

Beam::find($id)?->duration();    // of a live call, so far; null before it is answered

$e->duration;                    // ParticipantLeft — how long that person was in the room
```

## How it is measured

Each browser posts a heartbeat every `call.heartbeat` seconds (30 by default) with its state. The first heartbeat saying `connected` stamps `answeredAt` and fires `CallAnswered` — once, whichever side reports first. `hangUp()` from either browser, or `end()` on the server, stamps `endedAt` and fires `CallEnded` with the difference.

When neither browser can report — both closed, both offline — the timeout driver notices: a call nothing has heartbeated for three heartbeat intervals (ninety seconds with the defaults) is ended with reason `connection-lost`, and `endedAt` is **the last heartbeat received**, not the moment the sweep ran. That is the honest figure: the ninety seconds of silence it takes to establish that a call is gone are not talking time, and one heartbeat interval is the most it can be off by.

## In the browser

```js
call.on('tick', ({ seconds }) => timer.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`);
call.duration;   // the same number, on demand
```

`useBeam()` in Vue and React exposes it as `duration`. Show it; store the server's.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
