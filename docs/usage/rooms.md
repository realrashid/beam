# <img src="/icons/door.svg" class="h1-icon" alt=""> Rooms

A call rings a person. A room is an address: a consultation link in an appointment email, a support session two agents can both join, a class. People arrive when they arrive, and the room is there.

## Creating one

```php
use RealRashid\Beam\Facades\Beam;

$room = Beam::room("consult-{$appointment->id}")
    ->allow($doctor, $patient)        // who may join; users, Participants or ids
    ->video()                         // or ->audio()
    ->screenShare()                   // ->screenShare(false) to forbid it
    ->expiresAt($appointment->ends_at)
    ->create();
```

`create()` records the room in the live registry until it expires (or for `token.ttl` seconds when no expiry is given). Nothing is broadcast: a room waits.

Calling it again is safe, and is the right thing to do: it updates the room's settings and keeps the people already inside, with their arrival times. So the page that opens a room is the best place to create it.

::: tip A room is live state, not a record
The registry is the cache. A room created when an appointment is booked three weeks out is a cache entry for three weeks — and `php artisan cache:clear` in a deploy, a Redis restart, or an eviction under memory pressure takes it with them, after which the link 404s and nothing recreates it.

Keep the appointment in your own table, as you already do, and create the Beam room in the controller that serves the join page. That way the room exists because someone is trying to enter it.
:::

The name is the room's identity and part of its channel name, so it must be URL-safe — letters, digits and `_ . : @ -` — and may not start with `call_`, which is reserved for calls.

## Handing out tokens

Each participant gets their own token, minted for them:

```php
return view('rooms.show', ['token' => $room->token($patient)]);
```

`token()` asks the [`beam.join` gate](/usage/authorization) — or, with no gate defined, the room's own allow-list; an empty list admits anyone authenticated. It then mints a token with the room's channel, the participant's ICE servers and TURN credentials, and the room's media settings.

`$room->link()` returns the application's page for this room when a route named `beam.room` with a `{room}` parameter exists — the thing to put in the appointment email.

## Later

```php
$room = Beam::room("consult-{$appointment->id}")->find();         // as created, or null if it expired
$room = Beam::room("consult-{$appointment->id}")->findOrFail();   // or NOT_FOUND, for a controller

$room->leave($patient);     // revokes their grant; ParticipantLeft fires with how long they were in
$room->close('finished');   // everyone is told, nobody can rejoin
Beam::room($name)->close(); // the same, without finding first
```

## In the browser

A room is joined exactly like a call:

```js
const call = await Beam.join(token);
call.attach({ local: '#me', remote: '#them' });
```

Who offers and who answers is decided on the first `hello` by comparing ids, so two people arriving at once still connect. `ParticipantJoined` fires on your server when someone's first heartbeat arrives, `ParticipantLeft` — with their duration — when they hang up, leave or vanish.

## Two people

A room carries **two people** in v1: one peer connection per browser, and a mesh or an SFU is on the [roadmap](/guide/introduction) rather than in the box. Both halves of that are enforced rather than assumed:

- `token()` refuses a third person while two are live, with `ROOM_FULL` (HTTP 409). It counts who is actually there — heard from within three heartbeats — so reloading your own page, or arriving after someone has left, is not "a third person".
- If one gets through anyway (two people arriving in the same instant, say), the browser binds to whoever speaks first and ignores the rest, rather than letting a stranger's offer renegotiate the pair's connection or their `bye` end the call. The page hears `error` with reason `room-full`.

A forwarded room link is therefore a refusal, not a broken consultation.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
