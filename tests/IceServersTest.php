<?php

declare(strict_types=1);

use RealRashid\Beam\Data\IceServers;

it('defaults to Google STUN when nothing is configured', function () {
    $ice = IceServers::fromConfig([]);

    expect($ice->stun)->toBe(['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'])
        ->and($ice->hasTurn())->toBeFalse();
});

it('uses configured STUN and appends minted TURN credentials', function () {
    $ice = IceServers::fromConfig(['stun' => ['stun:mine:3478']], [['urls' => ['turn:relay:3478'], 'username' => 'u', 'credential' => 'c']]);

    expect($ice->stun)->toBe(['stun:mine:3478'])->and($ice->hasTurn())->toBeTrue();
});

it('produces the exact RTCConfiguration.iceServers shape', function () {
    $ice = IceServers::fromConfig(['stun' => ['stun:a', 'stun:b']], [['urls' => 'turn:r', 'username' => 'u', 'credential' => 'c']]);

    expect($ice->toArray())->toBe([
        ['urls' => ['stun:a', 'stun:b']],
        ['urls' => 'turn:r', 'username' => 'u', 'credential' => 'c'],
    ]);
});

it('round-trips through fromArray', function () {
    $ice = IceServers::fromConfig(['stun' => ['stun:a']], [['urls' => ['turn:r'], 'username' => 'u', 'credential' => 'c']]);
    $back = IceServers::fromArray($ice->toArray());

    expect($back->stun)->toBe(['stun:a'])->and($back->turn)->toBe($ice->turn);
});

it('ignores non-string STUN entries rather than passing junk to the browser', function () {
    expect(IceServers::fromConfig(['stun' => ['stun:a', null, 42]])->stun)->toBe(['stun:a']);
});
