<?php

declare(strict_types=1);

namespace RealRashid\Beam\Broadcasts;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An offer, answer, ICE batch or bye, relayed browser → server → browser on
 * the controller transport. ShouldBroadcastNow: a queued signal would add a
 * worker's latency to every step of connecting a call.
 */
final class Signal implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $message
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'signal';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->message;
    }
}
