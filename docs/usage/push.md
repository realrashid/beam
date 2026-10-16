# <img src="/icons/bell.svg" class="h1-icon" alt=""> Push Notifications

An open tab rings through Broadcasting. A closed one rings through a notification: Web Push out of the box, or your own channels — FCM, APNs, SMS — through Laravel's Notification system. Beam decides which to use by whether a tab is listening.

## Web Push, built in

Three pieces, all shipped: a VAPID key pair (`beam:install` writes it to `.env`), a service worker served at `/beam/sw.js` with the header that lets it control scope `/`, and a subscription store (`push.store`: the `beam_push_subscriptions` table, or the cache).

Subscribe each device once, from a click — browsers refuse a permission prompt that nobody asked for:

```js
enableButton.onclick = async () => {
    await Beam.push.subscribe();     // registers the worker, asks permission, stores the subscription
};

await Beam.push.status();            // 'granted' | 'denied' | 'default' | 'unsupported'
await Beam.push.unsubscribe();
```

From then on, `ring()` sends `IncomingCallNotification` to the callee unless one of their tabs is known to be listening: a page with `Beam.onIncoming()` posts a presence heartbeat every 30 seconds, and a user seen within 45 is rung by Broadcasting alone. The notification shows "*Name* is calling" with the `about` line, and two actions:

- **Answer** — an open tab of the app answers in place through `Beam.onIncoming()`; otherwise the worker opens the app at `$call->link()` (your route named `beam.join`, or the app root) with `?beam=<token>&answer=1`, and `await Beam.answerFromNotification()` on that page joins.
- **Decline** — the worker posts the decline straight to your server; `CallDeclined` fires. It proves itself with the token the push carried rather than with a session and a CSRF token, because a service worker has neither: the endpoint (`POST {prefix}/calls/{id}/decline-by-token`) is outside the session middleware and accepts nothing else. A tap that loses the race — answered on the laptop, or already timed out — is a quiet 204.

```js
// On the page beam.join points at
const call = await Beam.answerFromNotification();   // null when the page was opened normally
if (call) call.attach({ local: '#me', remote: '#them' });
```

![The incoming-call modal from beam:install --views, ringing](../public/screenshots/incoming-modal.png)

## Phones

- **Android** (Chrome, Firefox, Samsung Internet): works in the browser as-is.
- **iPhone and iPad**: Web Push exists only for a web app added to the Home Screen, on iOS 16.4 or later. That needs a web app manifest with `"display": "standalone"` linked from your pages; `beam:doctor` checks for one once VAPID keys are set. `Beam.push.status()` reports `unsupported` in a Safari tab and works once installed; `platform.supportsPush()` says the same, so you can show an "Add to Home Screen" hint instead of a broken button. In the EU, iOS opens installed web apps in Safari and there is no push at all — a native app is the only way to ring an EU iPhone that is not on the page.
- **Desktop Safari** 16 and later takes a plain page.

## Your own channels

`IncomingCallNotification` is an ordinary Notification. Route it wherever the user already gets notifications:

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForBeam(): array
    {
        return ['webpush', 'fcm'];   // Beam's Web Push channel, plus your own
    }
}
```

`toArray()` carries `callId`, `from`, `about` and `media` for any channel; `toWebPush()` adds the callee's token, the link and the decline URL. A native app that receives it can open the app's call page with `?beam=<token>&answer=1` and let `answerFromNotification()` do the rest.

## What is stored

Only the subscription: the push endpoint and its two keys, per user, with the user agent and a timestamp. A device is identified by the SHA-256 of its endpoint — the URL itself is too long for any database to index — so re-subscribing the same browser replaces its row rather than adding one. `PushSubscribed` fires when one is stored. A subscription that the push service reports gone (410) is removed on the next send.

## What it says, and in which language

Every string on that notification comes from a published language file, so changing the wording — or the language — does not mean replacing the notification:

```bash
php artisan vendor:publish --tag=beam-lang
```

```php
// lang/vendor/beam/en/beam.php
'notification' => [
    'calling' => ':name is calling',
    'video' => 'Video call',
    'audio' => 'Voice call',
    'answer' => 'Answer',
    'decline' => 'Decline',
],
```

Add `lang/vendor/beam/ur/beam.php` and a user whose locale is `ur` is rung in Urdu. The `about` line is your own text and is shown as you wrote it.

## Ringing a phone whose owner has a tab open somewhere

By default a tab seen in the last 45 seconds suppresses the push — the thinking being that Broadcasting already reached them, and two rings for one call is worse than one. An idle tab on a locked work laptop is not the same as the person being there, so it is a setting rather than a rule:

```php
'push' => ['suppress_when_online' => false],   // ring the phone as well, always
```

## If your application already has a service worker

Registering a worker at a scope replaces whatever was registered there, so
`Beam.push.subscribe()` — which takes scope `/` by default — would evict a PWA's
own worker. Two ways round it, and the first is better:

```js
// your-worker.js, at the top: Beam's handlers become part of your worker
importScripts('/beam/sw.js');
```

Beam's worker only adds `push` and `notificationclick` listeners, so importing
it composes with yours and you never call `subscribe()`'s registration. If you
would rather keep them apart, give Beam a narrower scope — `Beam.push` accepts
one — and accept that notifications only ring from pages under it:

```js
Beam.configure({ pushScope: '/calls/' });   // before Beam.push.subscribe()
```

A subscription is tied to the VAPID key it was made with: rotate the keys and
every existing device would be refused with a 403 for ever, so `subscribe()`
notices the mismatch, drops the old subscription and makes a new one.

The endpoint arrives from the browser and your server posts to it on every incoming call, so Beam checks where it points: `https` only, and never your own network — a private or reserved address, `localhost`, or a single-label host is refused with a validation error. `push.endpoints` in the config narrows it further to a list of services you name; empty, the default, accepts any public one. [Configuration](/guide/configuration) has the list to paste in if you want the tighter posture.

## When the push is sent, and how long it may take

`ring()` sends the push inline, so it is on its way before the caller's request returns — a ring that waits for a queue worker is a ring that can arrive after the caller has given up. Two keys bound and change that:

```php
'push' => [
    'timeout' => 4,                                  // seconds per device before giving up
    'queue'   => env('BEAM_PUSH_QUEUE', false),      // true: hand it to the queue, return at once
],
```

`timeout` matters more than it looks: without it, the underlying HTTP client has none, and a push service that stops answering would hold the request that placed the call until PHP gave up on it. With `queue` on, `ring()` pays nothing and delivery is as prompt as your worker — a good trade when a worker is always running, and a bad one when it is not.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>