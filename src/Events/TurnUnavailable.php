<?php

declare(strict_types=1);

namespace RealRashid\Beam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

final class TurnUnavailable
{
    use Dispatchable;

    public function __construct(
        public readonly string $driver,
        public readonly Throwable $error,
    ) {}
}
