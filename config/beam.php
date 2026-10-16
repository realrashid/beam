<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The signal relay, call control and the push endpoints live under this
    | prefix, behind this middleware, at this rate.
    |
    | The throttle covers every authenticated route together — signals,
    | heartbeats, presence. A minute of trouble is the busiest Beam ever gets
    | (an ICE restart every five seconds, a candidate batch after each, a
    | heartbeat per state change), so it is set where a reconnecting call
    | cannot reach it. Set it lower and a call that hit a bad network would be
    | refused the signals it needs to recover.
    |
    | The compiled client and the service worker are the exception: a page may
    | need the client before anyone is signed in, and the browser fetches the
    | worker with no session, so they are registered separately with
    | asset_middleware — nothing, by default. Put your own middleware there if
    | your edge needs one; leave `auth` out of it, or the browser is served a
    | redirect where it expects JavaScript.
    |
    */

    'routes' => [
        'prefix' => 'beam',
        'middleware' => ['web', 'auth'],
        'asset_middleware' => [],
        'throttle' => '240,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token
    |--------------------------------------------------------------------------
    |
    | The token authorises joining, nothing after. This bounds how long a ring
    | or a room link stays joinable; once joined, being a participant in the
    | live call is what authorises signals, so expiry never drops a call.
    | Room links for a later appointment use expiresAt() on the room instead.
    |
    */

    'token' => [
        'ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signaling
    |--------------------------------------------------------------------------
    |
    | What carries the offer, answer and ICE candidates between two browsers.
    |
    |   controller  browser → PHP → Broadcasting → browser. PHP sees every
    |               message and a failure is an HTTP status. The default,
    |               because its failure is visible. Both broadcasters cap an
    |               event near 10 KB, so PHP gzips a signal over max_payload
    |               and the client inflates it — an offer carrying two video
    |               tracks is over the cap on its own.
    |   whisper     browser → Broadcasting → browser. Lower latency. Pusher
    |               requires client events to be enabled, caps them at ten
    |               per second per connection and drops the excess silently;
    |               both Pusher and Reverb cap a message near 10 KB.
    |
    */

    'signaling' => [
        'transport' => env('BEAM_SIGNALING', 'controller'),

        // Bytes. Above this a relayed signal is gzipped rather than handed to a
        // broadcaster that would refuse it (Pusher's 10 KB cap cannot be
        // raised; Reverb's REVERB_APP_MAX_MESSAGE_SIZE can). 0 turns it off.
        'max_payload' => env('BEAM_SIGNAL_MAX_PAYLOAD', 9000),
    ],

    /*
    |--------------------------------------------------------------------------
    | ICE — STUN and TURN
    |--------------------------------------------------------------------------
    |
    | STUN works with no setup and connects most calls. Roughly one in five
    | cannot connect directly — symmetric NAT, corporate networks, most mobile
    | carriers — and needs a TURN relay. Configure one driver; credentials are
    | minted per participant on the server and folded into the token, so a
    | TURN secret never reaches JavaScript.
    |
    */

    'ice' => [
        'stun' => [
            'stun:stun.l.google.com:19302',
            'stun:stun1.l.google.com:19302',
        ],

        'turn' => [
            'default' => env('BEAM_TURN', 'none'),

            // Vendor credentials are minted over HTTP inside ring(). Keeping
            // them for a quarter of their life means most rings make no vendor
            // call at all, and a call that starts with a cached credential
            // still has three quarters of the relay's window ahead of it. Set
            // false to mint on every ring; coturn is never cached (its
            // credential is an HMAC computed here).
            'cache' => env('BEAM_TURN_CACHE', true),

            // One block per driver, the way filesystems.disks and cache.stores
            // are laid out: the selector above, the options here, and nothing
            // else in between — a driver an application adds lands here too.
            'drivers' => [

                'coturn' => [
                    // COTURN_URLS=turn:turn.yourapp.com:3478,turns:turn.yourapp.com:5349
                    'urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('COTURN_URLS', ''))))),
                    'secret' => env('COTURN_SECRET'),
                    'ttl' => 3600,
                ],

                'cloudflare' => [
                    'key_id' => env('CF_TURN_KEY_ID'),
                    'token' => env('CF_TURN_API_TOKEN'),
                    'ttl' => 3600,
                ],

                'twilio' => [
                    'sid' => env('TWILIO_SID'),
                    'token' => env('TWILIO_TOKEN'),
                    'ttl' => 3600,
                ],

                'metered' => [
                    'app' => env('METERED_APP'),
                    'api_key' => env('METERED_KEY'),
                    // Optional: ask for a relay in one region rather than the nearest.
                    'region' => env('METERED_REGION'),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Calls
    |--------------------------------------------------------------------------
    |
    | timeout.driver decides what runs after an unanswered ring, and what
    | notices a call whose browsers have both died. Cache expiry fires nothing,
    | so something has to.
    |
    |   queue      a delayed job (needs a worker; on the sync queue Beam falls
    |              back to browser and beam:doctor says so). The default.
    |   browser    the caller's page reports the timeout. Dies with the tab.
    |   scheduler  beam:sweep every minute. One-minute granularity.
    |
    */

    'call' => [
        'default_timeout' => 30,

        'timeout' => [
            'driver' => env('BEAM_TIMEOUT', 'queue'),
        ],

        'heartbeat' => 30,

        // Seconds a dropped connection may spend reconnecting before the call ends with reason 'connection-lost'.
        'reconnect_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Push
    |--------------------------------------------------------------------------
    |
    | Rings a closed tab. store decides where each device's Web Push
    | subscription lives: a database table (survives cache:clear and deploys)
    | or the cache (no table, but a cache flush silently drops every device).
    |
    | endpoints is an allow-list of the push services a browser may subscribe
    | to. Empty means any https host that is not on your own network — the
    | subscription URL comes from the browser, and Beam refuses a private or
    | reserved address whatever this says. List hosts to tighten it:
    |
    |   'endpoints' => ['fcm.googleapis.com', 'push.services.mozilla.com',
    |                   'web.push.apple.com', 'notify.windows.com'],
    |
    | which covers Chrome, Firefox, Safari and Edge today — and refuses any
    | browser whose service is not on the list, including new ones.
    |
    */

    'push' => [
        'store' => env('BEAM_PUSH_STORE', 'database'),

        'endpoints' => [],

        // A tab seen in the last 45 seconds suppresses the push: Broadcasting
        // already rang it, and two rings for one call is worse than one. false
        // rings the phone as well — an idle tab on a locked work laptop is not
        // the same as the person being there.
        'suppress_when_online' => true,

        // Seconds to give a push service before giving up on one device. This
        // runs inside ring() unless queue is true, so it is a bound on how long
        // placing a call can wait for someone else's server.
        'timeout' => 4,

        // false: the ring's push is sent inline, and arrives as fast as the
        // push service answers. true: it goes to the queue, ring() returns at
        // once, and delivery is as prompt as your worker.
        'queue' => env('BEAM_PUSH_QUEUE', false),

        'vapid' => [
            'public' => env('BEAM_VAPID_PUBLIC'),
            'private' => env('BEAM_VAPID_PRIVATE'),
            'subject' => env('BEAM_VAPID_SUBJECT'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File transfer
    |--------------------------------------------------------------------------
    |
    | The largest file a browser may offer. This is a limit on memory, not on
    | disk: a transfer never touches the server, but it is held in the receiving
    | tab until the last chunk arrives, and both sides hash the whole file
    | (the browser's SHA-256 has no streaming form). Budget about twice the
    | file in the receiving tab — 256 MB needs roughly half a gigabyte of
    | headroom — and remember that a phone has far less of it than a laptop.
    |
    | Raise it if your users send bigger files and their machines can take it;
    | lower it if they are mostly on phones. The sender checks it before
    | offering, the receiver before accepting, and the relay refuses an
    | oversize offer with 413.
    |
    */

    'transfer' => [
        'max_bytes' => 256 * 1024 * 1024,
    ],

];
