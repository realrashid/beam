<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate as LaravelGate;
use RealRashid\Beam\Room;
use RealRashid\Beam\Support\Gate;

it('lets any authenticated user call any other when no gate is defined', function () {
    expect(app(Gate::class)->hasCallGate())->toBeFalse()
        ->and(app(Gate::class)->mayCall(makeUser('A'), makeUser('B')))->toBeTrue();
});

it('defers to beam.call when it is defined, passing caller and callee', function () {
    $agent = makeUser('Agent');
    $customer = makeUser('Customer');
    $seen = [];
    LaravelGate::define('beam.call', function ($caller, $callee) use (&$seen) {
        $seen = [$caller->name, $callee->name];

        return $caller->name === 'Agent';
    });

    expect(app(Gate::class)->hasCallGate())->toBeTrue()
        ->and(app(Gate::class)->mayCall($agent, $customer))->toBeTrue()
        ->and($seen)->toBe(['Agent', 'Customer'])
        ->and(app(Gate::class)->mayCall($customer, $agent))->toBeFalse();
});

it("uses the room's allow-list when no join gate is defined", function () {
    $alice = makeUser('Alice');
    $bob = makeUser('Bob');
    $room = new Room('consult', allowed: [$alice->id]);

    expect(app(Gate::class)->mayJoin($alice, $room))->toBeTrue()
        ->and(app(Gate::class)->mayJoin($bob, $room))->toBeFalse()
        ->and(app(Gate::class)->mayJoin($bob, new Room('open')))->toBeTrue();
});

it('defers to beam.join when it is defined, even over the allow-list', function () {
    $alice = makeUser('Alice');
    LaravelGate::define('beam.join', fn ($user, Room $room) => $room->name === 'vip');

    expect(app(Gate::class)->mayJoin($alice, new Room('vip')))->toBeTrue()
        ->and(app(Gate::class)->mayJoin($alice, new Room('consult', allowed: [$alice->id])))->toBeFalse();
});
