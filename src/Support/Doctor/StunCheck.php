<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

/**
 * STUN is how a browser learns its public address. Without one, only calls
 * on the same network connect. Hostnames that do not resolve are as good as
 * none.
 */
final class StunCheck implements Check
{
    /** @var callable(string): bool */
    private $resolves;

    /**
     * @param  null|callable(string): bool  $resolves  injected for tests
     */
    public function __construct(?callable $resolves = null)
    {
        $this->resolves = $resolves ?? static fn (string $host): bool => filter_var($host, FILTER_VALIDATE_IP) !== false || gethostbyname($host) !== $host;
    }

    public function name(): string
    {
        return 'STUN';
    }

    public function run(): Result
    {
        $urls = array_values(array_filter((array) config('beam.ice.stun', []), 'is_string'));

        if ($urls === []) {
            return Result::warn('No STUN server is configured: browsers cannot learn their public address, so only same-network calls connect.', 'Add stun:stun.l.google.com:19302 to beam.ice.stun in config/beam.php.');
        }

        $bad = [];
        foreach ($urls as $url) {
            if (! preg_match('/^stuns?:(?:\/\/)?([^:?\/]+)/', $url, $m) || ! ($this->resolves)($m[1])) {
                $bad[] = $url;
            }
        }

        if ($bad !== []) {
            return Result::fail('These STUN entries are malformed or do not resolve: '.implode(', ', $bad), 'Use stun:host:port entries whose host resolves from this server.');
        }

        return Result::ok(count($urls).' STUN server'.(count($urls) === 1 ? '' : 's').' configured and resolving.');
    }
}
