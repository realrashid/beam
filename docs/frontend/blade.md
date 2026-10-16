# <img src="/icons/layers.svg" class="h1-icon" alt=""> Blade

The plainest way, and the one most Laravel applications in the wild will use: a route returns a token, a view puts it in a script, and the compiled client does the rest.

## The route

```php
use RealRashid\Beam\Facades\Beam;

Route::post('/tickets/{ticket}/call', function (Ticket $ticket) {
    $call = Beam::call($ticket->customer)->video()->about("Ticket #{$ticket->id}")->ring();

    return view('calls.show', ['token' => $call->token]);
})->middleware(['auth', 'can:call,ticket']);
```

## The view, with Vite

```blade
<video id="me" autoplay muted playsinline></video>
<video id="them" autoplay playsinline></video>
<button id="hangup">End</button>

<script type="module">
    import Beam from '@realrashid/beam';

    const call = await Beam.join(@js($token));
    call.attach({ local: '#me', remote: '#them' });
    hangup.onclick = () => call.hangUp();
</script>
```

## The view, with no build step

`@beamAssets` serves the compiled client from the Composer package and exposes `window.Beam`; your `app.js` (or whatever `install:broadcasting` wrote) puts Echo on `window.Echo`.

```blade
<head>
    @vite(['resources/js/app.js'])
    @beamAssets
</head>

<video id="me" autoplay muted playsinline></video>
<video id="them" autoplay playsinline></video>
<p>state: <span id="state">connecting</span></p>
<button id="hangup">End</button>

<script>
    Beam.join(@js($token)).then((call) => {
        call.attach({ local: '#me', remote: '#them' });
        call.on('state', (s) => state.textContent = s);
        hangup.onclick = () => call.hangUp();
    });
</script>
```

If you load Echo as an IIFE `<script>` rather than through Vite, the class is `Echo.default`:

```html
<script>window.Echo = new Echo.default({ broadcaster: 'reverb', key: '…', wsHost: '…', wsPort: 8080, forceTLS: false, enabledTransports: ['ws', 'wss'] });</script>
```

## Being rung

On any page the user keeps open — a layout, a dashboard:

```blade
<div id="incoming" hidden>
    <p><b id="from"></b> is calling — <span id="about"></span></p>
    <button id="answer">Answer</button>
    <button id="decline">Decline</button>
</div>

<script>
    Beam.onIncoming((incoming) => {
        from.textContent = incoming.from.name;
        about.textContent = incoming.about ?? '';
        document.getElementById('incoming').hidden = false;
        answer.onclick = async () => { const call = await incoming.answer({ media: { audio: true, video: true } }); call.attach({ local: '#me', remote: '#them' }); };
        decline.onclick = () => incoming.decline();
    });
</script>
```

`php artisan beam:install --views` copies a complete, styled version of both screens into `resources/views/beam/` to start from.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
