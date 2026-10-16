<?php

declare(strict_types=1);

namespace RealRashid\Beam\Data;

enum CallState: string
{
    case Ringing = 'ringing';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Reconnecting = 'reconnecting';
    case Ended = 'ended';

    public function isLive(): bool
    {
        return $this !== self::Ended;
    }
}
