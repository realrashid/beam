<?php

declare(strict_types=1);

namespace RealRashid\Beam\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RealRashid\Beam\Support\CallSweeper;

/**
 * Dispatched by ring() with delay = timeout. Declines the call if nobody has
 * answered by then — unless the callee is answering right now, in which case it
 * looks again a heartbeat later rather than ending a call mid-negotiation.
 */
final class ExpireCall implements ShouldQueue
{
    use Queueable;

    /** How many extra looks a ring gets while its callee is mid-answer. */
    public const ATTEMPTS = 3;

    public function __construct(public readonly string $callId, public readonly int $attempt = 1) {}

    public function handle(CallSweeper $sweeper): void
    {
        if ($sweeper->expireIfRinging($this->callId) || ! $sweeper->isAnswering($this->callId)) {
            return;
        }

        if ($this->attempt < self::ATTEMPTS) {
            self::dispatch($this->callId, $this->attempt + 1)->delay((int) config('beam.call.heartbeat', 30));
        }
    }
}
