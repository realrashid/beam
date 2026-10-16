<?php

declare(strict_types=1);

namespace RealRashid\Beam\Timeout;

use RealRashid\Beam\Call;
use RealRashid\Beam\Contracts\TimeoutDriver;

/** beam:sweep every minute walks every live call. One-minute granularity, needs the scheduler. */
final class SchedulerDriver implements TimeoutDriver
{
    public function schedule(Call $call): void
    {
        //
    }

    public function watch(Call $call): void
    {
        //
    }

    public function name(): string
    {
        return 'scheduler';
    }
}
