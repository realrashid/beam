<?php

declare(strict_types=1);

namespace RealRashid\Beam\Turn;

use RealRashid\Beam\Contracts\TurnDriver;

/**
 * STUN only. The out-of-the-box default; beam:doctor says plainly that roughly
 * one call in five will not connect until a relay is configured.
 */
final class NullDriver implements TurnDriver
{
    public function credentialsFor(int|string $userId): array
    {
        return [];
    }

    public function name(): string
    {
        return 'none';
    }
}
