<?php

declare(strict_types=1);

namespace RealRashid\Beam\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class PushSubscribed
{
    use Dispatchable;

    public function __construct(
        public readonly int|string $userId,
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}} */
        public readonly array $subscription,
    ) {}
}
