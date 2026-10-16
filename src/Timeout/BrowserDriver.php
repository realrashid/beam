<?php

declare(strict_types=1);

namespace RealRashid\Beam\Timeout;

use RealRashid\Beam\Call;
use RealRashid\Beam\Contracts\TimeoutDriver;

/**
 * The caller's page reports the timeout (POST /beam/calls/{id}/timeout) and
 * either side's page reports a lost connection. Nothing runs server-side.
 * Dies with the tab: a call where both browsers crash never fires CallEnded.
 */
final class BrowserDriver implements TimeoutDriver
{
    public function schedule(Call $call): void
    {
        //
    }

    public function watch(Call $call): void
    {
        //
    }

    public function name(): string
    {
        return 'browser';
    }
}
