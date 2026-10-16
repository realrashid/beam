# <img src="/icons/layers.svg" class="h1-icon" alt=""> Inertia + React

`useBeam()` is the class in React's idiom: join in an effect, attach to the refs once the call exists, hang up on cleanup, and every piece of call state as state.

```php
return Inertia::render('Beam/Call', ['token' => $call->token]);
```

```jsx
import { useBeam } from '@realrashid/beam/react';

export default function Call({ token }) {
    const { local, remote, state, duration, muted, cameraOn, sharing, quality, error,
            toggleMute, enableCamera, disableCamera, switchCamera, shareScreen, stopSharing, setSpeaker, hangUp } = useBeam(token);

    return (
        <>
            <video ref={local} autoPlay muted playsInline />
            <video ref={remote} autoPlay playsInline />

            <p>{state} · {duration}s</p>
            {state === 'reconnecting' && <p>Reconnecting…</p>}
            {error && <p>{error.message}</p>}

            <button onClick={toggleMute}>{muted ? 'Unmute' : 'Mute'}</button>
            <button onClick={() => (cameraOn ? disableCamera() : enableCamera())}>{cameraOn ? 'Camera off' : 'Camera on'}</button>
            <button onClick={shareScreen}>Share screen</button>
            <button onClick={() => hangUp()}>End</button>
        </>
    );
}
```

`useBeam(token, options)` takes the same `options` as `Beam.join()`. A new `token` is a new call; the previous one is hung up. It returns `call` (the underlying `Call` for anything not wrapped) beside the state values, and the control functions are stable across renders.

Two more hooks:

```jsx
import { useIncoming, useFileOffers } from '@realrashid/beam/react';

useIncoming((incoming) => { /* show a modal; incoming.answer() or incoming.decline() */ });
useFileOffers((offer) => { /* offer.accept() or offer.decline() */ });
```

Each listens while the component is mounted, always calling the latest handler you passed. `php artisan beam:install --views` copies a complete `resources/js/Pages/Beam/Call.jsx` when `package.json` has React.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
