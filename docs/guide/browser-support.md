# <img src="/icons/globe.svg" class="h1-icon" alt=""> Browser Support

Beam runs on WebRTC, which every current browser ships. What differs between
them is the edges — autoplay, screen capture, push, codecs — and Beam keeps
every one of those differences in one file, `platform.ts`, each with the
browser it exists for.

## What the rig proves

The browser test rig (`tests/Browser/`) drives two real browser contexts
through a real Reverb server, fake cameras, and a real coturn relay in a
container. Nothing below is inferred from a compatibility table: each cell is a
spec that ran, in that browser, and what it found.

Chromium runs on every push. Firefox and WebKit are several times slower at the
same specs, and real WebRTC on a shared runner is where flakes come from, so
they run nightly and on demand instead. Every ✓ below is a run that happened;
anything else says what it is, because a cell that quietly means "we did not
look" is worse than an empty one.

| | Chromium | Firefox |
|---|---|---|
| Connect, both signaling transports | ✓ | ✓ |
| Ring → answer / decline / timeout | ✓ | ✓ |
| Mute, camera off, hang up | ✓ | ✓ |
| Switch camera | ✓ | not testable headless: Gecko's fake capture exposes one camera |
| Screen share, replacing the camera and alongside it | ✓ | not testable headless: Gecko has no display capture (`NotAllowedError`) |
| Audio-only call upgraded to video mid-call | ✓ | ✓ |
| Glare (both sides offer at once) | ✓ | ✓ |
| ICE restart, quality, give-up after `reconnect_timeout` | ✓ | ✓ |
| Relay-only through coturn | ✓ | ✓ |
| Recovery after the relay dies | ✓ | ✓ — Gecko does not retry a TURN allocation on its own, which is why Beam re-offers an ICE restart every 5 s while reconnecting |
| Web Push worker, subscription, closed-tab push | ✓ | ✓ |
| File transfer, 20 MB, SHA-256 agreed | ✓ | ✓ |
| Transfer survives the relay dying mid-way | ✓ | **not in 1.0**: run twice here and the call gave up instead of recovering, with a 30 s and then a 90 s `reconnect_timeout`. Gecko recovers a relay death with no transfer in flight (the row above), and Chromium recovers with one; this combination is open |
| jQuery, Vue, React adapters | ✓ | ✓ |
| The screens `beam:install --views` copies | ✓ | ✓ |

Safari has a project in the rig — a WebKit column joins this table from a run
of it, not from a guess, which is why there is no Safari column here yet. The
quirks Beam works around for WebKit are in the table further down, each with the
line of `platform.ts` that applies it; what is not claimed is a green run.

## Real devices

Headless browsers prove the protocol; real hardware proves the edges. Every
row here is a session that actually happened.

| Device | Connects | Screen share | File transfer | Push |
|---|---|---|---|---|
| Linux laptop, Chrome 146, built-in webcam — two sessions on one machine through the Switchboard demo | ✓ | ✓ picked from Chrome's picker; the camera came back on stop | ✓ accepted, downloaded, recorded on the ticket | ✓ a closed-tab ring delivered through FCM and shown; while a tab was open, presence suppressed it, as designed |

Phones are next — an iPhone on the Home Screen for push, an Android on
cellular for the relay — and go here when they have been run, not before.

## The edges, by browser

Every row is a line in `platform.ts`, applied in exactly one place.

| Edge | Browsers | What Beam does |
|---|---|---|
| Autoplay with sound needs a tap | Safari, iOS (every browser there is WebKit); Chromium until the origin has been touched | `play()` runs at once — it succeeds after the answer click. When it is refused, `error` fires with reason `autoplay-blocked` and playback retries on the next tap, click or key. |
| VP8 encodes in software | Safari, iOS | H.264 is put first in the codec preferences; the browser's own order stands elsewhere. |
| A backgrounded tab's sockets are suspended | iOS | On return to the foreground, a dead ICE pair triggers an immediate restart instead of waiting for the disconnect timer — on the polite side too, since the other side's restart was missed while asleep. |
| A relay that comes back is not retried | Firefox | While `reconnecting`, an ICE restart is offered again every 5 s until the pair is back or `reconnect_timeout` gives up. |
| No screen picker | Android, iOS | `shareScreen()` rejects with `screen-unavailable` and a message naming the platform, whatever `getDisplayMedia` says. |
| Several rear lenses | Android, iOS | `switchCamera()` with no id flips `facingMode` (`user`  `environment`) instead of walking the device list. |
| Web Push only for an installed web app | iOS 16.4+ | `platform.supportsPush()` is false in a tab and true once added to the Home Screen; the push page says how to prompt for that. |
| No `setSinkId` | Safari | `call.setSpeaker()` rejects with reason `unsupported`; audio follows the system output. |
| RTT reported on `remote-inbound-rtp` | Firefox | The `quality` event reads it from there when `candidate-pair` has none. |
| Chunks arrive as `Blob` | Firefox | A data channel's default `binaryType` is `blob` there; Beam reads either. |

Ask the same questions yourself:

```ts
import { platform } from '@realrashid/beam';

if (!platform.supportsDisplayMedia()) hideShareButton();
if (platform.isIOS && !platform.supportsPush()) showAddToHomeScreenHint();
```

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
