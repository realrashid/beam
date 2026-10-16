<?php

declare(strict_types=1);

namespace RealRashid\Beam\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPUnit\Framework\Assert;
use RealRashid\Beam\BeamManager;
use RealRashid\Beam\Call;
use RealRashid\Beam\PendingCall;
use RealRashid\Beam\PendingRoom;
use RealRashid\Beam\Room;

/**
 * Beam::fake(); … Beam::assertRang($customer)->from($agent)->withVideo();
 *
 * Records instead of broadcasting. Gates still run — a test that expects a
 * FORBIDDEN still gets one.
 */
final class BeamFake extends BeamManager
{
    /** @var array<int, Call> */
    public array $calls = [];

    /** @var array<int, Room> */
    public array $rooms = [];

    /** Beam::fake()->call($user) — the builder that records instead of ringing. */
    public function call(Authenticatable $callee): PendingCall
    {
        return new FakePendingCall($this, $callee);
    }

    public function room(string $name): PendingRoom
    {
        return new FakePendingRoom($this, $name);
    }

    public function recordRing(Call $call): Call
    {
        $this->calls[] = $call;

        return $call;
    }

    public function recordRoom(Room $room): Room
    {
        $this->rooms[] = $room;

        return $room;
    }

    public function assertRang(Authenticatable|int|string $callee): PendingRingAssertion
    {
        $matches = array_values(array_filter($this->calls, fn (Call $c) => $c->callee->is($callee)));

        Assert::assertNotEmpty($matches, 'Expected a call to user ['.self::id($callee).'], but none was rung.');

        return new PendingRingAssertion($matches);
    }

    public function assertNotRang(Authenticatable|int|string $callee): void
    {
        $matches = array_filter($this->calls, fn (Call $c) => $c->callee->is($callee));

        Assert::assertEmpty($matches, 'Expected no call to user ['.self::id($callee).'], but one was rung.');
    }

    public function assertNothingRang(): void
    {
        Assert::assertEmpty($this->calls, 'Expected no calls, but '.count($this->calls).' were rung.');
    }

    public function assertRoomCreated(string $name): void
    {
        Assert::assertNotEmpty(array_filter($this->rooms, fn (Room $r) => $r->name === $name), "Expected room [{$name}] to be created, but it was not.");
    }

    private static function id(Authenticatable|int|string $user): string
    {
        return (string) ($user instanceof Authenticatable ? $user->getAuthIdentifier() : $user);
    }
}
