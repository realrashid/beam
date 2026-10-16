<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support;

use RealRashid\Beam\Call;

/**
 * The two things every timeout driver ends up doing, in one place: decline a
 * ring nobody answered, end a call nobody is in any more.
 */
final class CallSweeper
{
    public function __construct(private readonly CallRegistry $registry) {}

    /** Declines the call if it is still ringing. Returns true when it did. */
    public function expireIfRinging(string $id, ?int $now = null): bool
    {
        $snapshot = $this->registry->get($id);

        if ($snapshot === null || ! isset($snapshot['callee']) || ! empty($snapshot['answeredAt'])) {
            return false;
        }

        // Standing down for a callee who is mid-answer. `answeredAt` is written
        // when a browser first reports `connected`, which is seconds after the
        // tap and longer through a relay — so a call answered at second 29 of a
        // 30-second ring would otherwise be declined as a timeout while the two
        // browsers were negotiating. Their page is heartbeating; that is enough.
        if ($this->isAnswering($id, $now)) {
            return false;
        }

        Call::fromArray($snapshot)->decline('timeout');

        return true;
    }

    /** Whether the callee's browser has joined this ring but not yet connected. */
    public function isAnswering(string $id, ?int $now = null): bool
    {
        $snapshot = $this->registry->get($id);

        if ($snapshot === null || ! isset($snapshot['callee']) || ! empty($snapshot['answeredAt'])) {
            return false;
        }

        $live = $this->registry->liveParticipants($id, (int) config('beam.call.heartbeat', 30), $now);

        return array_key_exists((string) ($snapshot['callee']['id'] ?? ''), $live);
    }

    /** Ends the call if every browser in it has gone silent. Returns true when it did. */
    public function endIfStale(string $id, ?int $now = null): bool
    {
        $heartbeat = (int) config('beam.call.heartbeat', 30);
        $snapshot = $this->registry->get($id);

        if ($snapshot === null || ! isset($snapshot['callee']) || ! $this->registry->isStale($id, $heartbeat, $now)) {
            return false;
        }

        // The call ended when it was last heard from, not when the sweeper
        // noticed: staleness takes 3 × heartbeat to establish, and counting that
        // silence as talking time would put ninety seconds nobody spoke into
        // whatever the application bills or reports.
        $lastSeen = max(array_map(fn (array $p) => (int) $p['seenAt'], $snapshot['participants'] ?? []) ?: [(int) $snapshot['answeredAt']]);

        // 'connection-lost' is the same reason the browser gives when its own
        // recovery runs out, because it is the same thing happening.
        Call::fromArray([...$snapshot, 'endedAt' => $lastSeen])->end('connection-lost');

        return true;
    }

    /** One pass over every live call: what beam:sweep runs. Returns [declined, ended]. */
    public function sweep(?int $now = null): array
    {
        $now ??= now()->getTimestamp();
        $declined = 0;
        $ended = 0;

        foreach ($this->registry->ids() as $id) {
            $snapshot = $this->registry->get($id);
            if ($snapshot === null || ! isset($snapshot['callee'])) {
                continue;
            }

            if (empty($snapshot['answeredAt'])) {
                if ($now - (int) $snapshot['createdAt'] >= (int) $snapshot['timeout'] && $this->expireIfRinging($id)) {
                    $declined++;
                }
            } elseif ($this->endIfStale($id, $now)) {
                $ended++;
            }
        }

        return [$declined, $ended];
    }
}
