# <img src="/icons/layers.svg" class="h1-icon" alt=""> Inertia + Vue

`useBeam()` is the class in Vue's idiom: join on mount, attach when the `<video>` refs exist, hang up on unmount, and every piece of call state as a ref.

```php
return Inertia::render('Beam/Call', ['token' => $call->token]);
```

```vue
<script setup>
import { useBeam } from '@realrashid/beam/vue';

const props = defineProps({ token: String });

const { local, remote, state, duration, muted, cameraOn, sharing, quality, error,
        toggleMute, enableCamera, disableCamera, switchCamera, shareScreen, stopSharing, setSpeaker, hangUp } = useBeam(props.token);
</script>

<template>
    <video ref="local" autoplay muted playsinline />
    <video ref="remote" autoplay playsinline />

    <p>{{ state }} · {{ duration }}s</p>
    <p v-if="state === 'reconnecting'">Reconnecting…</p>
    <p v-if="error">{{ error.message }}</p>

    <button @click="toggleMute">{{ muted ? 'Unmute' : 'Mute' }}</button>
    <button @click="cameraOn ? disableCamera() : enableCamera()">{{ cameraOn ? 'Camera off' : 'Camera on' }}</button>
    <button @click="shareScreen">Share screen</button>
    <button @click="hangUp()">End</button>
</template>
```

`useBeam(token, options)` takes the same `options` as `Beam.join()`; `token` may be a ref. It returns `call` (a `shallowRef` to the underlying `Call` for anything not wrapped), and the refs `state`, `duration`, `muted`, `cameraOn`, `sharing`, `quality`, `error`.

Two more composables:

```js
import { useIncoming, useFileOffers } from '@realrashid/beam/vue';

useIncoming((incoming) => { /* show a modal; incoming.answer() or incoming.decline() */ });
useFileOffers((offer) => { /* offer.accept() or offer.decline() */ });
```

Each listens for the life of the component and stops on unmount. `php artisan beam:install --views` copies a complete `resources/js/Pages/Beam/Call.vue` when `package.json` has Vue.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
