<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use RealRashid\Beam\Support\CallRegistry;

/**
 * The registry is a cache used as shared state, and the two browsers in a call
 * heartbeat on the same cadence — so the places that read, change and write
 * back have to survive arriving together.
 */
it('records the answer exactly once, however many heartbeats land together', function () {
    $registry = app(CallRegistry::class);
    $registry->put('call_1', ['id' => 'call_1', 'callee' => ['id' => 2, 'name' => 'C'], 'participants' => []], 60);

    $results = [$registry->answered('call_1', at: 1000), $registry->answered('call_1', at: 1001), $registry->answered('call_1', at: 1002)];

    expect($results)->toBe([true, false, false])
        ->and($registry->get('call_1')['answeredAt'])->toBe(1000);
});

it('lets a call be answered again once it has been forgotten and rung afresh', function () {
    $registry = app(CallRegistry::class);
    $registry->put('call_2', ['id' => 'call_2', 'callee' => ['id' => 2, 'name' => 'C']], 60);
    expect($registry->answered('call_2'))->toBeTrue();

    $registry->forget('call_2');
    $registry->put('call_2', ['id' => 'call_2', 'callee' => ['id' => 2, 'name' => 'C']], 60);

    expect($registry->answered('call_2'))->toBeTrue();   // the once-only flag went with the call
});

it('prunes the index when it reads it, so an id nothing swept does not live for ever', function () {
    $registry = app(CallRegistry::class);
    $registry->put('call_a', ['id' => 'call_a'], 60);
    $registry->put('call_b', ['id' => 'call_b'], 60);

    Cache::forget('beam:call:call_b');   // however it went: an expiry, a flush, a sweep that never ran

    expect($registry->ids())->toBe(['call_a'])
        ->and(Cache::get('beam:calls:index'))->toBe(['call_a']);
});

it('keeps both participants when two heartbeats arrive at once', function () {
    $registry = app(CallRegistry::class);
    $registry->put('call_3', ['id' => 'call_3', 'callee' => ['id' => 2, 'name' => 'C'], 'participants' => []], 60);

    $registry->touch('call_3', 'beam.call.call_3', 1, 'connected', 30, now: 1000);
    $registry->touch('call_3', 'beam.call.call_3', 2, 'connected', 30, now: 1000);

    expect($registry->participants('call_3'))->toHaveKeys(['1', '2'])
        ->and($registry->liveParticipants('call_3', 30, 1010))->toHaveCount(2)
        ->and($registry->liveParticipants('call_3', 30, 1200))->toBeEmpty();   // silent for longer than 3 × heartbeat
});
