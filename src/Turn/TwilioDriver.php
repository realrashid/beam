<?php

declare(strict_types=1);

namespace RealRashid\Beam\Turn;

use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Turn\Concerns\MintsOverHttp;

/**
 * Twilio Network Traversal Service. Verified 19 Sep 2026: still offered, no
 * deprecation. POST /2010-04-01/Accounts/{sid}/Tokens.json, basic auth,
 * form field Ttl (max 86400) → ice_servers[] with urls (older payloads: url).
 */
final class TwilioDriver implements TurnDriver
{
    use MintsOverHttp;

    public const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Tokens.json';

    public function __construct(
        private readonly ?string $sid,
        private readonly ?string $token,
        private readonly int $ttl = 3600,
    ) {}

    /**
     * @param  array{sid?: string|null, token?: string|null, ttl?: int}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self($config['sid'] ?? null, $config['token'] ?? null, min(86400, (int) ($config['ttl'] ?? 3600)));
    }

    public function credentialsFor(int|string $userId): array
    {
        if (! $this->sid || ! $this->token) {
            throw BeamException::invalidConfiguration('The twilio TURN driver needs beam.ice.turn.drivers.twilio.sid and .token.');
        }

        $response = $this->http()->withBasicAuth($this->sid, $this->token)->asForm()->post(sprintf(self::ENDPOINT, $this->sid), ['Ttl' => $this->ttl]);

        if (! $response->successful()) {
            throw $this->unavailable("HTTP {$response->status()}");
        }

        $servers = $this->turnEntries((array) $response->json('ice_servers', []));

        if ($servers === []) {
            throw $this->unavailable('the response carried no TURN credentials');
        }

        return $servers;
    }

    public function name(): string
    {
        return 'twilio';
    }
}
