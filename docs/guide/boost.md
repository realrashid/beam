# <img src="/icons/layers.svg" class="h1-icon" alt=""> Laravel Boost

[Laravel Boost](https://github.com/laravel/boost) gives AI coding assistants accurate, project-specific context. Beam ships its own guideline and skill, so an assistant working in your codebase writes the calling code the way this package expects it — a token handed to the browser, a gate before a ring, events for history — instead of reaching for a WebRTC tutorial and wiring `RTCPeerConnection` by hand.

## What ships

| File | What it does |
|---|---|
| `resources/boost/guidelines/core.blade.php` | The package guideline Boost injects into the assistant's context: the two builders, the token as the only browser contract, the gates, the config choices and their defaults, every control and event, the common mistakes |
| `resources/boost/skills/beam-development/SKILL.md` | A skill for building call and file features — ringing from a controller, receiving in every frontend, rooms, push, duration, history, testing, and what to say when a call will not connect |

You do not install them separately. Boost discovers guidelines and skills from the packages already in your `composer.json`:

```bash
php artisan boost:install
```

## Why it matters here

WebRTC has a long tail of things that work on a developer's machine and fail for a customer, and an assistant trained on the open web has seen all of them done wrong. The guideline says, in one place, what Beam has already decided:

- **The token is the boundary.** Never configure ICE servers, TURN credentials or channel names in JavaScript; they are in `$call->token`. An assistant that puts a relay secret in a Blade view has leaked it.
- **A call starts from a click.** Browsers refuse to open a camera without a user gesture; a `join()` on page load fails for everyone.
- **`reconnecting` is not `ended`.** A dropped Wi-Fi is a banner, not a hang-up.
- **TURN costs money, and one call in five needs it.** When a user reports "connects on my laptop, not on my phone", the answer is a missing relay, and `beam:doctor` says so.
- **Beam stores nothing.** Call history comes from `CallEnded` and the other events, into the application's own tables.
- **Where Beam offers a choice — signaling transport, push store, timeout driver, TURN driver — the guideline names every option and the default**, so the assistant recommends rather than assumes.

## A note on editing the guideline

`core.blade.php` is compiled by Blade before it reaches the assistant, so anything Blade recognises inside an example has to be escaped — directives written as `@@beamAssets` and `@@vite`, and code samples wrapped in `@verbatim`. An unescaped directive is *executed*: this guideline once shipped a real `<script>` tag for the compiled client because `@beamAssets` appeared in a bullet.

The package's test suite renders the guideline the way Boost does and asserts the output is prose — no script tags, no executed directives, no leaked PHP — and that every `Beam::` and `Beam.` method the guideline and skill name exists on the manager and the client. It cannot regress unnoticed.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
