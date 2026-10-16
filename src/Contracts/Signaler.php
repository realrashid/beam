<?php

declare(strict_types=1);

namespace RealRashid\Beam\Contracts;

/**
 * Relays one signaling message to everyone on a channel except the sender.
 * One implementation in v1 (Laravel Broadcasting); v2's SFU adapter supplies another.
 */
interface Signaler
{
    /**
     * @param  array<string, mixed>  $message
     */
    public function relay(string $channel, array $message): void;
}
