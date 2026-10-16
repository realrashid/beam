<?php

declare(strict_types=1);

namespace RealRashid\Beam\Push\Stores;

use Illuminate\Database\ConnectionInterface;
use RealRashid\Beam\Contracts\PushStore;

/**
 * The default: a table, so a cache:clear or a deploy never silently stops
 * closed tabs from ringing. beam:install publishes the migration.
 *
 * A device is identified by the SHA-256 of its endpoint rather than by the
 * endpoint itself: the URL is too long to index on MySQL (see the migration),
 * and a hash is the same 64 characters on every driver.
 */
final class DatabaseStore implements PushStore
{
    public const TABLE = 'beam_push_subscriptions';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function add(int|string $userId, array $subscription): void
    {
        $this->db->table(self::TABLE)->updateOrInsert(
            ['endpoint_hash' => self::hash($subscription['endpoint'])],
            [
                'user_id' => (string) $userId,
                'endpoint' => $subscription['endpoint'],
                'p256dh' => $subscription['keys']['p256dh'],
                'auth' => $subscription['keys']['auth'],
                'user_agent' => $subscription['user_agent'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function remove(int|string $userId, string $endpoint): void
    {
        $this->db->table(self::TABLE)->where('user_id', (string) $userId)->where('endpoint_hash', self::hash($endpoint))->delete();
    }

    public function for(int|string $userId): array
    {
        return $this->db->table(self::TABLE)->where('user_id', (string) $userId)->orderBy('id')->get()
            ->map(fn (object $row) => [
                'endpoint' => $row->endpoint,
                'keys' => ['p256dh' => $row->p256dh, 'auth' => $row->auth],
                'user_agent' => $row->user_agent,
                'created_at' => strtotime((string) $row->created_at) ?: 0,
            ])->all();
    }

    public function forget(int|string $userId): void
    {
        $this->db->table(self::TABLE)->where('user_id', (string) $userId)->delete();
    }

    public function name(): string
    {
        return 'database';
    }

    /** How a device is identified: the endpoint is the identity, its hash is the key. */
    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
