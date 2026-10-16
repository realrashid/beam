<?php

declare(strict_types=1);

namespace RealRashid\Beam\Timeout;

use RealRashid\Beam\Call;
use RealRashid\Beam\Contracts\TimeoutDriver;
use RealRashid\Beam\Jobs\ExpireCall;
use RealRashid\Beam\Jobs\SweepCalls;

/** Delayed jobs. Exact, survives dead tabs, needs a worker. The default. */
final class QueueDriver implements TimeoutDriver
{
    public function schedule(Call $call): void
    {
        ExpireCall::dispatch($call->id)->delay($call->timeout);
    }

    public function watch(Call $call): void
    {
        SweepCalls::dispatch($call->id)->delay(((int) config('beam.call.heartbeat', 30)) * 3);
    }

    public function name(): string
    {
        return 'queue';
    }
}
