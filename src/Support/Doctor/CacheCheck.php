<?php

declare(strict_types=1);

namespace RealRashid\Beam\Support\Doctor;

use Illuminate\Contracts\Cache\Factory;
use Throwable;

/**
 * Every authorisation decision after the token is a cache read: who is in which
 * live call, which channel a browser may subscribe to, whether a relay may pass
 * a signal on. So the store has to keep what it is given, and every app server
 * has to see the same one.
 *
 * `array` keeps nothing past the request, which makes every join a 403 and no
 * error anywhere says why. `file` keeps it on one machine, which is a call that
 * works until a load balancer sends the callee to the other server.
 */
final class CacheCheck implements Check
{
    /** Stores that are local to one machine rather than shared between servers. */
    private const LOCAL = ['file', 'octane', 'apc'];

    private const FORGETFUL = ['array', 'null'];

    public function __construct(private readonly Factory $cache) {}

    public function name(): string
    {
        return 'Cache';
    }

    public function run(): Result
    {
        $name = (string) config('cache.default', 'file');

        if (in_array($name, self::FORGETFUL, true)) {
            return Result::fail(
                "The cache store is [{$name}], which keeps nothing between requests: Beam holds every live call and every participant there, so no browser could join a call.",
                'Set CACHE_STORE to redis, memcached, database or file in .env.',
            );
        }

        // Round-tripped through the store the config names, not through whatever
        // was resolved first: a Redis that is configured but not listening fails
        // here rather than on someone's first call.
        try {
            $store = $this->cache->store($name === '' ? null : $name);
            $key = 'beam:doctor:'.bin2hex(random_bytes(4));
            $store->put($key, 'ok', 5);
            $read = $store->get($key);
            $store->forget($key);
        } catch (Throwable $e) {
            return Result::fail(
                "The [{$name}] cache could not be written to: {$e->getMessage()}",
                'Beam cannot authorise a call without it. Check the store\'s server and credentials.',
            );
        }

        if ($read !== 'ok') {
            return Result::fail(
                "The [{$name}] cache did not return what Beam put in it.",
                'Check the store\'s configuration; live calls are held there.',
            );
        }

        if (in_array($name, self::LOCAL, true)) {
            return Result::warn(
                "Live calls are held in the [{$name}] cache, which is local to this machine: behind more than one app server, a caller and a callee that land on different ones never see each other as participants.",
                'On a single server this is fine. Behind a load balancer use redis, memcached or database.',
            );
        }

        return Result::ok("Live calls are held in the [{$name}] cache, shared across servers. Keep cache:clear out of your deploy: it ends every call in progress.");
    }
}
