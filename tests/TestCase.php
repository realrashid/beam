<?php

declare(strict_types=1);

namespace RealRashid\Beam\Tests;

use Illuminate\Broadcasting\BroadcastManager;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\TestCase as Orchestra;
use RealRashid\Beam\BeamServiceProvider;
use RealRashid\Beam\Facades\Beam;

class TestCase extends Orchestra
{
    use WithLaravelMigrations;

    protected function getPackageProviders($app): array
    {
        return [BeamServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Beam' => Beam::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        // Not 'sync': the queue timeout driver guards against it. Tests that exercise the guard set it explicitly.
        $app['config']->set('queue.default', 'null');
        $app['config']->set('queue.connections.null', ['driver' => 'null']);
        // A broadcaster whose auth() really verifies channels; see Fixtures\TestBroadcaster.
        $app['config']->set('broadcasting.default', 'test');
        $app['config']->set('broadcasting.connections.test', ['driver' => 'test']);
        $app->afterResolving(BroadcastManager::class, fn (BroadcastManager $manager) => $manager->extend('test', fn () => new Fixtures\TestBroadcaster));
        $app['config']->set('auth.providers.users.model', Fixtures\User::class);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
