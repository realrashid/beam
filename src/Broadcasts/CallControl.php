<?php

declare(strict_types=1);

namespace RealRashid\Beam\Broadcasts;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use RealRashid\Beam\Data\Participant;

/**
 * A server-side decision about a live call or room — decline, end, close —
 * delivered to everyone on its channel.
 */
final class CallControl implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public const ACTIONS = ['decline', 'end', 'close'];

    public function __construct(
        public readonly string $channel,
        public readonly string $action,
        public readonly ?string $reason = null,
        public readonly ?Participant $by = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'control';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['action' => $this->action, 'reason' => $this->reason, 'by' => $this->by?->toArray()];
    }
}
