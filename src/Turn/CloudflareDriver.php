<?php

declare(strict_types=1);

namespace RealRashid\Beam\Turn;

use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Turn\Concerns\MintsOverHttp;

/**
 * Cloudflare Realtime TURN. Verified against the docs, 19 Sep 2026:
 *   POST https://rtc.live.cloudflare.com/v1/turn/keys/{key_id}/credentials/generate-ice-servers
 *   Authorization: Bearer {token}   body {"ttl": seconds}   → 201 {"iceServers": [...]}
 * The TURN key is created in the dashboard; there is no API for that.
 */
final class CloudflareDriver implements TurnDriver
{
    use MintsOverHttp;

    public const ENDPOINT = 'https://rtc.live.cloudflare.com/v1/turn/keys/%s/credentials/generate-ice-servers';

    public function __construct(
        private readonly ?string $keyId,
        private readonly ?string $token,
        private readonly int $ttl = 3600,
    ) {}

    /**
     * @param  array{key_id?: string|null, token?: string|null, ttl?: int}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self($config['key_id'] ?? null, $config['token'] ?? null, (int) ($config['ttl'] ?? 3600));
    }

    public function credentialsFor(int|string $userId): array
    {
        if (! $this->keyId || ! $this->token) {
            throw BeamException::invalidConfiguration('The cloudflare TURN driver needs beam.ice.turn.drivers.cloudflare.key_id and .token (a TURN key from the Cloudflare dashboard).');
        }

        $response = $this->http()->withToken($this->token)->post(sprintf(self::ENDPOINT, $this->keyId), ['ttl' => $this->ttl]);

        if (! $response->successful()) {
            throw $this->unavailable("HTTP {$response->status()}");
        }

        $servers = $this->turnEntries((array) $response->json('iceServers', []));

        if ($servers === []) {
            throw $this->unavailable('the response carried no TURN credentials');
        }

        return $servers;
    }

    public function name(): string
    {
        return 'cloudflare';
    }
}
