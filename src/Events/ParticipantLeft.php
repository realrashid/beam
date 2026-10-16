<?php

declare(strict_types=1);

namespace RealRashid\Beam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RealRashid\Beam\Data\Participant;
use RealRashid\Beam\Room;

final class ParticipantLeft
{
    use Dispatchable;

    public function __construct(
        public readonly Room $room,
        public readonly Participant $participant,
        public readonly ?int $duration = null,
    ) {}
}
