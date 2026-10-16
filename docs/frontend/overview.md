# <img src="/icons/layers.svg" class="h1-icon" alt=""> Frontend Overview

Laravel Echo set the pattern: PHP authorises a private channel, the browser subscribes with one line, and every framework wraps that same line in its own idiom. Beam's client is one framework-free class, `@realrashid/beam`, and each adapter is about forty lines around it.

| Frontend | Import | The two lines |
|---|---|---|
| [Blade](/frontend/blade) | `@beamAssets` → `window.Beam`, or `import Beam from '@realrashid/beam'` | `const call = await Beam.join(token); call.attach({ local: '#me', remote: '#them' });` |
| [jQuery](/frontend/jquery) | `@realrashid/beam/jquery` → `$.beam` | `$.beam.join(token).then(call => call.attach({ local: $('#me'), remote: $('#them') }))` |
| [Livewire](/frontend/livewire) | the class, through `$wire.on` | `$wire.on('beam:join', ({ token }) => Beam.join(token).then(…))` |
| [Inertia + Vue](/frontend/vue) | `@realrashid/beam/vue` → `useBeam` | `const { local, remote, state, hangUp } = useBeam(props.token)` |
| [Inertia + React](/frontend/react) | `@realrashid/beam/react` → `useBeam` | `const { local, remote, state, hangUp } = useBeam(token)` |

## Getting the client onto the page

**Vite** — `npm install @realrashid/beam` and import; the package ships ESM with types for the class and each adapter.

**No build step** — `@beamAssets` in `<head>` serves the compiled client from the Composer package (versioned, cached for a year, refetched after an update) and prints the meta tags Beam reads: the signed-in user's id, the route prefix and the CSRF token. It exposes `window.Beam`, installs `$.beam` when jQuery is on the page, and prints no ICE servers and no keys.

Either way Beam needs Laravel Echo on `window.Echo` (what `php artisan install:broadcasting` sets up in `resources/js/echo.js`), or `Beam.configure({ echo })`.

```js
Beam.configure({ echo, userId, csrf, prefix });   // each optional; the defaults come from Echo and the meta tags
```

## The class, in one screen

```js
const call = await Beam.join(token, { media: { video: true, audio: true } });   // media: false for a data-only connection

call.attach({ local: '#me', remote: '#them' });  // a selector, an element, or a jQuery object
call.state;                                      // 'ringing' | 'connecting' | 'connected' | 'reconnecting' | 'ended'
call.on('state' | 'connected' | 'reconnecting' | 'ended' | 'error' | 'quality' | 'tick' | 'muted' | 'camera' | 'sharing' | 'remotetrack', fn);

call.mute(); call.unmute(); call.toggleMute();
call.cameraOff(); call.cameraOn(); await call.switchCamera();
await call.shareScreen(); await call.stopSharing();
await call.setSpeaker(deviceId);
await call.hangUp();

Beam.onIncoming((incoming) => incoming.answer() | incoming.decline());
Beam.send(file).to(userId);  Beam.onFileOffered((offer) => offer.accept() | offer.decline());
await Beam.push.subscribe();
await Beam.answerFromNotification();
Beam.platform;   // isSafari, isIOS, isAndroid, isFirefox, supportsDisplayMedia(), supportsPush(), supportsSetSinkId(), …
```

## Rules that hold in every frontend

- **A call starts from a click.** Browsers refuse to open a camera without a user gesture, and refuse to play sound without one on Safari. Do not `join()` on page load; do it in the handler of the button that was pressed.
- **The remote `<video>` is `autoplay playsinline`; the local one is also `muted`.** Beam sets these on `attach()`, but writing them in the markup avoids a flash.
- **Let go with `call.release()`, not `hangUp()`.** In a component that joins, release on unmount: two mounts of the same token share one call (React's StrictMode makes two), and only the last holder to let go hangs it up. `hangUp()` ends the call for both sides; that belongs on a button. The Vue and React adapters do this for you.
- **Handle `reconnecting`.** It is a banner, not an end; `ended` with reason `connection-lost` comes only after `reconnect_timeout`.
- **The token is the only configuration.** Never pass ICE servers or channel names to the client; they are in the token.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
