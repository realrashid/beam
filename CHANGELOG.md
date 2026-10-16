# Changelog

All notable changes to `Beam` will be documented in this file.

## v1.0.0 - 2026-10-17

### [Initial Release]

#### Added

- `Beam::call($user)` — a fluent builder for ringing one person: `audio()`, `video()`, `files()`, `about()`, `timeout()`, `from()`, `meta()`, then `ring()`. `Conditionable` and `Macroable`.
- `Beam::room($name)` — a stable address people join: `allow()`, `audio()`, `video()`, `screenShare()`, `expiresAt()`, then `create()`; `find()` and `close()` later; `$room->token($user)`, `leave()`, `link()`.
- One signed, encrypted token per participant carrying the call id, role, channel, ICE servers with minted TURN credentials, expiry and protocol version — the whole browser contract.
- Two signaling transports behind `signaling.transport`: `controller` (browser → PHP → Broadcasting; the default, because its failure is visible) and `whisper` (client events).
- Four TURN drivers behind `ice.turn.driver` — `coturn` (HMAC `use-auth-secret`, minted locally), `cloudflare`, `twilio` and `metered` — plus `Beam::extendTurn()` for your own. `TurnUnavailable` fires and the call proceeds on STUN when a vendor fails.
- Three timeout drivers behind `call.timeout.driver`: `queue` (a delayed job; the default), `browser` and `scheduler` (`beam:sweep`). A heartbeat and a cache-held registry end a call whose browsers both died.
- Web Push out of the box: VAPID keys from `beam:install`, a service worker at `/beam/sw.js` with **Answer** and **Decline** on the notification, `Beam.push.subscribe()`, `Beam.answerFromNotification()`, presence suppression so an open tab is rung by Broadcasting alone, and two subscription stores behind `push.store` (`database`, the default, and `cache`). `IncomingCallNotification` routes to any channel through `routeNotificationForBeam()`.
- Peer-to-peer file transfer: `Beam.send(file).to(userId)` and `Beam.onFileOffered()`; 16 KiB chunks with back-pressure over a data channel negotiated in the first offer, resume after a drop, holes re-requested by name, SHA-256 agreed by both sides; a data-only connection when there is no call (`POST /beam/transfers`); `transfer.max_bytes`.
- Audio-to-video upgrade mid-call (`call.enableVideo()`, or `cameraOn()` on a call without a camera), and screen sharing either replacing the camera or alongside it (`shareScreen({ alongside: true })`) — a second video track the other side receives as its own `remotetrack`.
- The browser client `@realrashid/beam`: `Beam.join()`, `Call` with a state machine (`ringing` · `connecting` · `connected` · `reconnecting` · `ended`), perfect negotiation, queued candidates, ICE restart with `reconnect_timeout`, quality events, `attach()` for selectors, elements or jQuery objects, and controls — `mute`, `cameraOff`, `switchCamera`, `shareScreen`, `setSpeaker`, `hangUp` — each with an event.
- Adapters: `@realrashid/beam/vue` (`useBeam`, `useIncoming`, `useFileOffers`), `@realrashid/beam/react` (the same), `@realrashid/beam/jquery` (`$.beam`, events bridged onto `$(call).on()`), and a compiled IIFE served by `@beamAssets` for apps with no build step.
- `platform.ts`: every browser quirk in one place with the browser it exists for — H.264 first on Safari, `autoplay-blocked` retried on the next tap, an immediate ICE restart when a backgrounded tab returns, screen share refused by name on Android and iOS, camera switching by facing on phones, `unsupported` for `setSpeaker()` without `setSinkId`. `Beam.platform` for your own UI.
- Server-measured call duration on `CallEnded`, `Beam::find($id)->duration()` and `ParticipantLeft`.
- Events: `CallRinging`, `CallAnswered`, `CallDeclined`, `CallEnded`, `ParticipantJoined`, `ParticipantLeft`, `FileOffered`, `FileAccepted`, `FileDeclined`, `FileTransferred`, `PushSubscribed`, `TurnUnavailable`.
- Two gates, `beam.call` and `beam.join`; participant checks on every signal after the token; `Redactor` keeps credentials out of logs. `BeamException` renders inside a request as JSON with the status its reason implies (403, 401, 404, 503).
- `Beam.onIncoming()` waits for Echo to appear — a Vite bundle is a deferred module, so an inline script registering it runs first.
- `beam:install` (config, migration, VAPID keys once; `--views` copies a call screen, an incoming-call modal, and a Vue or React page when `package.json` has them), `beam:doctor` (eleven checks with a fix on every line and exit code 1 on a fail; `DoctorCommand::add()`), `beam:sweep`.
- `Beam::fake()` with `assertRang()->from()->withVideo()->about()->timeout()`, `assertNotRang()`, `assertNothingRang()`, `assertRoomCreated()`.
- Laravel Boost guideline and skill, guarded by a test that renders them through Blade and checks every method they name exists.
- A two-browser Playwright rig — fake cameras, a real Reverb server, real coturn in a container — proving connect, ring, controls, screen share, glare, reconnect, TURN, push, transfer, the adapters and the copied stubs in Chromium and Firefox (WebKit in CI).
- Documentation with every screenshot captured by the rig and a test that keeps every code sample and error table in step with the code.

#### Notes

- Requires PHP 8.2 or higher and Laravel 12.61 / 13.12 or higher; Laravel Broadcasting with Reverb, Pusher or Ably.
- Beam stores nothing about calls. Record history from the events. The only table is `beam_push_subscriptions`, and only when `push.store` is `database`.
- Headless: no call UI ships. `beam:install --views` copies one that is yours.
- **TURN costs money.** STUN alone connects most calls; roughly one in five needs a relay, and a relay bills by the gigabyte. Configure a driver before going live.
- A call starts from a click. Browsers refuse to open a camera without a user gesture.
- The protocol version in the token must match the client's; they ship together, and `beam:doctor` checks the served `beam.js`.
- Rooms are one-to-one in v1: the client holds one peer connection.
- Through a relay or while video is on, a file transfer is paced by the browser's bandwidth estimator and ramps over the first minute.

#### Conventions

- `php artisan about` reports Beam's transport, TURN driver, timeout driver, push store, registry cache and client version.
- `lang/en/beam.php` holds every string the push notification shows; `vendor:publish --tag=beam-lang` to change the wording or the language.
- Publish tags: `beam-config`, `beam-migrations`, `beam-lang`, `beam-views`. The migration is loaded from the package, so `migrate` needs no publish — `beam:install --migrations` is for editing it.
- `TurnDriver`, `TimeoutDriver` and `PushStore` are bound in the container, so an application can typehint the contract.
- `Beam::extendTurn()` and `extendTimeout()` take a closure receiving the container and that driver's config block, as `Storage::extend()` does; a class string still works.
- `beam.ice.turn` is `default` + `cache` + `drivers`, laid out like `filesystems.disks`.
- `find()` returns null and `findOrFail()` throws, on both `Beam::find()` and `Beam::room()->find()`.
- `CallEnded` carries `$by`, the same name `CallAnswered` and `CallDeclined` use.
- The file events carry a `Data\File` (`name`, `size`, `mime`, `forHumans()`).
- In the browser, `call.release()` lets a component let go of a call; the last holder hangs it up.
