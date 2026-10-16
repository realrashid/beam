<?php

declare(strict_types=1);

namespace RealRashid\Beam\Signaling;

use RealRashid\Beam\Broadcasts\Signal;
use RealRashid\Beam\Contracts\Signaler;

final class BroadcastSignaler implements Signaler
{
    public function relay(string $channel, array $message): void
    {
        broadcast(new Signal($channel, $message))->toOthers();
    }
}
