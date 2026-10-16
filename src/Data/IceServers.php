<?php

declare(strict_types=1);

namespace RealRashid\Beam\Data;

/**
 * The STUN list plus whatever TURN credentials a driver minted — in the shape
 * RTCConfiguration.iceServers expects, so the browser passes it straight through.
 *
 * @phpstan-type IceServer array{urls: string|array<int, string>, username?: string, credential?: string}
 */
final class IceServers
{
    /**
     * @param  array<int, string>  $stun
     * @param  array<int, IceServer>  $turn
     */
    public function __construct(
        public readonly array $stun,
        public readonly array $turn = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `beam.ice` config block
     * @param  array<int, IceServer>  $turn  credentials from a TurnDriver
     */
    public static function fromConfig(array $config, array $turn = []): self
    {
        $stun = array_values(array_filter((array) ($config['stun'] ?? []), 'is_string'));

        if ($stun === []) {
            $stun = ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'];
        }

        return new self($stun, array_values($turn));
    }

    public function hasTurn(): bool
    {
        return $this->turn !== [];
    }

    /**
     * RTCConfiguration.iceServers: one entry with every STUN url, then the TURN entries as minted.
     *
     * @return array<int, IceServer>
     */
    public function toArray(): array
    {
        return [['urls' => $this->stun], ...$this->turn];
    }

    /**
     * From a decoded token's `ice` claim — untyped, so every key is checked.
     *
     * @param  array<int, array<string, mixed>>  $servers
     * @param  array<int, string>  $stunFallback
     */
    public static function fromArray(array $servers, array $stunFallback = []): self
    {
        $stun = [];
        $turn = [];

        foreach ($servers as $server) {
            $urls = (array) ($server['urls'] ?? []);
            $isTurn = isset($server['username']) || array_filter($urls, fn (string $u) => str_starts_with($u, 'turn'));

            if ($isTurn) {
                $turn[] = $server;
            } else {
                $stun = [...$stun, ...$urls];
            }
        }

        return new self($stun !== [] ? $stun : $stunFallback, $turn);
    }
}
