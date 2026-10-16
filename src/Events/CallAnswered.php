<?php

declare(strict_types=1);

namespace RealRashid\Beam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RealRashid\Beam\Call;
use RealRashid\Beam\Data\Participant;

final class CallAnswered
{
    use Dispatchable;

    public function __construct(
        public readonly Call $call,
        public readonly Participant $by,
    ) {}
}
