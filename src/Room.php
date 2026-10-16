<?php

declare(strict_types=1);

namespace RealRashid\Beam;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Route;
use RealRashid\Beam\Broadcasts\CallControl;
use RealRashid\Beam\Data\Participant;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Events\ParticipantLeft;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Signaling\Channels;
use RealRashid\Beam\Support\CallRegistry;
use RealRashid\Beam\Support\Gate;

/**
 * Somewhere people join, rather than someone you ring. 1:1 in v1.
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
final class Room
{
    /**
     * @param  array<int, int|string>  $allowed  user ids; empty means anyone authenticated
     */
    public function __construct(
        public readonly string $name,
        public readonly array $allowed = [],
        /** 'audio' | 'video' */
        public readonly string $media = 'video',
        public readonly bool $screenShare = true,
        public readonly ?DateTimeInterface $expiresAt = null,
        public readonly int $createdAt = 0,
    ) {}

    public function channel(): string
    {
        return Channels::room($this->name);
    }

    public function allows(Authenticatable|Participant|int|string $user): bool
    {
        if ($this->allowed === []) {
            return true;
        }

        $id = match (true) {
            $user instanceof Authenticatable => $user->getAuthIdentifier(),
            $user instanceof Participant => $user->id,
            default => $user,
        };

        return in_array((string) $id, array_map('strval', $this->allowed), true);
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->getTimestamp() <= ($now ?? now()->getTimestamp());
    }

    /**
     * A join token for one participant — after the gate has said yes.
     * Registers them as a participant so the channel and the relay admit them.
     */
    public function token(Authenticatable $user): string
    {
        if ($this->isExpired()) {
            throw BeamException::expired();
        }

        if (! app(Gate::class)->mayJoin($user, $this)) {
            throw BeamException::forbidden("User [{$user->getAuthIdentifier()}] may not join room [{$this->name}].");
        }

        // A room carries two people. The third is refused here rather than
        // handed a token that would let their browser renegotiate the pair's
        // connection — and this counts who is live, so reloading your own page,
        // or arriving after someone has gone quiet, is not "a third person".
        $live = app(CallRegistry::class)->liveParticipants($this->name, (int) config('beam.call.heartbeat', 30));
        $others = array_filter(array_keys($live), fn (int|string $id) => (string) $id !== (string) $user->getAuthIdentifier());

        if (count($others) >= 2) {
            throw BeamException::roomFull($this->name);
        }

        $ttl = $this->expiresAt !== null
            ? max(1, $this->expiresAt->getTimestamp() - now()->getTimestamp())
            : (int) config('beam.token.ttl', 3600);

        app(CallRegistry::class)->allow($this->channel(), $user->getAuthIdentifier(), $ttl);

        return (new Token(
            callId: $this->name,
            channel: $this->channel(),
            role: 'participant',
            userId: $user->getAuthIdentifier(),
            ice: app(BeamManager::class)->ice($user->getAuthIdentifier()),
            expiresAt: now()->getTimestamp() + $ttl,
            meta: [
                'media' => $this->media,
                'screenShare' => $this->screenShare,
                'transport' => config('beam.signaling.transport', 'controller'),
                'heartbeat' => (int) config('beam.call.heartbeat', 30),
                'reconnectTimeout' => (int) config('beam.call.reconnect_timeout', 30),
                'transferMax' => (int) config('beam.transfer.max_bytes'),
            ],
        ))->pack(app(Encrypter::class));
    }

    /** The application's join page for this room: a route named `beam.room` taking {room}. */
    public function link(): string
    {
        if (! Route::has('beam.room')) {
            throw BeamException::invalidConfiguration('Define a route named [beam.room] that accepts a {room} parameter; Room::link() points there.');
        }

        return route('beam.room', ['room' => $this->name]);
    }

    /** One participant leaves: their grant is revoked and ParticipantLeft carries how long they were in. */
    public function leave(Authenticatable|Participant $user): void
    {
        $participant = $user instanceof Participant ? $user : Participant::from($user);
        $registry = app(CallRegistry::class);

        $duration = $registry->participantDuration($this->name, $participant->id);
        $registry->revoke($this->channel(), $participant->id);
        $registry->drop($this->name, $participant->id);

        event(new ParticipantLeft($this, $participant, $duration));
    }

    /** Close the room from the server: everyone in it is told, and nobody can rejoin. */
    public function close(?string $reason = null): void
    {
        broadcast(new CallControl($this->channel(), 'close', $reason ?? 'closed'));

        $registry = app(CallRegistry::class);
        foreach ($this->allowed as $id) {
            $registry->revoke($this->channel(), $id);
        }
        $registry->forget($this->name);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            $snapshot['name'],
            $snapshot['allowed'] ?? [],
            $snapshot['media'] ?? 'video',
            (bool) ($snapshot['screenShare'] ?? true),
            isset($snapshot['expiresAt']) ? (new \DateTimeImmutable)->setTimestamp((int) $snapshot['expiresAt']) : null,
            (int) ($snapshot['createdAt'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'allowed' => $this->allowed,
            'media' => $this->media,
            'screenShare' => $this->screenShare,
            'expiresAt' => $this->expiresAt?->getTimestamp(),
            'createdAt' => $this->createdAt,
        ];
    }
}
