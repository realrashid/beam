<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

/**
 * Every signal rides on Laravel Broadcasting; a log or null driver means no
 * browser ever hears an offer. Reverb is checked for a listening socket.
 */
final class BroadcastingCheck implements Check
{
    /** @var callable(string, int): bool */
    private $reachable;

    /**
     * @param  null|callable(string, int): bool  $reachable  injected for tests
     */
    public function __construct(?callable $reachable = null)
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
        return 'Broadcasting';
    }

    public function run(): Result
    {
        $driver = (string) config('broadcasting.default', 'null');

        if (in_array($driver, ['', 'log', 'null'], true)) {
            return Result::fail("Broadcasting is [{$driver}]: offers and answers never reach a browser, so no call can connect.", 'Run php artisan install:broadcasting (Reverb), or configure Pusher or Ably; Beam signals over whatever Broadcasting uses.');
        }

        if ($driver === 'reverb') {
            $host = (string) config('broadcasting.connections.reverb.options.host', '');
            $port = (int) config('broadcasting.connections.reverb.options.port', 0);

            if ($host === '' || $port === 0) {
                return Result::fail('Broadcasting is reverb but REVERB_HOST or REVERB_PORT is empty.', 'Set REVERB_HOST and REVERB_PORT in .env (install:broadcasting writes them).');
            }

            if (! ($this->reachable)($host, $port)) {
                return Result::fail("Reverb does not answer at {$host}:{$port}.", 'Start it with php artisan reverb:start, or point REVERB_HOST/REVERB_PORT at the running server.');
            }

            return Result::ok("Broadcasting via Reverb at {$host}:{$port}, which answers.");
        }

        return Result::ok("Broadcasting via {$driver}.");
    }
}
