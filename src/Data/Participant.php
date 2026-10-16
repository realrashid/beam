<?php

declare(strict_types=1);

namespace RealRashid\Beam\Data;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who is on a call, as the other side's screen needs them: an id, a name, and
 * an avatar if the application has one. Never the model itself.
 */
final class Participant
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $name,
        public readonly ?string $avatar = null,
    ) {}

    /**
     * @param  Authenticatable|array{id: int|string, name?: string, avatar?: string|null}  $user
     */
    public static function from(Authenticatable|array $user): self
    {
        if (is_array($user)) {
            return new self($user['id'], (string) ($user['name'] ?? $user['id']), $user['avatar'] ?? null);
        }

        $id = $user->getAuthIdentifier();
        $name = $user->name ?? $user->email ?? (string) $id;
        $avatar = $user->avatar ?? $user->profile_photo_url ?? null;

        return new self($id, (string) $name, is_string($avatar) ? $avatar : null);
    }

    /**
     * @return array{id: int|string, name: string, avatar: string|null}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'avatar' => $this->avatar];
    }

    public function is(Authenticatable|Participant|int|string $other): bool
    {
        $otherId = match (true) {
            $other instanceof Authenticatable => $other->getAuthIdentifier(),
            $other instanceof self => $other->id,
            default => $other,
        };

        return (string) $this->id === (string) $otherId;
    }
}
