<?php

declare(strict_types=1);

namespace RealRashid\Beam\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RealRashid\Beam\Support\CallRegistry;
use RealRashid\Beam\Support\CallSweeper;

/**
 * Dispatched when a call is answered, and again by itself while the call is
 * live. Ends the call once every browser in it has gone silent.
 */
final class SweepCalls implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $callId) {}

    public function handle(CallSweeper $sweeper, CallRegistry $registry): void
    {
        if ($sweeper->endIfStale($this->callId) || $registry->get($this->callId) === null) {
            return;
        }

        self::dispatch($this->callId)->delay(((int) config('beam.call.heartbeat', 30)) * 3);
    }
}
