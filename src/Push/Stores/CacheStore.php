<?php

declare(strict_types=1);

namespace RealRashid\Beam\Push\Stores;

use Illuminate\Contracts\Cache\Repository;
use RealRashid\Beam\Contracts\PushStore;

/**
 * No table. And no safety net: a cache flush drops every device's
 * subscription and nothing can tell. The docs say so; beam:doctor says so.
 */
final class CacheStore implements PushStore
{
    public function __construct(private readonly Repository $cache, private readonly int $ttlDays = 30) {}

    public function add(int|string $userId, array $subscription): void
    {
        $list = array_values(array_filter($this->for($userId), fn (array $s) => $s['endpoint'] !== $subscription['endpoint']));
        $list[] = ['endpoint' => $subscription['endpoint'], 'keys' => $subscription['keys'], 'user_agent' => $subscription['user_agent'] ?? null, 'created_at' => now()->getTimestamp()];
        $this->cache->put($this->key($userId), $list, $this->ttlDays * 86400);
    }

    public function remove(int|string $userId, string $endpoint): void
    {
        $list = array_values(array_filter($this->for($userId), fn (array $s) => $s['endpoint'] !== $endpoint));
        $this->cache->put($this->key($userId), $list, $this->ttlDays * 86400);
    }

    public function for(int|string $userId): array
    {
        $list = $this->cache->get($this->key($userId), []);

        return is_array($list) ? array_values($list) : [];
    }

    public function forget(int|string $userId): void
    {
        $this->cache->forget($this->key($userId));
    }

    public function name(): string
    {
        return 'cache';
    }

    private function key(int|string $userId): string
    {
        return "beam:push:{$userId}";
    }
}
