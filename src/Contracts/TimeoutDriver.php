<?php

declare(strict_types=1);

namespace RealRashid\Beam\Contracts;

use RealRashid\Beam\Call;

/**
 * What runs later: after `timeout` seconds of ringing, and whenever a live
 * call's browsers have all gone silent. Cache expiry fires nothing, so one of
 * these has to.
 */
interface TimeoutDriver
{
    /** Arrange for the ring to expire. Called by ring(). */
    public function schedule(Call $call): void;

    /** Arrange for a silent call to be ended. Called when the callee answers. */
    public function watch(Call $call): void;

    public function name(): string;
}
