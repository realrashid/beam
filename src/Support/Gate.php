<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use RealRashid\Beam\Room;

/**
 * Who may call whom, and who may enter a room. The application decides through
 * two ordinary gates; with none defined, any authenticated user may call any
 * other, and beam:doctor says so in production.
 */
final class Gate
{
    public const CALL = 'beam.call';

    public const JOIN = 'beam.join';

    public function __construct(private readonly GateContract $gate) {}

    public function mayCall(Authenticatable $caller, Authenticatable $callee): bool
    {
        if (! $this->hasCallGate()) {
            return true;
        }

        return $this->gate->forUser($caller)->allows(self::CALL, [$callee]);
    }

    /** With no gate: the room's own allow-list decides; an empty list admits anyone authenticated. */
    public function mayJoin(Authenticatable $user, Room $room): bool
    {
        if ($this->hasJoinGate()) {
            return $this->gate->forUser($user)->allows(self::JOIN, [$room]);
        }

        return $room->allows($user);
    }

    public function hasCallGate(): bool
    {
        return $this->gate->has(self::CALL);
    }

    public function hasJoinGate(): bool
    {
        return $this->gate->has(self::JOIN);
    }
}
