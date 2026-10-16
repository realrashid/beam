<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

use RealRashid\Beam\BeamManager;
use Throwable;

/**
 * No relay → roughly one call in five will not connect; say so. A relay →
 * mint a credential, then see whether every TURN host answers on its port.
 */
final class TurnCheck implements Check
{
    /** @var callable(string, int): bool */
    private $reachable;

    /**
     * @param  null|callable(string, int): bool  $reachable  injected for tests
     */
    public function __construct(private readonly BeamManager $beam, ?callable $reachable = null)
    {
        $this->reachable = $reachable ?? static function (string $host, int $port): bool {
            $socket = @fsockopen($host, $port, $errno, $errstr, 2.0);
            if ($socket === false) {
                return false;
            }
            fclose($socket);

            return true;
        };
    }

    public function name(): string
    {
        return 'TURN';
    }

    public function run(): Result
    {
        $name = (string) config('beam.ice.turn.default', 'none');

        if ($name === 'none') {
            return Result::warn('No TURN relay is configured: calls behind symmetric NAT and most mobile carriers — roughly one in five — will not connect.', 'Set BEAM_TURN to coturn, cloudflare, twilio or metered and fill in its keys. TURN costs bandwidth; docs/guide/turn.md.');
        }

        try {
            $servers = $this->beam->turn()->credentialsFor('doctor');
        } catch (Throwable $e) {
            return Result::fail("The {$name} TURN driver could not mint a credential: {$e->getMessage()}", 'Check the keys in config/beam.php and that the vendor account is active.');
        }

        $unreachable = [];
        foreach ($servers as $server) {
            foreach ((array) $server['urls'] as $url) {
                [$host, $port] = self::hostAndPort($url);
                if ($host !== null && ! ($this->reachable)($host, $port)) {
                    $unreachable[] = "{$host}:{$port}";
                }
            }
        }

        if ($unreachable !== []) {
            return Result::fail('Credentials mint, but these TURN hosts do not answer: '.implode(', ', array_unique($unreachable)), 'Check the relay is running and its ports are open to the internet (3478 UDP/TCP, 5349 TLS for coturn).');
        }

        return Result::ok("TURN via {$name}: credentials mint and every relay host answers.");
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    public static function hostAndPort(string $url): array
    {
        if (! preg_match('/^turns?:(?:\/\/)?([^:?\/]+)(?::(\d+))?/', $url, $m)) {
            return [null, 0];
        }

        return [$m[1], isset($m[2]) ? (int) $m[2] : (str_starts_with($url, 'turns') ? 5349 : 3478)];
    }
}
