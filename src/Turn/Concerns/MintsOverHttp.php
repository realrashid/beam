<?php

declare(strict_types=1);

namespace RealRashid\Beam\Turn\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RealRashid\Beam\Exceptions\BeamException;

/**
 * The vendor drivers share one posture: a short timeout, two retries (minting
 * is idempotent), and a failure that is reported rather than thrown as fatal —
 * ring() carries on with STUN and fires TurnUnavailable.
 */
trait MintsOverHttp
{
    protected function http(): PendingRequest
    {
        // Bounded on purpose: this runs inside ring(), so the worst case is what
        // the person who pressed the button waits. Three seconds, two retries,
        // and BeamManager keeps what comes back so most rings make no call at all.
        return Http::timeout(3)->connectTimeout(2)->retry(2, 150, throw: false)->acceptJson();
    }

    /**
     * Keep only entries that are actually TURN with credentials, in our shape.
     *
     * @param  array<int, mixed>  $servers
     * @return array<int, array{urls: array<int, string>, username: string, credential: string}>
     */
    protected function turnEntries(array $servers): array
    {
        $out = [];

        foreach ($servers as $server) {
            if (! is_array($server) || ! isset($server['username'], $server['credential'])) {
                continue;
            }

            $urls = array_values(array_filter((array) ($server['urls'] ?? $server['url'] ?? []), 'is_string'));

            if ($urls === []) {
                continue;
            }

            $out[] = ['urls' => $urls, 'username' => (string) $server['username'], 'credential' => (string) $server['credential']];
        }

        return $out;
    }

    protected function unavailable(string $detail): BeamException
    {
        return BeamException::turnUnavailable($this->name(), $detail);
    }

    abstract public function name(): string;
}
