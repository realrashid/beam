# <img src="/icons/lock.svg" class="h1-icon" alt=""> Authorization

Two ordinary Laravel gates decide everything: who may call whom, and who may enter a room. Beam never decides for you — and with no gate defined, any authenticated user may call any other, which is right on a laptop and wrong in production. `beam:doctor` warns about it; in production it fails.

## `beam.call`

```php
use Illuminate\Support\Facades\Gate;

Gate::define('beam.call', fn (User $caller, User $callee) =>
    $caller->isAgent() || $caller->hasOpenTicketWith($callee));
```

Checked by `ring()` — and by `POST /beam/transfers`, the data-only connection a file transfer outside a call asks for. A refusal throws `BeamException` with reason `FORBIDDEN` (a 403 on the transfer route), before anything is broadcast or minted.

## `beam.join`

```php
use RealRashid\Beam\Room;

Gate::define('beam.join', fn (User $user, Room $room) => $room->allows($user) || $user->isAdmin());
```

Checked by `$room->token($user)`. With no `beam.join` gate, the room's own allow-list decides — `->allow($doctor, $patient)` — and an empty list admits anyone authenticated. Defining the gate replaces that rule rather than adding to it, so call `$room->allows($user)` yourself when you want both.

## After the token

The token authorises *joining*. Once a browser has joined, every signal it sends is checked against the live registry: the token must name the channel, and the user must be a participant of that call right now. Ending, declining, timing out a call and offering a file all pass through the same check. So a leaked token gives someone at most the ability to join a call they were already a party to, until `token.ttl` runs out.

A token is encrypted with your app key and carries the user id it was minted for; one presented by a different signed-in user is refused with `403 FORBIDDEN`, a tampered one with `401 TAMPERED`, an old one with `401 EXPIRED`.

## The routes

Everything under the prefix sits behind `routes.middleware` (`['web', 'auth']` by default) and `routes.throttle`. Two routes are public by design: the compiled client and the push service worker, which a browser fetches before anyone is signed in. Neither carries anything but code.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
