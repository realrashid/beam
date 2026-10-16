<?php

declare(strict_types=1);

namespace RealRashid\Beam;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\Macroable;
use RealRashid\Beam\Contracts\TimeoutDriver;
use RealRashid\Beam\Contracts\TurnDriver;
use RealRashid\Beam\Data\IceServers;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Facades\Beam;
use RealRashid\Beam\Support\CallRegistry;
use RealRashid\Beam\Support\CallSweeper;
use RealRashid\Beam\Support\Gate;
use RealRashid\Beam\Testing\BeamFake;
use RealRashid\Beam\Timeout\BrowserDriver;
use RealRashid\Beam\Timeout\QueueDriver;
use RealRashid\Beam\Timeout\SchedulerDriver;
use RealRashid\Beam\Turn\CloudflareDriver;
use RealRashid\Beam\Turn\Concerns\MintsOverHttp;
use RealRashid\Beam\Turn\CoturnDriver;
use RealRashid\Beam\Turn\MeteredDriver;
use RealRashid\Beam\Turn\NullDriver;
use RealRashid\Beam\Turn\TwilioDriver;

/**
 * The entry point behind the Beam facade and the beam() helper.
 *
 * call(), room(), find(), fake(), turn(), ice().
 *
 * @author Rashid Ali <realrashid05@gmail.com>
 */
class BeamManager
{
    use Macroable;

    /** @var array<string, Closure|class-string<TurnDriver>> */
    protected array $turnDrivers = [
        'none' => NullDriver::class,
        'coturn' => CoturnDriver::class,
        'cloudflare' => CloudflareDriver::class,
        'twilio' => TwilioDriver::class,
        'metered' => MeteredDriver::class,
    ];

    public function __construct(
        protected Container $container,
        protected Repository $config,
    ) {}

    /** Start describing a call to one person. Nothing happens until ->ring(). */
    public function call(Authenticatable $callee): PendingCall
    {
        return new PendingCall($this, $callee);
    }

    /** Describe a room to create, or take hold of an existing one by name. */
    public function room(string $name): PendingRoom
    {
        return new PendingRoom($this, $name);
    }

    /** A live call by id, or null — as find() means everywhere else in Laravel. */
    public function find(string $id): ?Call
    {
        $snapshot = $this->registry()->get($id);

        return $snapshot === null ? null : Call::fromArray($snapshot);
    }

    /** The same, for code that would rather have the exception than the null. */
    public function findOrFail(string $id): Call
    {
        return $this->find($id) ?? throw BeamException::notFound($id);
    }

    /** Swap in a manager that records instead of broadcasting. */
    public function fake(): BeamFake
    {
        $fake = new BeamFake($this->container, $this->config);
        $this->container->instance(BeamManager::class, $fake);
        Beam::clearResolvedInstance(BeamManager::class);

        return $fake;
    }

    /** @var array<string, Closure|class-string<TimeoutDriver>> */
    protected array $timeoutDrivers = [
        'queue' => QueueDriver::class,
        'browser' => BrowserDriver::class,
        'scheduler' => SchedulerDriver::class,
    ];

    /**
     * The configured timeout driver — with the sync guard: on the sync queue a
     * delayed job runs inline and would decline the call the instant it rang,
     * so `queue` falls back to `browser` and says so.
     *
     * @internal Beam's own plumbing: call(), room(), find(), findOrFail() and fake() are the API.
     */
    public function timeouts(): TimeoutDriver
    {
        $name = (string) $this->config->get('beam.call.timeout.driver', 'queue');

        if (! isset($this->timeoutDrivers[$name])) {
            throw BeamException::invalidConfiguration(
                "Unknown timeout driver [{$name}]. Set beam.call.timeout.driver to one of: ".implode(', ', array_keys($this->timeoutDrivers)).'.'
            );
        }

        if ($name === 'queue' && $this->config->get('queue.default') === 'sync') {
            Log::warning('Beam: call.timeout.driver is queue but the queue is sync, so rings fall back to the browser driver. Run a queue worker, or choose the browser or scheduler driver. `php artisan beam:doctor` explains.');

            return $this->container->make(BrowserDriver::class);
        }

        /** @var TimeoutDriver */
        return $this->resolveDriver($this->timeoutDrivers[$name], (array) $this->config->get("beam.call.timeout.{$name}", []));
    }

    /**
     * Register a timeout driver: a closure receiving the container and this
     * driver's config block, as Storage::extend() and Cache::extend() take.
     * A class string still works for a driver with a no-argument constructor
     * or a static fromConfig().
     */
    public function extendTimeout(string $name, Closure|string $driver): static
    {
        $this->timeoutDrivers[$name] = $driver;

        return $this;
    }

    /**
     * What a timeout driver calls to decline or end a call.
     *
     * @internal Beam's own plumbing: call(), room(), find(), findOrFail() and fake() are the API.
     */
    public function sweeper(): CallSweeper
    {
        return $this->container->make(CallSweeper::class);
    }

    /**
     * Who is in which live call — the cache-held registry.
     *
     * @internal Beam's own plumbing: call(), room(), find(), findOrFail() and fake() are the API.
     */
    public function registry(): CallRegistry
    {
        return $this->container->make(CallRegistry::class);
    }

    /**
     * The wrapper around the application's two Beam abilities.
     *
     * @internal Beam's own plumbing: call(), room(), find(), findOrFail() and fake() are the API.
     */
    public function gate(): Gate
    {
        return $this->container->make(Gate::class);
    }

    /**
     * The configured TURN driver.
     *
     * @internal Beam's own plumbing: call(), room(), find(), findOrFail() and fake() are the API.
     */
    public function turn(): TurnDriver
    {
        $name = (string) $this->config->get('beam.ice.turn.default', 'none');

        if (! isset($this->turnDrivers[$name])) {
            throw BeamException::invalidConfiguration(
                "Unknown TURN driver [{$name}]. Set beam.ice.turn.default to one of: ".implode(', ', array_keys($this->turnDrivers)).'.'
            );
        }

        /** @var TurnDriver */
        return $this->resolveDriver($this->turnDrivers[$name], (array) $this->config->get("beam.ice.turn.drivers.{$name}", []));
    }

    /**
     * Register a TURN driver of your own:
     *
     *   Beam::extendTurn('ourvendor', fn ($app, array $config) => new OurDriver(
     *       $app->make(OurApiClient::class), $config['pool'] ?? null,
     *   ));
     *
     * The closure receives the container and beam.ice.turn.drivers.{name}, the
     * way Storage::extend() and Cache::extend() do. A class string still works
     * for a driver with a no-argument constructor or a static fromConfig().
     */
    public function extendTurn(string $name, Closure|string $driver): static
    {
        $this->turnDrivers[$name] = $driver;

        return $this;
    }

    /**
     * A driver from a closure, a fromConfig() or the container — in that order.
     *
     * @param  Closure|class-string  $driver
     * @param  array<string, mixed>  $config
     */
    protected function resolveDriver(Closure|string $driver, array $config): object
    {
        if ($driver instanceof Closure) {
            return $driver($this->container, $config);
        }

        return method_exists($driver, 'fromConfig')
            ? $driver::fromConfig($config)
            : $this->container->make($driver);
    }

    /**
     * STUN from config plus TURN credentials minted for one participant.
     *
     * @internal Beam's own plumbing: call(), room(), find(), findOrFail() and fake() are the API.
     */
    public function ice(int|string $userId): IceServers
    {
        return IceServers::fromConfig(
            (array) $this->config->get('beam.ice', []),
            $this->credentialsFor($userId),
        );
    }

    /**
     * A participant's TURN credentials. Every ring mints two — one per side —
     * and a vendor mints them over HTTP, so they are kept for a quarter of
     * their life: most rings then make no vendor call at all, and any call that
     * starts with a cached credential still has three quarters of the relay's
     * window in front of it. The vendor's own config goes into the key, so
     * rotating an API token does not serve credentials minted with the old one.
     *
     * Never cached for coturn, whose credential is an HMAC computed here, and
     * off entirely with beam.ice.turn.cache => false.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function credentialsFor(int|string $userId): array
    {
        $driver = $this->turn();

        if (! in_array(MintsOverHttp::class, class_uses_recursive($driver), true) || ! $this->config->get('beam.ice.turn.cache', true)) {
            return $driver->credentialsFor($userId);
        }

        $vendor = (array) $this->config->get("beam.ice.turn.drivers.{$driver->name()}", []);
        $keep = max(60, intdiv((int) ($vendor['ttl'] ?? 1800), 4));
        $key = "beam:turn:{$driver->name()}:".substr(md5((string) json_encode($vendor)), 0, 8).":{$userId}";

        // A vendor that fails is not remembered: remember() only stores what the
        // closure returns, and a failure throws through to ring()'s fallback.
        return $this->container->make(Cache::class)->remember($key, $keep, fn () => $driver->credentialsFor($userId));
    }
}
