<?php

declare(strict_types=1);

namespace RealRashid\Beam\Broadcasts;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use RealRashid\Beam\Call;
use RealRashid\Beam\Signaling\Channels;

/**
 * "Someone is calling you" — on the callee's private user channel, carrying the
 * callee's own packed token so answer() is one call. Never the caller's token.
 */
final class IncomingCall implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly Call $call,
        public readonly string $calleeToken,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(Channels::user($this->call->callee->id));
    }

    public function broadcastAs(): string
    {
        return 'incoming';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'callId' => $this->call->id,
            'from' => $this->call->caller->toArray(),
            'about' => $this->call->about,
            'media' => $this->call->media,
            'timeout' => $this->call->timeout,
            'token' => $this->calleeToken,
        ];
    }
}
