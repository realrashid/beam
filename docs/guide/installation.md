# <img src="/icons/rocket.svg" class="h1-icon" alt=""> Installation Guide

Five steps from `composer require` to a first call: install, set up Broadcasting, publish, put the assets on the page, and let `beam:doctor` tell you what is left.

## Requirements

- PHP 8.2 or higher
- Laravel 12.61 or 13.12 and up — those minimums are the lowest release of each line with no published security advisory, which is also what Beam's own test matrix resolves to
- Laravel Broadcasting with Reverb, Pusher or Ably — a call's offer and answer travel over it
- HTTPS in production (browsers refuse the camera on plain http away from localhost)

## Step 1: Composer Magic

```bash
composer require realrashid/beam
```

The package uses Laravel's auto-discovery, so the service provider and the `Beam` facade are registered automatically.

## Step 2: Broadcasting

If the application does not broadcast yet:

```bash
php artisan install:broadcasting
```

That installs Reverb and writes `resources/js/echo.js`. Beam signals over whatever Broadcasting you use; a `log` or `null` driver means no call can connect, and `beam:doctor` says so.

## Step 3: Set Up the Foundation

```bash
php artisan beam:install
php artisan migrate
```

`beam:install` publishes `config/beam.php`, publishes the `beam_push_subscriptions` migration (when `push.store` is `database`, the default), and writes a VAPID key pair for Web Push into `.env` — once; it never overwrites keys without `--force`.

Want a working call screen and an incoming-call modal to start from?

```bash
php artisan beam:install --views
```

They land in `resources/views/beam/` (plus `Call.vue` or `Call.jsx` under `resources/js/Pages/Beam/` when `package.json` has Vue or React) and belong to you from then on. See [Headless by Design](/guide/headless).

## Step 4: Include the Assets

Beam's client is one framework-free class, published to npm as `@realrashid/beam` and also compiled into the Composer package for apps without a build step. Pick one.

**With Vite**

```bash
npm install @realrashid/beam
```

```js
import Beam from '@realrashid/beam';          // or '@realrashid/beam/vue', '/react', '/jquery'
```

**Without a build step**

```blade
<head>
    @vite(['resources/js/app.js'])   {{-- sets up window.Echo --}}
    @beamAssets
</head>
```

`@beamAssets` prints the meta tags Beam reads (the signed-in user's id, the route prefix, the CSRF token) and a versioned `<script>` for the compiled client, which exposes `window.Beam`. It prints no ICE servers and no keys — those travel in the token.

## Step 5: Ask the Doctor

```bash
php artisan beam:doctor
```

One line per thing that can be wrong — Broadcasting, STUN, TURN, the gate, VAPID keys, the service worker, the web app manifest, the client version, HTTPS, client events, the queue — each saying what to do next. On a fresh install expect a warning about TURN: [it costs money](/guide/turn), and you decide when.

## Step 6: A First Call

```php
use RealRashid\Beam\Facades\Beam;

Route::post('/call/{user}', function (User $user) {
    $call = Beam::call($user)->video()->ring();

    return view('beam.call', ['token' => $call->token]);
})->middleware('auth');
```

Open the page in one browser, the callee's dashboard (with the incoming modal included) in another, and press call. Everything from here — rooms, files, push, events, testing — is in [Usage](/usage/usage).

Happy coding!

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
