<?php

declare(strict_types=1);

namespace RealRashid\Beam;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Broadcasts\CallControl;
use RealRashid\Beam\Data\Participant;
use RealRashid\Beam\Events\CallDeclined;
use RealRashid\Beam\Events\CallEnded;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Signaling\Channels;
use RealRashid\Beam\Support\CallRegistry;

/**
 * A call that has been rung. What ring() returns, what Beam::find() rebuilds,
 * and what every call event carries.
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
final class Call
{
    public function __construct(
        public readonly string $id,
        public readonly Participant $caller,
        public readonly Participant $callee,
        /** 'audio' | 'video' */
        public readonly string $media,
        public readonly ?string $about,
        /** Seconds to ring before CallDeclined('timeout'). */
        public readonly int $timeout,
        /** The caller's packed token — hand it to the caller's browser. Empty when rebuilt by find(). */
        public readonly string $token = '',
        public readonly int $createdAt = 0,
        /** Unix seconds, set by the server when the callee answers (Phase 7 wires it from the heartbeat). */
        public readonly ?int $answeredAt = null,
        public readonly ?int $endedAt = null,
    ) {}

    public function channel(): string
    {
        return Channels::call($this->id);
    }

    /**
     * A URL the callee can open from an email or SMS. The page is the
     * application's: define a route named `beam.join` that takes the call id.
     */
    public function link(): string
    {
        if (! Route::has('beam.join')) {
            throw BeamException::invalidConfiguration('Define a route named [beam.join] that accepts a {call} parameter; Call::link() points there.');
        }

        return route('beam.join', ['call' => $this->id]);
    }

    /** Refuse or cancel the call from the server. */
    public function decline(?string $reason = null, ?Participant $by = null): void
    {
        $reason ??= 'declined';

        broadcast(new CallControl($this->channel(), 'decline', $reason, $by));
        $this->release();
        event(new CallDeclined($this, $reason, $by));
    }

    /** End a live call from the server. */
    public function end(?string $reason = null, ?Participant $by = null): void
    {
        $reason ??= 'server';

        broadcast(new CallControl($this->channel(), 'end', $reason, $by));
        $duration = $this->duration();
        $this->release();
        event(new CallEnded($this, $duration, $by, $reason));
    }

    /** Seconds between answer and end (or now, while live) — server-measured. Null if never answered. */
    public function duration(?int $now = null): ?int
    {
        if ($this->answeredAt === null) {
            return null;
        }

        return max(0, ($this->endedAt ?? $now ?? now()->getTimestamp()) - $this->answeredAt);
    }

    public function isParticipant(Authenticatable|Participant|int|string $user): bool
    {
        return $this->caller->is($user) || $this->callee->is($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'caller' => $this->caller->toArray(),
            'callee' => $this->callee->toArray(),
            'media' => $this->media,
            'about' => $this->about,
            'timeout' => $this->timeout,
            'createdAt' => $this->createdAt,
            'answeredAt' => $this->answeredAt,
            'endedAt' => $this->endedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            caller: Participant::from($data['caller']),
            callee: Participant::from($data['callee']),
            media: $data['media'],
            about: $data['about'] ?? null,
            timeout: (int) $data['timeout'],
            createdAt: (int) ($data['createdAt'] ?? 0),
            answeredAt: isset($data['answeredAt']) ? (int) $data['answeredAt'] : null,
            endedAt: isset($data['endedAt']) ? (int) $data['endedAt'] : null,
        );
    }

    private function release(): void
    {
        $registry = app(CallRegistry::class);
        $registry->revoke($this->channel(), $this->caller->id);
        $registry->revoke($this->channel(), $this->callee->id);
        $registry->forget($this->id);
    }
}
