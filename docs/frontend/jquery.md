# <img src="/icons/layers.svg" class="h1-icon" alt=""> jQuery

For the many Laravel applications whose frontend is jQuery and a page of script tags. `@beamAssets` installs `$.beam` when jQuery is already on the page; with a bundler, `import '@realrashid/beam/jquery'` does the same.

```blade
<head>
    <script src="/js/jquery.js"></script>
    @vite(['resources/js/app.js'])   {{-- window.Echo --}}
    @beamAssets                      {{-- window.Beam, and $.beam --}}
</head>

<video id="me" autoplay muted playsinline></video>
<video id="them" autoplay playsinline></video>
<button id="mute">Mute</button>
<button id="hangup">End</button>
<div id="banner" style="display:none">Reconnecting…</div>

<script>
    $(function () {
        $.beam.join(@js($token)).then(function (call) {
            call.attach({ local: $('#me'), remote: $('#them') });

            $('#mute').on('click', function () { call.toggleMute(); });
            $('#hangup').on('click', function () { call.hangUp(); });

            $(call).on('reconnecting', function () { $('#banner').show(); });
            $(call).on('connected',    function () { $('#banner').hide(); });
            $(call).on('ended', function (e, info) { alert('Ended: ' + info.reason); });
        });

        $.beam.onIncoming(function (incoming) {
            $('#incoming-modal').modal('show');
            $('#answer').one('click', function () { incoming.answer({ media: { audio: true, video: true } }); });
            $('#decline').one('click', function () { incoming.decline(); });
        });
    });
</script>
```

`$.beam` is a thin namespace over the class — `join`, `onIncoming`, `onFileOffered`, `send`, `push`, `configure` — with two courtesies: `attach()` accepts jQuery objects, and every call event is also triggered as a jQuery event on the call, so `$(call).on('state', function (e, state) {…})` reads like the rest of the page. The payload comes as the second argument, after the event.

If jQuery is loaded after Beam, or you want to be explicit:

```js
import { install } from '@realrashid/beam/jquery';
install(jQuery);
```

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
