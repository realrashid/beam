<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use RealRashid\Beam\Contracts\PushStore;
use RealRashid\Beam\Exceptions\BeamException;
use RealRashid\Beam\Push\Subscriptions;

$sub = fn (string $endpoint = 'https://push.test/abc') => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'P', 'auth' => 'A'], 'user_agent' => 'UA'];

it('adds, lists, replaces by endpoint, removes and forgets', function (string $store) use ($sub) {
    config()->set('beam.push.store', $store);
    $s = app(Subscriptions::class)->store();
    expect($s->name())->toBe($store);

    $s->add(1, $sub('https://push.test/one'));
    $s->add(1, $sub('https://push.test/two'));
    $s->add(1, ['endpoint' => 'https://push.test/one', 'keys' => ['p256dh' => 'P2', 'auth' => 'A2']]);   // same endpoint: replaced, not duplicated
    $s->add(2, $sub('https://push.test/other-user'));

    $mine = $s->for(1);
    expect($mine)->toHaveCount(2)
        ->and(collect($mine)->firstWhere('endpoint', 'https://push.test/one')['keys']['p256dh'])->toBe('P2')
        ->and($s->for(2))->toHaveCount(1)
        ->and($s->for(3))->toBe([]);

    $s->remove(1, 'https://push.test/one');
    expect($s->for(1))->toHaveCount(1);

    $s->forget(1);
    expect($s->for(1))->toBe([])->and($s->for(2))->toHaveCount(1);
})->with(['database', 'cache']);

it('database: survives a cache flush; cache: does not — which is why database is the default', function () use ($sub) {
    config()->set('beam.push.store', 'database');
    app(Subscriptions::class)->store()->add(1, $sub());
    config()->set('beam.push.store', 'cache');
    app(Subscriptions::class)->store()->add(2, $sub());

    Cache::flush();

    config()->set('beam.push.store', 'database');
    expect(app(Subscriptions::class)->store()->for(1))->toHaveCount(1);
    config()->set('beam.push.store', 'cache');
    expect(app(Subscriptions::class)->store()->for(2))->toBe([]);
});

it('refuses an unknown store, naming the valid ones', function () {
    config()->set('beam.push.store', 'pigeon');

    expect(fn () => app(Subscriptions::class)->store())->toThrow(fn (BeamException $e) => expect($e->getMessage())->toContain('database, cache'));
});

it('lets an application register its own store', function () {
    $custom = new class implements PushStore
    {
        public array $data = [];

        public function add(int|string $userId, array $subscription): void
        {
            $this->data[$userId][] = $subscription;
        }

        public function remove(int|string $userId, string $endpoint): void {}

        public function for(int|string $userId): array
        {
            return $this->data[$userId] ?? [];
        }

        public function forget(int|string $userId): void {}

        public function name(): string
        {
            return 'mine';
        }
    };
    app()->instance($custom::class, $custom);
    app(Subscriptions::class)->extend('mine', $custom::class);
    config()->set('beam.push.store', 'mine');

    app(Subscriptions::class)->store()->add(1, ['endpoint' => 'e', 'keys' => ['p256dh' => 'p', 'auth' => 'a']]);
    expect(app(Subscriptions::class)->store()->for(1))->toHaveCount(1);
});
