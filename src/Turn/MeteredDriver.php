<?php

declare(strict_types=1);

namespace RealRashid\Beam\Turn;

use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Turn\Concerns\MintsOverHttp;

/**
 * Metered. Verified 19 Sep 2026: GET https://{app}.metered.live/api/v1/turn/credentials?apiKey=…
 * returns an array of servers. There is no TTL parameter — the credentials are
 * whatever Metered returns, so "per-participant, time-limited" does not apply
 * here the way it does to the other three; the docs say so.
 */
final class MeteredDriver implements TurnDriver
{
    use MintsOverHttp;

    public function __construct(
        private readonly ?string $app,
        private readonly ?string $apiKey,
        private readonly ?string $region = null,
    ) {}

    /**
     * @param  array{app?: string|null, api_key?: string|null, region?: string|null}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self($config['app'] ?? null, $config['api_key'] ?? null, $config['region'] ?? null);
    }

    public function credentialsFor(int|string $userId): array
    {
        if (! $this->app || ! $this->apiKey) {
            throw BeamException::invalidConfiguration('The metered TURN driver needs beam.ice.turn.drivers.metered.app (the {app}.metered.live subdomain) and .api_key.');
        }

        $query = ['apiKey' => $this->apiKey] + ($this->region ? ['region' => $this->region] : []);
        $response = $this->http()->get("https://{$this->app}.metered.live/api/v1/turn/credentials", $query);

        if (! $response->successful()) {
            throw $this->unavailable("HTTP {$response->status()}");
        }

        $servers = $this->turnEntries((array) $response->json());

        if ($servers === []) {
            throw $this->unavailable('the response carried no TURN credentials');
        }

        return $servers;
    }

    public function name(): string
    {
        return 'metered';
    }
}
