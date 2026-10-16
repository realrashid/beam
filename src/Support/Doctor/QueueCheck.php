<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

/**
 * The queue timeout driver delays an ExpireCall job; on the sync queue that
 * job would run inside ring() itself, so Beam falls back to the browser
 * driver and says so. Better to know before the first unanswered call.
 */
final class QueueCheck implements Check
{
    public function name(): string
    {
        return 'Timeouts';
    }

    public function run(): Result
    {
        $driver = (string) config('beam.call.timeout.driver', 'queue');

        if ($driver !== 'queue') {
            return Result::ok("Unanswered calls time out through the {$driver} driver.");
        }

        $connection = (string) config('queue.default', 'sync');

        if ($connection === 'sync') {
            return Result::warn('The timeout driver is queue but QUEUE_CONNECTION=sync: rings fall back to the browser driver, which only fires while the caller keeps the page open.', 'Run a worker (php artisan queue:work) with a real connection, or set BEAM_TIMEOUT=browser or scheduler.');
        }

        return Result::ok("Unanswered calls time out through the {$connection} queue; keep a worker running.");
    }
}
