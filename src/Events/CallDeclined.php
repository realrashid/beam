<?php

declare(strict_types=1);

namespace RealRashid\Beam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RealRashid\Beam\Call;
use RealRashid\Beam\Data\Participant;

final class CallDeclined
{
    use Dispatchable;

    public function __construct(
        public readonly Call $call,
        /** 'declined' | 'timeout' | 'busy' | whatever decline() was given */
        public readonly string $reason,
        /** Null when nobody declined — the ring timed out. */
        public readonly ?Participant $by = null,
    ) {}
}
