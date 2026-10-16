<?php

declare(strict_types=1);

namespace RealRashid\Beam\Turn;

use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Exceptions\BeamException;

/**
 * Self-hosted coturn with `use-auth-secret`: the standard TURN REST API
 * scheme. No HTTP call — the credential is an HMAC the relay can verify:
 *
 *   username   = "<expiry unix seconds>:<user id>"
 *   credential = base64(HMAC-SHA1(static-auth-secret, username))
 *
 * One shared secret, per-participant, time-limited, and nothing to mint
 * against. tests/Turn/CoturnDriverTest.php pins a vector computed with openssl.
 */
final class CoturnDriver implements TurnDriver
{
    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(
        private readonly array $urls,
        private readonly ?string $secret,
        private readonly int $ttl = 3600,
    ) {}

    /**
     * @param  array{urls?: array<int, string>|string, secret?: string|null, ttl?: int}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(array_values((array) ($config['urls'] ?? [])), $config['secret'] ?? null, (int) ($config['ttl'] ?? 3600));
    }

    public function credentialsFor(int|string $userId, ?int $now = null): array
    {
        if ($this->secret === null || $this->secret === '') {
            throw BeamException::invalidConfiguration('The coturn TURN driver needs beam.ice.turn.drivers.coturn.secret (the relay\'s static-auth-secret).');
        }

        if ($this->urls === []) {
            throw BeamException::invalidConfiguration('The coturn TURN driver needs at least one url in beam.ice.turn.drivers.coturn.urls (turn:host:3478, turns:host:5349).');
        }

        $username = (($now ?? now()->getTimestamp()) + $this->ttl).':'.$userId;
        $credential = base64_encode(hash_hmac('sha1', $username, $this->secret, true));

        return [['urls' => $this->urls, 'username' => $username, 'credential' => $credential]];
    }

    public function name(): string
    {
        return 'coturn';
    }
}
