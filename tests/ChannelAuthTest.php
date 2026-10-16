<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use RealRashid\Beam\Signaling\Channels;
use RealRashid\Beam\Support\CallRegistry;

beforeEach(function () {
    // The rig app registers these itself via install:broadcasting; tests do it here.
    Broadcast::routes(['middleware' => ['web']]);
});

it('authorises the user for their own beam.user channel and nobody else', function () {
    $alice = makeUser('Alice');
    $bob = makeUser('Bob');

    $this->actingAs($alice)->postJson('/broadcasting/auth', ['channel_name' => 'private-'.Channels::user($alice->id), 'socket_id' => '1.1'])->assertOk();
    $this->actingAs($alice)->postJson('/broadcasting/auth', ['channel_name' => 'private-'.Channels::user($bob->id), 'socket_id' => '1.1'])->assertForbidden();
});

it('authorises a room channel only for a registered participant', function () {
    $alice = makeUser('Alice');
    $bob = makeUser('Bob');
    app(CallRegistry::class)->allow(Channels::room('phase1'), $alice->id, 60);

    $this->actingAs($alice)->postJson('/broadcasting/auth', ['channel_name' => 'private-beam.room.phase1', 'socket_id' => '1.1'])->assertOk();
    $this->actingAs($bob)->postJson('/broadcasting/auth', ['channel_name' => 'private-beam.room.phase1', 'socket_id' => '1.1'])->assertForbidden();
});

it('authorises a call channel only for a registered participant, and stops after revoke', function () {
    $alice = makeUser('Alice');
    app(CallRegistry::class)->allow(Channels::call('call_1'), $alice->id, 60);

    $this->actingAs($alice)->postJson('/broadcasting/auth', ['channel_name' => 'private-beam.call.call_1', 'socket_id' => '1.1'])->assertOk();

    app(CallRegistry::class)->revoke(Channels::call('call_1'), $alice->id);
    $this->actingAs($alice)->postJson('/broadcasting/auth', ['channel_name' => 'private-beam.call.call_1', 'socket_id' => '1.1'])->assertForbidden();
});

it('refuses every beam channel to a guest', function () {
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-beam.room.phase1', 'socket_id' => '1.1'])->assertStatus(403);
});
