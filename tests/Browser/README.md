# The browser rig

Two real browser contexts — two people — connected through a real Reverb server,
fake cameras, and a real coturn relay, with Playwright asserting frames, signals
and file bytes between them. This is what proves the package does what the docs say.

## What is in this directory

- `*.spec.ts` — the specs: connect, ring, controls, screenshare, glare, reconnect, turn, push, transfer, adapters, stubs, screenshots.
- `fixtures.ts` — `twoPeers()`, `waitForRemoteVideo()` (asserts frames, not elements).
- `relay.ts` — the TURN relay for relay-only specs: coturn in a container (Docker or Podman), node-turn as a video-only fallback.
- `rig/` — the **only** part of the rig's Laravel app that is ours: the routes, the pages the specs drive, a bootstrap patch (CSRF off for the test routes), `.env.rig`. Fourteen files.
- `rig.sh` — builds the actual Laravel app from a fresh skeleton plus `rig/`, **outside this package**: at `../beam-rig`, or `$BEAM_RIG_DIR`. That app is never committed here — it is `laravel new` plus this overlay, reproducible on any machine and in CI.
- `rig-assets.mjs` — fills the app's `public/vendor/` (Pusher, Echo, jQuery, Vue, a React ESM bundle, links to `beam.js` and `dist/`) from this package's `node_modules`.
- `server.php` — Playwright's web server: Reverb on `:8091` and the app on `:8090`.

## Running it

```bash
npm ci && npm run build
npm run rig:setup                  # once: creates ../beam-rig (or $BEAM_RIG_DIR)
npx playwright install --with-deps chromium
npx playwright test --project=chromium
```

`--project=firefox` and `--project=webkit` pick another browser; a spec name filters (`npx playwright test --project=chromium connect`).
The relay-only specs start coturn themselves through Docker or Podman; without either, node-turn stands in and the specs that push data through the relay skip with a reason.

The rig runs one worker on purpose: real WebRTC between real browsers on one machine shows a second worker's load as timeouts.

## Why the app is not in the repository

`composer require realrashid/beam` must hand a developer a package, not a second application. The Composer archive already excludes `tests/`; keeping the app out of the repository as well keeps the repository honest about what it is. `rig.sh` is the app.

## After you edit the overlay

`tests/Browser/rig/` is the source of truth, and `rig.sh` copies it into the app.
Editing a file there does **not** change the app that is already built — so:

```bash
npm run rig:sync      # copy the overlay over the built app
```

Skip it and the specs run against the old copy. That is a confusing failure: the
package is right, the app is stale, and the error looks like a product bug. (It
cost an hour once — the rig's event log was still reading `$e->file['name']`
after the events started carrying a `Data\File`.)
