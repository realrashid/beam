<?php

declare(strict_types=1);

namespace RealRashid\Beam\Contracts;

/**
 * Where each device's Web Push subscription lives. Two ship — database and
 * cache — and the application chooses through beam.push.store.
 *
 * @phpstan-type Subscription array{endpoint: string, keys: array{p256dh: string, auth: string}, user_agent?: string|null, created_at?: int}
 */
interface PushStore
{
    /**
     * @param  Subscription  $subscription
     */
    public function add(int|string $userId, array $subscription): void;

    public function remove(int|string $userId, string $endpoint): void;

    /**
     * @return array<int, Subscription>
     */
    public function for(int|string $userId): array;

    public function forget(int|string $userId): void;

    public function name(): string;
}
