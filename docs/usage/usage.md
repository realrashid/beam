# <img src="/icons/book.svg" class="h1-icon" alt=""> Usage

Everything in Beam starts from one of two builders and ends in the browser with one line. This page is the map; each section below has its own page.

## The two builders

```php
use RealRashid\Beam\Facades\Beam;

// One person rings another
$call = Beam::call($customer)->video()->about('Order #4821')->ring();

// People join an address
$room = Beam::room("consult-{$appointment->id}")->allow($doctor, $patient)->video()->create();
```

Nothing is sent, checked or minted until the terminal method — `ring()` or `create()`. Both return an object whose `token` is the whole browser contract:

```php
return view('calls.show', ['token' => $call->token]);
return view('rooms.show', ['token' => $room->token($patient)]);
```

The `beam()` helper is the same thing in a function: `beam()` is the manager, `beam($customer)` is `Beam::call($customer)`.

## The browser

```js
import Beam from '@realrashid/beam';   // or window.Beam under @beamAssets

const call = await Beam.join(token);
call.attach({ local: '#me', remote: '#them' });

call.on('state', (state) => badge.textContent = state);   // ringing · connecting · connected · reconnecting · ended
call.on('tick', ({ seconds }) => timer.textContent = seconds);
call.on('ended', ({ reason, duration }) => …);

call.mute();  call.cameraOff();  call.switchCamera();  call.shareScreen();  call.hangUp();
```

Being rung:

```js
Beam.onIncoming((incoming) => {
    modal.show(incoming.from.name, incoming.about);
    answer.onclick  = () => incoming.answer({ media: { audio: true, video: true } });
    decline.onclick = () => incoming.decline();
});
```

A call must begin from a click: browsers refuse to open a camera without a user gesture.

## Where to next

| I want to… | Page |
|---|---|
| ring one person and handle answer, decline, timeout | [Calls](/usage/calls) |
| give people a link to join | [Rooms](/usage/rooms) |
| send a file browser to browser | [File Transfer](/usage/files) |
| decide who may call whom | [Authorization](/usage/authorization) |
| record history, react to answers and hang-ups | [Events](/usage/events) |
| ring a phone whose tab is closed | [Push Notifications](/usage/push) |
| show or store how long a call lasted | [Call Duration](/usage/duration) |
| test without a browser | [Testing](/usage/testing) |
| install, diagnose, sweep | [Artisan Commands](/usage/artisan) |
| know what every error means | [Errors](/usage/errors) |

And the same two lines in every frontend — Blade, jQuery, Livewire, Vue, React — in the [Frontend](/frontend/overview) section.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
