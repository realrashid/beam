# <img src="/icons/zap.svg" class="h1-icon" alt=""> Events

Beam stores nothing about calls. Every fact worth keeping — who called whom, whether they answered, how long they spoke, what file crossed — arrives as an ordinary Laravel event on your server. Listen and write it wherever your history lives.

## Calls

| Event | Fires when | Carries |
|---|---|---|
| `CallRinging` | `ring()` has broadcast the call | `$call` |
| `CallAnswered` | the first browser in the call reports `connected` — whichever side that is, once | `$call` (with `answeredAt`), `$by` (the participant whose heartbeat arrived first) |
| `CallDeclined` | the callee declines, the caller cancels while ringing, or the ring times out | `$call`, `$reason` (`declined`, `timeout`, or yours), `$by` (null on a timeout) |
| `CallEnded` | either side hangs up, the server ends it, or both browsers vanish | `$call`, `$duration` (seconds, null if never answered), `$by` (null when nobody did), `$reason` ([the vocabulary](/usage/errors)) |

```php
use RealRashid\Beam\Events\CallEnded;

Event::listen(CallEnded::class, function (CallEnded $e) {
    CallLog::create([
        'call_id'   => $e->call->id,
        'caller_id' => $e->call->caller->id,
        'callee_id' => $e->call->callee->id,
        'about'     => $e->call->about,
        'seconds'   => $e->duration,
        'reason'    => $e->reason,
    ]);
});
```

`$call` is the `RealRashid\Beam\Call` value object: `id`, `caller` and `callee` (`Participant`: `id`, `name`, `avatar`), `media`, `about`, `timeout`, `createdAt`, `answeredAt`, `endedAt`. `endedAt` is set when the server measured the end — a sweep of a call that went silent — and null on an ordinary hangup, where `$duration` is the figure to keep.

## Rooms

| Event | Fires when | Carries |
|---|---|---|
| `ParticipantJoined` | someone's first heartbeat arrives in a room | `$room`, `$participant` |
| `ParticipantLeft` | they hang up, are removed with `leave()`, or vanish | `$room`, `$participant`, `$duration` |

## Files

`FileOffered`, `FileAccepted`, `FileDeclined` and `FileTransferred`, each with `$transferId`, `$from`, `$to` (both `Participant`) and `$file` — a `RealRashid\Beam\Data\File` with `name`, `size`, `mime`, `forHumans()` and `toArray()`. `FileTransferred` means the receiver reported done and the hash matched.

All three of a file's details come from the other browser, like the body of any request: escape the name before it reaches a page, and sanitise it before anything is saved under it.

## Infrastructure

| Event | Fires when |
|---|---|
| `PushSubscribed` | a device stored a Web Push subscription — `$userId`, `$subscription` |
| `TurnUnavailable` | a TURN driver threw while minting during `ring()` — `$driver`, `$error`. The call goes ahead on STUN alone; alert on this one. |

## Broadcast, not dispatched

Two more events travel to browsers over Broadcasting rather than to your listeners: `IncomingCall` on the callee's private user channel (`beam.user.{id}`), and `Signal` / `CallControl` on the call's channel (`beam.call.{id}` or `beam.room.{name}`). Beam registers the channel authorisation callbacks for all three; you never touch them.

<br />
<p align="center"> <b>Made with ❤️ from Pakistan</b> </p>
