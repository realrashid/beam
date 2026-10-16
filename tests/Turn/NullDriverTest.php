<?php

declare(strict_types=1);

use RealRashid\Beam\Data\IceServers;
use RealRashid\Beam\Turn\NullDriver;

it('mints nothing, so the browser gets STUN only', function () {
    $driver = new NullDriver;

    expect($driver->name())->toBe('none')
        ->and($driver->credentialsFor(1))->toBe([])
        ->and(IceServers::fromConfig([], $driver->credentialsFor(1))->hasTurn())->toBeFalse();
});
