<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * Who is a participant of which live channel, and a snapshot of every live
 * call and room — the facts that authorise Broadcasting channels and the
 * relay, and that Beam::find() reads. Cache-held; never a table.
 *
 * Every browser in a call heartbeats; each heartbeat refreshes the grant and
 * the snapshot for 3 × the heartbeat interval, so a two-hour call lives as
 * long as someone is in it and a dead tab is noticed within ninety seconds.
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
final class CallRegistry
{
    private const INDEX = 'beam:calls:index';

    /**
     * How long a snapshot is kept after its last heartbeat. Deliberately much
     * longer than the staleness window (3 × heartbeat): staleness is judged
     * from seenAt, and a snapshot that expired first would leave nothing for
     * the sweeper to end — the "cache expiry fires nothing" trap. The sweeper
     * calls forget() when it ends a call; this only bounds what nothing swept.
     */
    public const SNAPSHOT_TTL = 3600;

    public function __construct(private readonly Repository $cache) {}

    public function allow(string $channel, int|string $userId, int $ttlSeconds): void
    {
        $this->cache->put($this->key($channel, $userId), true, $ttlSeconds);
    }

    public function isParticipant(string $channel, int|string|null $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return (bool) $this->cache->get($this->key($channel, $userId), false);
    }

    public function revoke(string $channel, int|string $userId): void
    {
        $this->cache->forget($this->key($channel, $userId));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function put(string $id, array $snapshot, int $ttlSeconds): void
    {
        $this->cache->put("beam:call:{$id}", $snapshot, $ttlSeconds);
        $this->index(fn (array $ids) => array_values(array_unique([...$ids, $id])));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $snapshot = $this->cache->get("beam:call:{$id}");

        return is_array($snapshot) ? $snapshot : null;
    }

    public function forget(string $id): void
    {
        $this->cache->forget("beam:call:{$id}");
        $this->cache->forget("beam:answered:{$id}");
        $this->index(fn (array $ids) => array_values(array_diff($ids, [$id])));
    }

    /**
     * A browser saying "still here, in this state". Refreshes its grant and
     * the snapshot; records when and in what state it was last seen.
     */
    public function touch(string $id, string $channel, int|string $userId, string $state, int $heartbeat, ?int $now = null): void
    {
        $now ??= now()->getTimestamp();

        // Two browsers heartbeat on the same cadence: without the lock, the
        // later write can carry a snapshot read before the earlier one and drop
        // the other participant — which shows up as an arrival firing twice.
        $this->atomically($id, function () use ($id, $userId, $state, $now) {
            $snapshot = $this->get($id);

            if ($snapshot === null) {
                return;
            }

            $joinedAt = $snapshot['participants'][(string) $userId]['joinedAt'] ?? $now;
            $snapshot['participants'][(string) $userId] = ['state' => $state, 'seenAt' => $now, 'joinedAt' => $joinedAt];

            $this->cache->put("beam:call:{$id}", $snapshot, self::SNAPSHOT_TTL);
        });

        $this->allow($channel, $userId, $heartbeat * 3);
    }

    /**
     * Record the moment the call was answered — once. Returns true the first time.
     *
     * Both browsers heartbeat on the same cadence and often land together, and
     * a read-then-write would let both believe they were first: two
     * CallAnswered events, two sweep chains. add() is the cache's own
     * compare-and-set, so exactly one caller wins.
     */
    public function answered(string $id, ?int $at = null): bool
    {
        $at ??= now()->getTimestamp();

        if (! $this->cache->add("beam:answered:{$id}", $at, self::SNAPSHOT_TTL)) {
            return false;
        }

        $snapshot = $this->get($id);

        if ($snapshot === null) {
            return false;
        }

        $snapshot['answeredAt'] = $at;
        $this->cache->put("beam:call:{$id}", $snapshot, self::SNAPSHOT_TTL);

        return true;
    }

    /**
     * @return array<string, array{state: string, seenAt: int, joinedAt: int}>
     */
    public function participants(string $id): array
    {
        return $this->get($id)['participants'] ?? [];
    }

    /**
     * The participants heard from within 3 × heartbeat — who is in the room
     * now, as opposed to who has been in it during the snapshot's hour.
     *
     * @return array<string, array{state: string, seenAt: int, joinedAt: int}>
     */
    public function liveParticipants(string $id, int $heartbeat, ?int $now = null): array
    {
        $now ??= now()->getTimestamp();

        return array_filter(
            $this->participants($id),
            fn (array $p) => $now - (int) $p['seenAt'] <= $heartbeat * 3,
        );
    }

    /** Whether this user has heartbeated this call or room before. */
    public function hasSeen(string $id, int|string $userId): bool
    {
        return isset($this->participants($id)[(string) $userId]);
    }

    /** Seconds since this participant was first seen — a room participant's time in the room. */
    public function participantDuration(string $id, int|string $userId, ?int $now = null): ?int
    {
        $joinedAt = $this->participants($id)[(string) $userId]['joinedAt'] ?? null;

        return $joinedAt === null ? null : max(0, ($now ?? now()->getTimestamp()) - (int) $joinedAt);
    }

    /** Drop one participant from a snapshot (a room member leaving). */
    public function drop(string $id, int|string $userId): void
    {
        $this->atomically($id, function () use ($id, $userId) {
            $snapshot = $this->get($id);
            if ($snapshot === null) {
                return;
            }
            unset($snapshot['participants'][(string) $userId]);
            $this->cache->put("beam:call:{$id}", $snapshot, self::SNAPSHOT_TTL);
        });
    }

    /** A live call none of whose browsers has been heard from for 3 × heartbeat. */
    public function isStale(string $id, int $heartbeat, ?int $now = null): bool
    {
        $snapshot = $this->get($id);

        if ($snapshot === null || empty($snapshot['answeredAt'])) {
            return false;
        }

        $now ??= now()->getTimestamp();
        $latest = max(array_map(fn (array $p) => (int) $p['seenAt'], $snapshot['participants'] ?? []) ?: [(int) $snapshot['answeredAt']]);

        return $now - $latest > $heartbeat * 3;
    }

    /**
     * Every live call and room id — what the scheduler sweep walks.
     *
     * @return array<int, string>
     */
    public function ids(): array
    {
        $ids = $this->cache->get(self::INDEX, []);
        $ids = is_array($ids) ? array_values($ids) : [];
        $live = array_values(array_filter($ids, fn ($id) => $this->get((string) $id) !== null));

        // Written back, not just filtered: the index is kept forever, and an id
        // whose call nothing swept would otherwise sit in it for the life of the
        // cache — one more entry for every call the application ever placed.
        if (count($live) !== count($ids)) {
            $this->index(fn () => $live);
        }

        return $live;
    }

    /**
     * What a transfer is, so accept/decline/done can carry the file's name and
     * size to the application without the browser repeating them.
     *
     * @param  array<string, mixed>  $meta
     */
    public function rememberTransfer(string $transferId, array $meta): void
    {
        $this->cache->put("beam:transfer:{$transferId}", $meta, 86400);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function transfer(string $transferId): ?array
    {
        $meta = $this->cache->get("beam:transfer:{$transferId}");

        return is_array($meta) ? $meta : null;
    }

    public function forgetTransfer(string $transferId): void
    {
        $this->cache->forget("beam:transfer:{$transferId}");
    }

    private function index(callable $mutate): void
    {
        $this->atomically('index', function () use ($mutate) {
            $ids = $this->cache->get(self::INDEX, []);
            $this->cache->forever(self::INDEX, $mutate(is_array($ids) ? $ids : []));
        });
    }

    /**
     * Run a read-modify-write under the store's own lock where it has one —
     * redis, memcached, database and dynamodb do; file and array do not, and
     * there the call runs as before, which is what it always did.
     */
    private function atomically(string $id, callable $mutate): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            $mutate();

            return;
        }

        $lock = $store->lock("beam:lock:{$id}", 5);

        try {
            // Waiting briefly rather than skipping: a heartbeat that gave up
            // here would be a participant the snapshot forgot.
            $lock->block(2, $mutate);
        } catch (LockTimeoutException) {
            $mutate();
        }
    }

    private function key(string $channel, int|string $userId): string
    {
        return "beam:grant:{$channel}:{$userId}";
    }
}
