<?php

declare(strict_types=1);

namespace RealRashid\Beam\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use RealRashid\Beam\Call;
use RealRashid\Beam\PendingCall;

/**
 * What Beam::fake() hands back from call(). The gate still runs and the Call is
 * still assembled — a test that expects a FORBIDDEN still gets one — but
 * nothing is broadcast, nobody is notified and no timeout is scheduled.
 *
 * The fake overrides the behaviour; the real builder never learns that a test
 * is happening.
 */
final class FakePendingCall extends PendingCall
{
    public function __construct(private readonly BeamFake $fake, Authenticatable $callee)
    {
        parent::__construct($fake, $callee);
    }

    protected function dispatch(Call $call): Call
    {
        return $this->fake->recordRing($call);
    }
}
