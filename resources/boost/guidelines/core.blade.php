<beam-guidelines>
# Beam

This package adds real-time audio and video calls, screen sharing and peer-to-peer file transfer to Laravel applications. PHP authorises, rings and mints credentials; the browser joins with a single signed token.

## Features

- Calls: `Beam::call($user)` rings a specific person over Laravel Broadcasting; `Beam::room($name)` creates a stable address people join.
- File transfer: browser-to-browser over a WebRTC data channel; nothing touches the server or storage.
- TURN drivers: `coturn`, `cloudflare`, `twilio` and `metered`, each minting per-participant, time-limited credentials.
- Push notifications: `ring()` also sends `IncomingCallNotification`, so a closed tab still rings. Web Push is built in; the application routes it to FCM or APNs through `via()` like any other Notification.
- Call duration: measured on the server between `CallAnswered` and `CallEnded`; available as `$e->duration`, `Beam::find($id)->duration()`, and `call.duration` in the browser.
- One JavaScript class, `@realrashid/beam`, with `vue`, `react` and `jquery` entry points, plus a compiled bundle served by `@@beamAssets` for apps without a build step.
- `beam:doctor`: eleven checks (Broadcasting, STUN, TURN, the gate, VAPID keys, the service worker, the web app manifest, the client version, HTTPS, client events, the queue), each line saying what to do; exit code 1 on a fail. `DoctorCommand::add()` registers an application's own check.

## Usage Rules

- **Nothing is sent until the terminal method.** `Beam::call(...)` and `Beam::room(...)` return builders; `->ring()` and `->create()` are what act.
- **The token is the whole browser contract.** `$call->token` and `$room->token($user)` carry the call id, role, channel, ICE servers and protocol version. Never configure ICE servers, TURN credentials or channel names in JavaScript.
- **Never put a TURN secret in JavaScript or a Blade view.** Credentials are minted server-side and folded into the token. `Redactor` strips them from logs.
- Use `Beam::call($user)` when one person rings another. Use `Beam::room($name)` when people join an address, such as a consultation link.
- Define `Gate::define('beam.call', fn ($caller, $callee) => ...)` and `Gate::define('beam.join', fn ($user, $room) => ...)`. With no gate defined, any authenticated user may call any other; `beam:doctor` warns about this in production.
- `turn.driver => none` is the out-of-the-box default. STUN alone connects most calls; roughly one in five will fail on symmetric NAT or mobile networks until a TURN driver is configured. Say so when a user asks why a call fails to connect.
- Beam stores nothing. Listen for `CallEnded`, `FileTransferred` and the other events to record call history in the application's own tables.
- Beam ships no call UI. `beam:install --views` copies a call screen and an incoming-call modal into the application, after which they belong to the application.
- **A call must begin from a user gesture.** Browsers refuse to open a camera without a click. Do not try to start a call on page load.
- Call `Beam.push.subscribe()` once per device, from a user gesture, so incoming calls ring when the page is closed. `beam:install` generates the VAPID keys; `beam:doctor` checks them.
- Do not measure call duration in the browser. The server measures it between answer and end, so a crashed tab still produces a correct `CallEnded`.
- Every builder supports `when()`, `unless()` and `macro()`.
- Where two mechanisms are reasonable, Beam ships both behind config and the application chooses: `signaling.transport` (`controller`, the default, or `whisper`), `push.store` (`database`, the default, or `cache`), and `call.timeout.driver` (`queue`, the default, `browser` or `scheduler`). Recommend the default; do not present it as the only way.
- `call.timeout.driver => queue` needs a queue worker. On the `sync` queue Beam falls back to the `browser` driver and `beam:doctor` says so.
- The callee must be `Notifiable` (the `Illuminate\Notifications\Notifiable` trait) for a closed tab to ring; without it `ring()` still broadcasts to open tabs and sends nothing else.
- Browser quirks live in `platform.ts` and are applied once: Safari gets H.264 first, a refused autoplay is reported as `autoplay-blocked` and retried on the next tap, a tab returning from the background restarts ICE at once, Android and iOS refuse `shareScreen()` with `screen-unavailable`, phones switch cameras by facing, `setSpeaker()` rejects with `unsupported` where `setSinkId` is missing. `Beam.platform` answers the same questions for an app's own UI (`supportsDisplayMedia()`, `supportsPush()`, `isIOS`, …).
- With Echo loaded from a plain script tag (the IIFE build) rather than through Vite, the class is `Echo.default`.

## Example Usage

@verbatim
<code-snippet name="Ring a customer from a controller" lang="php">
$call = Beam::call($ticket->customer)
    ->video()
    ->about("Ticket #{$ticket->id}")
    ->timeout(30)
    ->ring();

return view('calls.show', ['token' => $call->token]);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Join from the browser" lang="js">
import Beam from '@realrashid/beam';

const call = await Beam.join(token);
call.attach({ local: '#me', remote: '#them' });
call.on('reconnecting', () => banner.show());
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Receive an incoming call" lang="js">
Beam.onIncoming((incoming) => {
    acceptBtn.onclick  = () => incoming.answer({ video: true });
    declineBtn.onclick = () => incoming.decline();
});
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Create a room for an appointment" lang="php">
$room = Beam::room("consult-{$appointment->id}")
    ->allow($doctor, $patient)
    ->video()
    ->screenShare()
    ->expiresAt($appointment->ends_at)
    ->create();

$room->token($patient);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Record call history from an event" lang="php">
Event::listen(CallEnded::class, fn (CallEnded $e) => CallLog::create([
    'caller_id' => $e->call->caller->id,
    'callee_id' => $e->call->callee->id,
    'seconds'   => $e->duration,
]));
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Ring a closed tab through push" lang="js">
// Once per device, from a click — registers the service worker and stores the subscription.
await Beam.push.subscribe();
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Route the incoming-call notification to a native app too" lang="php">
// User model
public function routeNotificationForBeam(): array
{
    return ['webpush', 'fcm'];
}
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Show a live call timer" lang="js">
call.on('tick', ({ seconds }) => timer.textContent = format(seconds));
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Controls, each with an event when it changes" lang="js">
call.toggleMute();            // 'muted'
call.cameraOff();             // 'camera'
await call.enableVideo();     // an audio call becomes a video call; the other side's remote video lights up
await call.switchCamera();    // the next camera; on a phone, the other facing
await call.shareScreen();     // 'sharing'; the camera returns on stopSharing() or the browser's own Stop
await call.shareScreen({ alongside: true });   // the screen as a second track; the other side gets another remotetrack
await call.setSpeaker(id);    // rejects with reason 'unsupported' on Safari
if (!Beam.platform.supportsDisplayMedia()) shareButton.hidden = true;
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Send a file browser to browser" lang="js">
const transfer = Beam.send(file).to(userId);
transfer.on('progress', ({ percent }) => bar.style.width = percent + '%');
await transfer.start();
</code-snippet>
@endverbatim

## Frontends

- Blade with no build step: `@@beamAssets` exposes `window.Beam` (and installs `$.beam` when jQuery is on the page); Echo must be on `window.Echo` — `@@vite(['resources/js/app.js'])` above it, or `Beam.configure({ echo })`.
- jQuery: `import '@realrashid/beam/jquery'` adds `$.beam`; `attach()` accepts jQuery objects.
- Livewire: `$this->dispatch('beam:join', token: $call->token)` and `$wire.on('beam:join', ...)`. No adapter needed.
- Inertia + Vue: `const { local, remote, state, mute, hangUp } = useBeam(props.token)` from `@realrashid/beam/vue`.
- Inertia + React: the same from `@realrashid/beam/react`.

## Testing

- `Beam::fake()` then `Beam::assertRang($user)->from($caller)->withVideo()->about($text)->timeout($seconds)`, `Beam::assertNotRang($user)`, `Beam::assertNothingRang()`, `Beam::assertRoomCreated($name)`. Gates still run under the fake.
- Media cannot be tested without a browser. The repository's `tests/Browser/` rig opens two contexts with fake cameras in Chromium, WebKit and Firefox; point users there rather than attempting to mock `RTCPeerConnection`.

</beam-guidelines>

## A ring cannot be rolled back

`ring()`, `$call->end()` and `$room->close()` broadcast immediately. Inside a
`DB::transaction()` the browsers have already been told, so a rollback leaves a
ring nobody can take back. Ring after the commit, or from `DB::afterCommit()`.
