<?php

declare(strict_types=1);

namespace RealRashid\Beam\Facades;

use Illuminate\Support\Facades\Facade;
use RealRashid\Beam\BeamManager;

/**
 * Beam::call($user)->video()->ring()
 *
 * The API is four calls: call(), room(), find() (or findOrFail()) and, in a
 * test, fake(). Everything after that is on what they return.
 *
 * @method static \RealRashid\Beam\PendingCall call(\Illuminate\Contracts\Auth\Authenticatable $callee)
 * @method static \RealRashid\Beam\PendingRoom room(string $name)
 * @method static \RealRashid\Beam\Call|null find(string $id)
 * @method static \RealRashid\Beam\Call findOrFail(string $id)
 * @method static \RealRashid\Beam\Testing\BeamFake fake()
 * @method static \RealRashid\Beam\BeamManager extendTurn(string $name, \Closure|string $driver)
 * @method static \RealRashid\Beam\BeamManager extendTimeout(string $name, \Closure|string $driver)
 *
 * After Beam::fake():
 * @method static \RealRashid\Beam\Testing\PendingRingAssertion assertRang(\Illuminate\Contracts\Auth\Authenticatable|int|string $callee)
 * @method static void assertNotRang(\Illuminate\Contracts\Auth\Authenticatable|int|string $callee)
 * @method static void assertNothingRang()
 * @method static void assertRoomCreated(string $name)
 *
 * Beam's own plumbing, resolved through the contracts instead where you can:
 * @method static \RealRashid\Beam\Contracts\TurnDriver turn()
 * @method static \RealRashid\Beam\Contracts\TimeoutDriver timeouts()
 * @method static \RealRashid\Beam\Data\IceServers ice(int|string $userId)
 * @method static \RealRashid\Beam\Support\CallRegistry registry()
 * @method static \RealRashid\Beam\Support\CallSweeper sweeper()
 * @method static \RealRashid\Beam\Support\Gate gate()
 *
 * @see BeamManager
 */
class Beam extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BeamManager::class;
    }
}
