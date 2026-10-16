# <img src="/icons/settings.svg" class="h1-icon" alt=""> Configuration

`php artisan beam:install` publishes `config/beam.php`. Every key below has a default that works on a laptop; the ones you will touch before production are the TURN driver and the Web Push keys.

## The principle behind every key

Where there is more than one reasonable way to do something, Beam ships every reasonable way behind a config key and you choose. Nothing is forced. Each key names its options, says which is the default and why, and what the others cost.

```php
'signaling' => ['transport' => env('BEAM_SIGNALING', 'controller')],   // controller | whisper
'push'      => ['store'     => env('BEAM_PUSH_STORE', 'database')],    // database | cache
'call'      => ['timeout'   => ['driver' => env('BEAM_TIMEOUT', 'queue')]],   // queue | browser | scheduler
'ice'       => ['turn'      => ['default' => env('BEAM_TURN', 'none')]],      // none | coturn | cloudflare | twilio | metered
```

## Routes

```php
'routes' => [
    'prefix' => 'beam',
    'middleware' => ['web', 'auth'],
    'asset_middleware' => [],
    'throttle' => '240,1',
],
```

Every route Beam registers lives under the prefix: the signal relay, call control (heartbeat, decline, end, timeout), file-transfer offers, the push subscription endpoints and presence. All of those need a signed-in user, so `middleware` is where your guard goes — `['web', 'auth']`, or `['web', 'auth:sanctum']`, or whatever your application already uses.

`throttle` covers all of them together — signals, heartbeats, presence — per user. It is set where a bad minute cannot reach it: an ICE restart every five seconds with a batch of candidates after each is the busiest Beam ever gets, and that is exactly the minute when refusing a signal would keep a call from recovering.

Two routes are public on purpose and have their own key: `beam.js` (the compiled client) and `sw.js` (the push service worker). A page may need the client before anyone is signed in — a login screen can ring — and the browser fetches the worker with no session at all, so `asset_middleware` is empty by default. Put your own middleware there if your edge needs one, and leave any `auth` out of it: a browser asking for JavaScript and getting a login redirect fails with nothing in the console to explain it.

## Token

```php
'token' => ['ttl' => 3600],
```

The token authorises *joining*, nothing after. This bounds how long a ring or a room link stays joinable; once joined, being a participant in the live call is what authorises signals, so an expiring token never drops a call. A room for a later appointment uses `expiresAt()` on the room instead.

## Signaling

```php
'signaling' => ['transport' => env('BEAM_SIGNALING', 'controller')],
```

| | `controller` (default) | `whisper` |
|---|---|---|
| Path | browser → PHP → Broadcasting → browser | browser → Broadcasting → browser |
| Failure | an HTTP status the browser reacts to | silent |
| Caps | the broadcaster's 10 KB event cap, handled: PHP gzips anything over `max_payload` and the client inflates it | Pusher: client events must be enabled, 10 per second per connection, excess dropped without telling the sender; a message near 10 KB goes through the controller instead |
| Cost | one extra hop, tens of milliseconds | lowest latency |

`max_payload` (9000 bytes) is the size above which a relayed signal is gzipped rather than handed to a broadcaster that would refuse it. Pusher caps an event at 10 KB and will not raise it; Reverb ships the same default. That cap is not theoretical: sharing a screen alongside the camera makes Chromium repeat its whole video codec list, and the 12 KB offer that comes out was answered with "Payload too large" and a 500 until Beam started compressing it. Set it to 0 if your broadcaster has no cap.

ICE gathering emits ten to forty candidates in its first second, so on either transport Beam coalesces them for ~100 ms and sends them in batches. `whisper` is the option, not the default, because its failure mode is a call that connects fine in testing and fails on a customer's phone with no error anywhere. [How It Works](/guide/how-it-works) has the detail.

## ICE — STUN and TURN

```php
'ice' => [
    'stun' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'],
    'turn' => [
        'default' => env('BEAM_TURN', 'none'),
        'cache'   => env('BEAM_TURN_CACHE', true),
        'drivers' => [
            'coturn'     => ['urls' => [/* COTURN_URLS, comma-separated */], 'secret' => env('COTURN_SECRET'), 'ttl' => 3600],
            'cloudflare' => ['key_id' => env('CF_TURN_KEY_ID'), 'token' => env('CF_TURN_API_TOKEN'), 'ttl' => 3600],
            'twilio'     => ['sid' => env('TWILIO_SID'), 'token' => env('TWILIO_TOKEN'), 'ttl' => 3600],
            'metered'    => ['app' => env('METERED_APP'), 'api_key' => env('METERED_KEY'), 'region' => env('METERED_REGION')],
        ],
    ],
],
```

`default` picks the driver and `drivers` holds each one's options — the same shape as `filesystems.disks` and `cache.stores`, so a driver you add lands in the same place as the four that ship. `cache` keeps a minted credential for a quarter of its life rather than asking the vendor on every ring.

STUN works with no setup and connects most calls. Roughly one in five cannot connect directly — symmetric NAT, corporate networks, most mobile carriers — and needs a TURN relay. Configure one driver; credentials are minted per participant on the server and folded into the token, so a TURN secret never reaches JavaScript. [TURN Costs Money](/guide/turn) explains the trade-off; the [TURN](/turn/overview) section has one page per driver.

## Calls

```php
'call' => [
    'default_timeout' => 30,
    'timeout' => ['driver' => env('BEAM_TIMEOUT', 'queue')],
    'heartbeat' => 30,
    'reconnect_timeout' => 30,
],
```

- `default_timeout` — seconds a ring lasts before `CallDeclined` fires with reason `timeout`; `->timeout()` on the builder overrides it per call.
- `timeout.driver` — what runs after an unanswered ring, and what notices a call whose browsers have both died. Cache expiry fires nothing, so something has to:

  | Driver | How | Costs |
  |---|---|---|
  | `queue` (default) | a delayed `ExpireCall` job | needs a worker; on the `sync` queue Beam falls back to `browser` and `beam:doctor` says so |
  | `browser` | the caller's page reports the timeout | dies with the tab |
  | `scheduler` | `beam:sweep` every minute (registered for you) | one-minute granularity |

- `heartbeat` — seconds between the browser's "still here" posts; the registry entry for a call lives as long as someone heartbeats.
- `reconnect_timeout` — seconds a dropped connection may spend reconnecting before the call ends with reason `connection-lost`. A Wi-Fi to mobile handover resolves well inside 30.

## Push

```php
'push' => [
    'store' => env('BEAM_PUSH_STORE', 'database'),
    'endpoints' => [],
    'suppress_when_online' => true,
    'timeout' => 4,
    'queue' => env('BEAM_PUSH_QUEUE', false),
    'vapid' => [
        'public'  => env('BEAM_VAPID_PUBLIC'),
        'private' => env('BEAM_VAPID_PRIVATE'),
        'subject' => env('BEAM_VAPID_SUBJECT'),
    ],
],
```

Rings a closed tab. `store` decides where each device's Web Push subscription lives: `database` (the `beam_push_subscriptions` table — survives `cache:clear` and deploys) or `cache` (no table, but a cache flush silently drops every device). `beam:install` generates the VAPID keys into `.env` once; set `BEAM_VAPID_SUBJECT` to a `mailto:` address. [Push Notifications](/usage/push) covers phones, including the iOS Home Screen requirement.

`suppress_when_online` is what happens when the callee has a tab open: by default no push is sent, because Broadcasting already rang it and two rings for one call is worse than one. Set it to `false` to ring the phone as well — an idle tab on a locked laptop is not the same as the person being there.

`endpoints` is an allow-list of the push services a browser may subscribe to. A subscription URL comes from the browser and your server posts to it on every incoming call, so it is one of the few places an authenticated user can aim your application somewhere — Beam always refuses a non-`https` URL, a private or reserved address, `localhost` and single-label hosts, whatever this list says. Leave it empty and any public push service is accepted, which is what keeps a browser nobody has heard of yet working. Set it to tighten:

```php
'endpoints' => ['fcm.googleapis.com', 'push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'],
```

That covers Chrome, Firefox, Safari and Edge today — and refuses anything else, including the next browser.

`timeout` is how long one device's push service gets before Beam gives up on it, and it is a bound on `ring()` itself: the send is inline by default, because a ring that waits for a queue worker can arrive after the caller has given up. `queue` moves it to the queue instead, so `ring()` returns at once and delivery is as prompt as your worker. [Push Notifications](/usage/push) has the trade in full.

## File transfer

```php
'transfer' => ['max_bytes' => 256 * 1024 * 1024],
```

The largest file a browser may offer. Files go browser to browser and never touch your server, so this is not a storage limit — it is a **memory** limit, and it belongs to the receiving tab. A transfer is held there until the last chunk arrives, and both sides hash the whole file (the browser's SHA-256 has no streaming form), so budget roughly twice the file in the receiving browser: 256 MB wants about half a gigabyte of headroom. A laptop has it; a phone has much less.

Raise it when your users send bigger files from machines that can take them, lower it when they are mostly on phones. The sender checks it before offering, the receiver before accepting, and the relay refuses an oversize offer with `413 TOO_LARGE`.

## Environment at a glance

```dotenv
BEAM_SIGNALING=controller
BEAM_TIMEOUT=queue
BEAM_TURN=none
BEAM_PUSH_STORE=database
BEAM_VAPID_PUBLIC=
BEAM_VAPID_PRIVATE=
BEAM_VAPID_SUBJECT=mailto:you@example.com
```

Run `php artisan beam:doctor` after changing any of them; it reads the same config and says what is still missing.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
