<?php

declare(strict_types=1);

namespace RealRashid\Beam\Testing;

use RealRashid\Beam\PendingRoom;
use RealRashid\Beam\Room;

/**
 * What Beam::fake() hands back from room(). The name is still validated and the
 * Room is still assembled; nothing reaches the registry.
 */
final class FakePendingRoom extends PendingRoom
{
    public function __construct(private readonly BeamFake $fake, string $name)
    {
        parent::__construct($fake, $name);
    }

    protected function persist(Room $room): Room
    {
        return $this->fake->recordRoom($room);
    }
}
