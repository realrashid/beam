<?php

declare(strict_types=1);

namespace RealRashid\Beam\Push;

use Illuminate\Contracts\Cache\Repository;

/**
 * Whether a user has a tab open that is listening for calls. Broadcasting
 * reaches it, so the push would only make the phone ring twice. Best effort:
 * a tab that has not heartbeated within the window gets both.
 */
final class Presence
{
    public function __construct(private readonly Repository $cache, private readonly int $windowSeconds = 45) {}

    public function touch(int|string $userId): void
    {
        $this->cache->put("beam:online:{$userId}", now()->getTimestamp(), $this->windowSeconds);
    }

    public function isOnline(int|string $userId): bool
    {
        return $this->cache->has("beam:online:{$userId}");
    }

    public function forget(int|string $userId): void
    {
        $this->cache->forget("beam:online:{$userId}");
    }
}
