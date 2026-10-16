<?php

declare(strict_types=1);

namespace RealRashid\Beam\Contracts;

interface TurnDriver
{
    /**
     * Mint TURN credentials for one participant — time-limited where the vendor allows it.
     *
     * @return array<int, array{urls: string|array<int, string>, username?: string, credential?: string}>
     */
    public function credentialsFor(int|string $userId): array;

    public function name(): string;
}
