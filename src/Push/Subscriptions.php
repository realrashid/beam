<?php

declare(strict_types=1);

namespace RealRashid\Beam\Push;

use Illuminate\Contracts\Container\Container;
use RealRashid\Beam\Contracts\PushStore;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Push\Stores\CacheStore;
use RealRashid\Beam\Push\Stores\DatabaseStore;

/** Resolves the configured store. */
final class Subscriptions
{
    /** @var array<string, class-string<PushStore>> */
    private array $stores = ['database' => DatabaseStore::class, 'cache' => CacheStore::class];

    public function __construct(private readonly Container $container) {}

    public function store(): PushStore
    {
        $name = (string) config('beam.push.store', 'database');

        if (! isset($this->stores[$name])) {
            throw BeamException::invalidConfiguration("Unknown push store [{$name}]. Set beam.push.store to one of: ".implode(', ', array_keys($this->stores)).'.');
        }

        return $this->container->make($this->stores[$name]);
    }

    public function extend(string $name, string $storeClass): static
    {
        $this->stores[$name] = $storeClass;

        return $this;
    }
}
