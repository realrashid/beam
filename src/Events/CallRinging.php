<?php

declare(strict_types=1);

namespace RealRashid\Beam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RealRashid\Beam\Call;

final class CallRinging
{
    use Dispatchable;

    public function __construct(
        public readonly Call $call,
    ) {}
}
