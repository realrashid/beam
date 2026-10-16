<?php

declare(strict_types=1);

namespace RealRashid\Beam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RealRashid\Beam\Call;
use RealRashid\Beam\Data\Participant;

final class CallEnded
{
    use Dispatchable;

    public function __construct(
        public readonly Call $call,
        /** Seconds between answer and end, measured on the server; null if never answered. */
        public readonly ?int $duration,
        /** Null when neither side ended it — both went silent. Named as in CallAnswered and CallDeclined. */
        public readonly ?Participant $by,
        /** 'hangup' | 'connection-lost' | 'server' | whatever end() was given */
        public readonly string $reason = 'hangup',
    ) {}
}
