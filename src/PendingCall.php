<?php

declare(strict_types=1);

namespace RealRashid\Beam;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use RealRashid\Beam\Broadcasts\IncomingCall;
use RealRashid\Beam\Data\IceServers;
use RealRashid\Beam\Data\Participant;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Events\CallRinging;
use RealRashid\Beam\Events\TurnUnavailable;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Notifications\IncomingCallNotification;
use RealRashid\Beam\Push\Presence;
use RealRashid\Beam\Support\Gate;
use Throwable;

/**
 * Beam::call($callee)->from($agent)->video()->about('Order #1042')->timeout(30)->ring()
 *
 * Nothing happens until ring(). Then: the gate, TURN credentials for both
 * sides, two tokens, the registry, the incoming-call broadcast, the timeout,
 * the event — none of which the browser has to know about.
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
class PendingCall
{
    use Conditionable;
    use Macroable;

    protected ?Authenticatable $caller = null;

    protected string $media = 'video';

    protected ?string $about = null;

    protected ?int $timeout = null;

    /** @var array<string, mixed> */
    protected array $meta = [];

    public function __construct(
        protected BeamManager $beam,
        protected Authenticatable $callee,
    ) {}

    public function from(Authenticatable $caller): static
    {
        $this->caller = $caller;

        return $this;
    }

    public function audio(): static
    {
        $this->media = 'audio';

        return $this;
    }

    public function video(): static
    {
        $this->media = 'video';

        return $this;
    }

    /** No camera, no microphone: a connection for files only. What Beam.send() uses outside a call. */
    public function files(): static
    {
        $this->media = 'none';

        return $this;
    }

    public function about(?string $about): static
    {
        $this->about = $about;

        return $this;
    }

    /** Seconds to ring before CallDeclined('timeout'). */
    public function timeout(int $seconds): static
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    /**
     * Extra claims the browser may read from the token.
     *
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): static
    {
        $this->meta = [...$this->meta, ...$meta];

        return $this;
    }

    public function ring(): Call
    {
        return $this->dispatch($this->authorised());
    }

    /**
     * The gate, then the call itself as a value — everything that must happen
     * whether or not anything is broadcast, which is why Beam::fake() runs it too.
     */
    protected function authorised(): Call
    {
        $caller = $this->caller ?? auth()->user();

        if (! $caller instanceof Authenticatable) {
            throw BeamException::invalidConfiguration('No caller: call from($user) or ring() from an authenticated request.');
        }

        if (! $this->beam->gate()->mayCall($caller, $this->callee)) {
            throw BeamException::forbidden("User [{$caller->getAuthIdentifier()}] may not call user [{$this->callee->getAuthIdentifier()}].");
        }

        return new Call(
            id: 'call_'.Str::lower((string) Str::ulid()),
            caller: Participant::from($caller),
            callee: Participant::from($this->callee),
            media: $this->media,
            about: $this->about,
            timeout: $this->timeout ?? (int) config('beam.call.default_timeout', 30),
            createdAt: now()->getTimestamp(),
        );
    }

    /**
     * Tokens, the registry, the broadcast, the notification, the timeout, the
     * event. Testing\FakePendingCall overrides this and records instead — so
     * nothing in here has to ask whether a test is running.
     */
    protected function dispatch(Call $call): Call
    {
        $ttl = (int) config('beam.token.ttl', 3600);
        $registry = $this->beam->registry();
        $registry->allow($call->channel(), $call->caller->id, $ttl);
        $registry->allow($call->channel(), $call->callee->id, $ttl);
        $registry->put($call->id, $call->toArray(), $ttl);

        $timeouts = $this->beam->timeouts();
        $this->meta['timeoutDriver'] = $timeouts->name();

        $callerToken = $this->tokenFor($call, 'caller', $call->caller->id, $ttl);
        $calleeToken = $this->tokenFor($call, 'callee', $call->callee->id, $ttl);

        $rung = new Call(...[...$call->toArray(), 'caller' => $call->caller, 'callee' => $call->callee, 'token' => $callerToken]);

        broadcast(new IncomingCall($rung, $calleeToken));
        if ($this->media !== 'none') {
            $this->notify($rung, $calleeToken);
        }
        $timeouts->schedule($rung);
        event(new CallRinging($rung));

        return $rung;
    }

    /**
     * Broadcasting only reaches an open tab. Unless one is listening, the callee
     * is also notified — Web Push by default, or wherever the application routes it.
     * Only a Notifiable callee can be (every Laravel User model is, by default).
     */
    protected function notify(Call $call, string $calleeToken): void
    {
        if (! method_exists($this->callee, 'notify')) {
            return;
        }

        // A tab that is listening will be rung by Broadcasting, so by default the
        // push is skipped and the phone stays quiet. Not everyone wants that —
        // an idle tab on a locked work laptop is not the same as the person
        // being there — so it is a setting, not a rule.
        if (config('beam.push.suppress_when_online', true) && app(Presence::class)->isOnline($call->callee->id)) {
            return;
        }

        if (empty(config('beam.push.vapid.public')) && ! method_exists($this->callee, 'routeNotificationForBeam')) {
            return;
        }

        $notification = new IncomingCallNotification($call, $calleeToken);

        // Inline by default: a push that goes through a queue arrives when the
        // worker gets to it, and a ring is worth less late. The send is bounded
        // (beam.push.timeout), so a push service that stops answering costs a
        // few seconds rather than the request. Applications that would rather
        // pay nothing at ring time set beam.push.queue.
        if (config('beam.push.queue', false)) {
            $this->callee->notify($notification);

            return;
        }

        method_exists($this->callee, 'notifyNow')
            ? $this->callee->notifyNow($notification)
            : $this->callee->notify($notification);
    }

    protected function tokenFor(Call $call, string $role, int|string $userId, int $ttl): string
    {
        return (new Token(
            callId: $call->id,
            channel: $call->channel(),
            role: $role,
            userId: $userId,
            ice: $this->iceFor($userId),
            expiresAt: now()->getTimestamp() + $ttl,
            meta: [
                ...$this->meta,
                'about' => $call->about,
                'from' => $call->caller->toArray(),
                'to' => $call->callee->toArray(),
                'media' => $call->media,
                'timeout' => $call->timeout,
                'transport' => config('beam.signaling.transport', 'controller'),
                'heartbeat' => (int) config('beam.call.heartbeat', 30),
                'reconnectTimeout' => (int) config('beam.call.reconnect_timeout', 30),
                'transferMax' => (int) config('beam.transfer.max_bytes'),
            ],
        ))->pack(app(Encrypter::class));
    }

    /**
     * A TURN vendor that does not answer is not fatal: the call goes ahead on
     * STUN, and the application is told so it can alert.
     */
    protected function iceFor(int|string $userId): IceServers
    {
        try {
            return $this->beam->ice($userId);
        } catch (Throwable $e) {
            event(new TurnUnavailable((string) config('beam.ice.turn.default', 'none'), $e));

            return IceServers::fromConfig((array) config('beam.ice', []));
        }
    }
}
