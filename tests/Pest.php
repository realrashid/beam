<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use RealRashid\Beam\Data\IceServers;
use RealRashid\Beam\Data\Token;
use RealRashid\Beam\Support\CallRegistry;
use RealRashid\Beam\Tests\Fixtures\User;
use RealRashid\Beam\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function makeUser(string $name = 'Ada'): User
{
    return User::forceCreate([
        'name' => $name,
        'email' => strtolower($name).'-'.uniqid().'@example.test',
        'password' => bcrypt('secret'),
    ]);
}

/** A signed token for a channel, and the registry grant that makes the user a participant. */
function tokenFor(User $user, string $channel, int $ttl = 3600, bool $grant = true): string
{
    if ($grant) {
        app(CallRegistry::class)->allow($channel, $user->getAuthIdentifier(), $ttl);
    }

    return (new Token(
        callId: str_replace(['beam.room.', 'beam.call.'], '', $channel),
        channel: $channel,
        role: str_starts_with($channel, 'beam.room.') ? 'participant' : 'caller',
        userId: $user->getAuthIdentifier(),
        ice: IceServers::fromConfig((array) config('beam.ice')),
        expiresAt: time() + $ttl,
    ))->sign(app(Encrypter::class));
}

/** The packed token as the browser would decode it. */
function unpackToken(string $packed): array
{
    return json_decode(base64_decode(strtr($packed, '-_', '+/')), true);
}
