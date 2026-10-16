<?php

declare(strict_types=1);

namespace RealRashid\Beam;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use RealRashid\Beam\Data\Participant;
use RealRashid\Beam\Exceptions\BeamException;

/**
 * Beam::room("consult-{$id}")->allow($doctor, $patient)->video()->screenShare()->expiresAt($ends)->create()
 *
 * Also the handle on an existing room: Beam::room('consult-88')->close().
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
class PendingRoom
{
    use Conditionable;
    use Macroable;

    /** @var array<int, int|string> */
    protected array $allowed = [];

    protected string $media = 'video';

    protected bool $screenShare = true;

    protected ?DateTimeInterface $expiresAt = null;

    public function __construct(
        protected BeamManager $beam,
        protected string $name,
    ) {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,120}$/', $name) || str_starts_with($name, 'call_')) {
            throw BeamException::invalidConfiguration("Room name [{$name}] may only contain letters, digits, and _ . : @ - and may not start with call_.");
        }
    }

    public function allow(Authenticatable|Participant|int|string ...$users): static
    {
        foreach ($users as $user) {
            $this->allowed[] = match (true) {
                $user instanceof Authenticatable => $user->getAuthIdentifier(),
                $user instanceof Participant => $user->id,
                default => $user,
            };
        }

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

    public function screenShare(bool $allowed = true): static
    {
        $this->screenShare = $allowed;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $at): static
    {
        $this->expiresAt = $at;

        return $this;
    }

    public function create(): Room
    {
        return $this->persist($this->make());
    }

    /** The room as a value, before anything is written down. */
    protected function make(): Room
    {
        return new Room($this->name, array_values(array_unique($this->allowed)), $this->media, $this->screenShare, $this->expiresAt, now()->getTimestamp());
    }

    /**
     * Writes the room into the live registry. Testing\FakePendingRoom overrides
     * this and records instead.
     */
    protected function persist(Room $room): Room
    {
        $ttl = $this->expiresAt !== null
            ? max(1, $this->expiresAt->getTimestamp() - now()->getTimestamp())
            : (int) config('beam.token.ttl', 3600);

        // A room is live state with a lifetime, so the page that opens it is
        // the right place to create it — and creating it again must not throw
        // away the people already inside, or everyone's arrival fires twice and
        // their time in the room restarts.
        $snapshot = $room->toArray();
        $existing = $this->beam->registry()->get($room->name);

        if (is_array($existing) && isset($existing['name'])) {
            $snapshot['participants'] = $existing['participants'] ?? [];
            $snapshot['createdAt'] = (int) ($existing['createdAt'] ?? $snapshot['createdAt']);
        }

        $this->beam->registry()->put($room->name, $snapshot, $ttl);

        return $room;
    }

    /** The room as previously created, or null if it has expired or was never created. */
    public function find(): ?Room
    {
        $snapshot = $this->beam->registry()->get($this->name);

        return $snapshot === null ? null : Room::fromArray($snapshot);
    }

    /** The same, for code that would rather have the exception than the null. */
    public function findOrFail(): Room
    {
        return $this->find() ?? throw BeamException::notFound($this->name);
    }

    /** Closing a room that is not there is not an error: there is nobody to tell. */
    public function close(?string $reason = null): void
    {
        $this->find()?->close($reason);
    }
}
