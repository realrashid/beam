<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use RealRashid\Beam\BeamManager;
use RealRashid\Beam\PendingCall;

if (! function_exists('beam')) {
    /**
     * Resolve the Beam manager — or, given a user, start a call to them:
     * beam($customer)->video()->ring().
     */
    function beam(?Authenticatable $callee = null): BeamManager|PendingCall
    {
        $manager = app(BeamManager::class);

        return $callee === null ? $manager : $manager->call($callee);
    }
}
